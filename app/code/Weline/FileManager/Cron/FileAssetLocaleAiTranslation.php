<?php

declare(strict_types=1);

namespace Weline\FileManager\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\FileManager\Api\FileAssetLocaleTranslationInterface;

/**
 * FileAssetLocale AI gap-fill: enqueue only; workers perform translation.
 */
class FileAssetLocaleAiTranslation implements CronTaskInterface
{
    public function __construct(
        private readonly FileAssetLocaleTranslationInterface $translations,
    ) {
    }

    public function name(): string
    {
        return '文件资源多语言 AI 自动翻译';
    }

    public function execute_name(): string
    {
        return 'filemanager_file_asset_locale_ai_translation';
    }

    public function tip(): string
    {
        return '模块开启 AI 自动翻译后，定时为缺失语言的 FileAssetLocale 入队补缺（已有文案跳过）';
    }

    public function cron_time(): string
    {
        return '20 * * * *';
    }

    public function execute(): string
    {
        $start = microtime(true);
        if (!$this->translations->isAutoTranslationEnabled()) {
            return (string)__('文件资源 AI 自动翻译未启用，cron 已跳过');
        }
        $queueId = $this->translations->enqueueAutoFill('cron');
        $duration = round(microtime(true) - $start, 2);
        if ($queueId <= 0) {
            return (string)__('文件资源 AI 翻译未入队（未启用、无缺口或已有待运行任务），耗时 %{1} 秒', [$duration]);
        }

        return (string)__('文件资源 AI 翻译已入队 #%{1}，耗时 %{2} 秒', [$queueId, $duration]);
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 60;
    }
}
