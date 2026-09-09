<?php
declare(strict_types=1);

namespace Weline\I18n\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\I18n\Service\AiTranslationConfig;
use Weline\I18n\Service\AiTranslationQueueService;
use Weline\I18n\Service\LocalModelTranslation\LocalModelTranslationQueueService;
use Weline\I18n\Service\WebsiteLocaleTranslationSync;

/**
 * AI 批量翻译定时任务：仅入队，不直接调用翻译，避免与队列 worker 并发重复选词。
 */
class AiTranslation implements CronTaskInterface
{
    public function __construct(
        private readonly AiTranslationConfig $config,
        private readonly AiTranslationQueueService $queueService,
        private readonly LocalModelTranslationQueueService $localModelQueueService,
        private readonly WebsiteLocaleTranslationSync $websiteLocaleTranslationSync,
    ) {
    }

    public function name(): string
    {
        return 'I18n AI批量翻译任务';
    }

    public function execute_name(): string
    {
        return 'i18n_ai_translation';
    }

    public function tip(): string
    {
        return '每 5 分钟同步多网站语言并集并入队 AI 翻译：并集新增则翻译，并从并集移除则跳过；含词典 + LocalModel。BUSY 后由下次 cron 续跑。';
    }

    public function cron_time(): string
    {
        // Was hourly: after AI_TRANSLATION_BUSY the backlog sat idle until the next :00.
        // */5 keeps feeding the queue while the model is free; idempotent enqueue skips duplicates.
        return '*/5 * * * *';
    }

    public function execute(): string
    {
        $startTime = microtime(true);

        try {
            if (!$this->config->isEnabled()) {
                return (string)__('I18n AI 自动翻译未启用，cron 已跳过');
            }

            // Heal gaps: install website-union locales and force-enable them before enqueue.
            $this->websiteLocaleTranslationSync->ensureWebsiteUnionReady();

            $enabledLocales = $this->config->getEnabledLocaleCodes();
            if ($enabledLocales === []) {
                return (string)__('没有启用的 AI 翻译语言（含多网站语言并集）');
            }

            $queueIds = $this->queueService->enqueueEnabledLocales('cron', false);
            $localModelQueueId = $this->localModelQueueService->enqueue('cron');
            $duration = round(microtime(true) - $startTime, 2);

            if ($queueIds === [] && $localModelQueueId <= 0) {
                return (string)__(
                    '所有启用语言与 LocalModel 翻译队列均已有待运行/运行中任务，cron 未重复入队（耗时 %{1} 秒）',
                    [$duration],
                );
            }

            $lines = [];
            foreach ($queueIds as $localeCode => $queueId) {
                $lines[] = (string)__('词典 %{1}: 队列 #%{2}', [$localeCode, $queueId]);
            }
            if ($localModelQueueId > 0) {
                $lines[] = (string)__('LocalModel: 队列 #%{1}', [$localModelQueueId]);
            }

            return (string)__(
                "AI 翻译 cron 入队完成 - 词典语言数: %{1}, LocalModel: %{2}, 耗时: %{3} 秒\n%{4}",
                [count($queueIds), $localModelQueueId > 0 ? '1' : '0', $duration, implode("\n", $lines)],
            );
        } catch (\Throwable $throwable) {
            return (string)__('AI批量翻译异常: %{1}', [$throwable->getMessage()]);
        }
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 60;
    }
}
