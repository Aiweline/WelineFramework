<?php
declare(strict_types=1);

namespace Weline\I18n\Queue;

use Weline\Framework\Async\TaskConsumerInterface;
use Weline\Framework\Async\TaskContextInterface;
use Weline\I18n\Service\AiTranslationConfig;
use Weline\I18n\Service\AiTranslationQueueService;
use Weline\I18n\Service\AiTranslationService;

class AiTranslateQueue implements TaskConsumerInterface
{
    public function __construct(
        private readonly AiTranslationConfig $config,
        private readonly AiTranslationService $translationService,
        private readonly AiTranslationQueueService $queueService
    ) {
    }

    public function name(): string
    {
        return 'I18n AI翻译队列';
    }

    public function attributes(): array
    {
        return [];
    }

    public function tip(): string
    {
        return '按语言批量执行 I18n AI 自动翻译';
    }

    public function validate(TaskContextInterface $queue): bool
    {
        $content = $this->decodeContent($queue);
        $localeCode = (string)($content['locale_code'] ?? '');

        if ($localeCode === '') {
            $queue->setResult((string)__('验证失败：缺少 locale_code。'));
            return false;
        }

        if (!$this->isValidTargetLocale($localeCode, $content)) {
            $queue->setResult((string)__('验证失败：语言 %{1} 未安装启用或为源语言。', [$localeCode]));
            return false;
        }

        if (
            !$this->isScopedDomainQueue($content)
            && !$this->isManualQueue($content)
            && !$this->config->isLocaleEnabled($localeCode)
        ) {
            $queue->setResult((string)__('验证失败：语言 %{1} 未开启 AI 自动翻译，自动队列已跳过。', [$localeCode]));
            return false;
        }

        $batchSize = $this->resolveBatchSize($content, $localeCode);
        if ($batchSize <= 0 || $batchSize > AiTranslationConfig::MAX_BATCH_SIZE) {
            $queue->setResult((string)__('验证失败：batch_size 必须在 1-%{1} 之间。', [AiTranslationConfig::MAX_BATCH_SIZE]));
            return false;
        }

        return true;
    }

    public function execute(TaskContextInterface $queue): string
    {
        $content = $this->decodeContent($queue);
        $localeCode = (string)$content['locale_code'];
        $sourceLocale = (string)($content['source_locale'] ?? $this->config->getSourceLocale());
        $batchSize = $this->resolveBatchSize($content, $localeCode);
        $strategy = $this->normalizeStrategy((string)($content['strategy'] ?? $this->config->getStrategy($localeCode)));
        $publish = (bool)($content['publish'] ?? $this->config->shouldAutoPublish());
        $scope = $this->buildBatchScope($content, $queue);

        $result = $this->translationService->batchTranslateDictionary(
            $localeCode,
            $sourceLocale,
            $batchSize,
            $strategy,
            $publish,
            $scope,
        );

        $consecutiveFailures = $this->advanceConsecutiveFailures($content, $result);
        $maxFailures = AiTranslationConfig::MAX_CONSECUTIVE_BATCH_FAILURES;
        $halted = $consecutiveFailures >= $maxFailures;
        $batchFailed = $this->isBatchFailure($result);
        $busy = $this->resultIndicatesBusy($result);
        // Busy or batch failure: stop this round; next cron continues when free.
        $stopRound = $halted || $busy || $batchFailed;

        $nextQueueId = 0;
        if (
            !$stopRound
            && (int)($result['remaining'] ?? 0) > 0
            && $this->shouldContinue($localeCode, $content)
        ) {
            $content['consecutive_failures'] = $consecutiveFailures;
            $nextQueueId = $this->queueService->enqueueContinuation($localeCode, $content);
        }

        $message = (string)__(
            'I18n AI翻译完成：语言=%{locale}，本批=%{translated}，失败=%{failed}，剩余=%{remaining}，连续失败=%{streak}/%{max}',
            [
                'locale' => $localeCode,
                'translated' => (string)($result['translated'] ?? 0),
                'failed' => (string)($result['failed'] ?? 0),
                'remaining' => (string)($result['remaining'] ?? 0),
                'streak' => (string)$consecutiveFailures,
                'max' => (string)$maxFailures,
            ]
        );

        if ($busy) {
            $message .= PHP_EOL . (string)__('AI翻译繁忙，已结束本批；不立刻续队，等待下一轮定时任务。');
        } elseif ($batchFailed && !$halted) {
            $message .= PHP_EOL . (string)__(
                '本批无进展，已停止续队（连续失败 %{1}/%{2}）；等待下一轮定时任务。',
                [$consecutiveFailures, $maxFailures],
            );
        }

        if ($halted) {
            $message .= PHP_EOL . (string)__(
                '连续失败已达 %{1} 次，已停止自动续跑；请等待下次 cron 或手动入队后再试。',
                [$maxFailures],
            );
            if (!empty($result['message'])) {
                $message .= PHP_EOL . (string)$result['message'];
            }
        } elseif ($nextQueueId > 0) {
            $message .= PHP_EOL . (string)__('已创建下一批队列：#%{1}', [$nextQueueId]);
        }

        if (!empty($result['errors'])) {
            $message .= PHP_EOL . implode(PHP_EOL, array_map('strval', (array)$result['errors']));
        }

        return $message . PHP_EOL . 'QUEUE_DONE';
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeContent(TaskContextInterface $queue): array
    {
        $content = $queue->getContent();
        if (is_array($content)) {
            return $content;
        }

        $decoded = json_decode((string)$content, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $content
     */
    private function isManualQueue(array $content): bool
    {
        return !empty($content['manual'])
            || in_array((string)($content['requested_by'] ?? ''), ['manual', 'catalog', 'payment_guide', 'social_login_guide'], true);
    }

    /**
     * @param array<string, mixed> $content
     */
    private function isScopedDomainQueue(array $content): bool
    {
        return (string)($content['domain'] ?? '') === 'google_taxonomy'
            || (string)($content['domain'] ?? '') === 'payment_guide'
            || (string)($content['domain'] ?? '') === 'social_login_guide'
            || !empty($content['allow_key_only_words']);
    }

    /**
     * @param array<string, mixed> $content
     */
    private function isValidTargetLocale(string $localeCode, array $content): bool
    {
        if ($this->isScopedDomainQueue($content)) {
            return $this->config->isInstalledActiveLocale($localeCode);
        }

        return $this->config->isTranslatableLocale($localeCode);
    }

    /**
     * @param array<string, mixed> $content
     */
    private function shouldContinue(string $localeCode, array $content): bool
    {
        if ($this->isScopedDomainQueue($content)) {
            return $this->config->isInstalledActiveLocale($localeCode);
        }

        return $this->config->isTranslatableLocale($localeCode)
            && ($this->isManualQueue($content) || $this->config->isLocaleEnabled($localeCode));
    }

    /**
     * @param array<string, mixed> $content
     */
    private function resolveBatchSize(array $content, string $localeCode): int
    {
        $batchSize = (int)($content['batch_size'] ?? 0);
        if ($batchSize <= 0) {
            $batchSize = $this->config->getBatchSize($localeCode);
        }

        return $batchSize;
    }

    private function normalizeStrategy(string $strategy): string
    {
        if ($strategy === 'words') {
            return AiTranslationConfig::DEFAULT_STRATEGY;
        }

        return in_array($strategy, ['light', 'high_fidelity'], true)
            ? $strategy
            : AiTranslationConfig::DEFAULT_STRATEGY;
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    private function buildBatchScope(array $content, TaskContextInterface $queue): array
    {
        $scope = $content;
        $scope['owner'] = 'queue:' . (string)$queue->getId();

        if (
            $this->isScopedDomainQueue($content)
            && empty($content['words'])
            && trim((string)($content['word_prefix'] ?? '')) === ''
        ) {
            $scope['word_prefix'] = 'google_taxonomy.';
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $content
     * @param array<string, mixed> $result
     */
    private function advanceConsecutiveFailures(array $content, array $result): int
    {
        if ((int)($result['translated'] ?? 0) > 0) {
            return 0;
        }

        if (!$this->isBatchFailure($result)) {
            return max(0, (int)($content['consecutive_failures'] ?? 0));
        }

        return max(0, (int)($content['consecutive_failures'] ?? 0)) + 1;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function isBatchFailure(array $result): bool
    {
        if ((int)($result['translated'] ?? 0) > 0) {
            return false;
        }

        if (empty($result['success'])) {
            return true;
        }

        return (int)($result['failed'] ?? 0) > 0;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function resultIndicatesBusy(array $result): bool
    {
        foreach ((array)($result['errors'] ?? []) as $error) {
            if (str_contains((string)$error, 'AI_TRANSLATION_BUSY')) {
                return true;
            }
        }

        $message = (string)($result['message'] ?? '');

        return $message !== '' && str_contains($message, 'AI_TRANSLATION_BUSY');
    }
}
