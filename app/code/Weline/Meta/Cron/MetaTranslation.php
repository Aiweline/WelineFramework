<?php

declare(strict_types=1);

namespace Weline\Meta\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Framework\Phrase\DictionaryEvents;
use Weline\I18n\Service\AiTranslationConfig;
use Weline\Meta\Service\MetaLocalTranslationService;

/**
 * Meta 专责定时翻译：@meta:: 词典 + MetaLocal 表。
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
        return '为 @meta:: 词典键与 MetaLocal 多语言表批量 AI 翻译（独立于 I18n 全局 cron）';
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

        $lines = [];
        $writeCsv = defined('DEV') && DEV;

        foreach ($this->translationConfig->getEnabledLocaleCodes() as $localeCode) {
            if ($localeCode === $this->translationConfig->getSourceLocale()) {
                continue;
            }
            $result = DictionaryEvents::translate('Weline_Meta', $localeCode, [
                'word_prefix' => '@meta::',
                'write_csv' => $writeCsv,
                'owner' => 'Weline_Meta:cron',
            ]);
            $lines[] = (string)__(
                '@meta:: %{1}: translated=%{2}',
                [$localeCode, (string)($result['translated'] ?? 0)],
            );
        }

        $localResult = $this->metaLocalTranslationService->batchTranslateEnabledLocales();
        $lines[] = (string)__(
            'MetaLocal: translated=%{1}, skipped=%{2}',
            [(string)($localResult['translated'] ?? 0), (string)($localResult['skipped'] ?? 0)],
        );

        return implode("\n", $lines);
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 60;
    }
}
