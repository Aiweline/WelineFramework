<?php
declare(strict_types=1);

namespace Weline\I18n\Queue;

use Weline\I18n\Service\AiTranslationConfig;
use Weline\I18n\Service\AiTranslationQueueService;
use Weline\I18n\Service\AiTranslationService;
use Weline\Queue\Model\Queue;
use Weline\Queue\QueueInterface;

class AiTranslateQueue implements QueueInterface
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

    public function validate(Queue &$queue): bool
    {
        $content = $this->decodeContent($queue);
        $localeCode = (string)($content['locale_code'] ?? '');

        if ($localeCode === '') {
            $queue->setResult((string)__('验证失败：缺少 locale_code。'));
            return false;
        }

        if (!$this->config->isTranslatableLocale($localeCode)) {
            $queue->setResult((string)__('验证失败：语言 %{1} 未安装启用或为源语言。', [$localeCode]));
            return false;
        }

        if (!$this->isManualQueue($content) && !$this->config->isLocaleEnabled($localeCode)) {
            $queue->setResult((string)__('验证失败：语言 %{1} 未开启 AI 自动翻译，自动队列已跳过。', [$localeCode]));
            return false;
        }

        $batchSize = (int)($content['batch_size'] ?? 0);
        if ($batchSize <= 0 || $batchSize > AiTranslationConfig::MAX_BATCH_SIZE) {
            $queue->setResult((string)__('验证失败：batch_size 必须在 1-%{1} 之间。', [AiTranslationConfig::MAX_BATCH_SIZE]));
            return false;
        }

        return true;
    }

    public function execute(Queue &$queue): string
    {
        $content = $this->decodeContent($queue);
        $localeCode = (string)$content['locale_code'];
        $sourceLocale = (string)($content['source_locale'] ?? $this->config->getSourceLocale());
        $batchSize = (int)($content['batch_size'] ?? $this->config->getBatchSize($localeCode));
        $strategy = (string)($content['strategy'] ?? $this->config->getStrategy($localeCode));
        $publish = (bool)($content['publish'] ?? $this->config->shouldAutoPublish());

        $result = $this->translationService->batchTranslateDictionary(
            $localeCode,
            $sourceLocale,
            $batchSize,
            $strategy,
            $publish
        );

        if (empty($result['success'])) {
            throw new \RuntimeException((string)($result['message'] ?? __('AI 翻译失败')));
        }

        $nextQueueId = 0;
        if (
            (int)($result['remaining'] ?? 0) > 0
            && $this->config->isTranslatableLocale($localeCode)
            && ($this->isManualQueue($content) || $this->config->isLocaleEnabled($localeCode))
        ) {
            $nextQueueId = $this->queueService->enqueueContinuation($localeCode, $content);
        }

        $message = (string)__(
            'I18n AI翻译完成：语言=%{locale}，本批=%{translated}，失败=%{failed}，剩余=%{remaining}',
            [
                'locale' => $localeCode,
                'translated' => (string)($result['translated'] ?? 0),
                'failed' => (string)($result['failed'] ?? 0),
                'remaining' => (string)($result['remaining'] ?? 0),
            ]
        );

        if ($nextQueueId > 0) {
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
    private function decodeContent(Queue $queue): array
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
        return !empty($content['manual']) || (string)($content['requested_by'] ?? '') === 'manual';
    }
}
