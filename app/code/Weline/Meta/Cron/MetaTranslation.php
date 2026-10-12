<?php

declare(strict_types=1);

namespace Weline\Meta\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\I18n\Service\AiTranslationConfig;
use Weline\Meta\Service\MetaLocalTranslationService;

/**
 * Meta 专责定时翻译：只跑 MetaLocal（不再 translate 词典 @meta::）。
 */
class MetaTranslation implements CronTaskInterface
{
    public function __construct(
        private readonly AiTranslationConfig $translationConfig,
        private readonly MetaLocalTranslationService $metaLocalTranslationService,
    ) {
    }

    public function name(): string
    {
        return 'Meta 元数据自动翻译';
    }

    public function execute_name(): string
    {
        return 'meta_translation';
    }

    public function tip(): string
    {
        return '为 MetaLocal 多语言表批量 AI 翻译（独立于 I18n 全局 cron；不写词典 @meta::）';
    }

    public function cron_time(): string
    {
        return '15 * * * *';
    }

    public function execute(): string
    {
        if (!$this->translationConfig->isEnabled()) {
            return (string)__('Meta 自动翻译：I18n AI 未启用，已跳过');
        }

        $localResult = $this->metaLocalTranslationService->batchTranslateEnabledLocales();

        return (string)__(
            'MetaLocal: translated=%{1}, skipped=%{2}',
            [(string)($localResult['translated'] ?? 0), (string)($localResult['skipped'] ?? 0)],
        );
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 60;
    }
}
