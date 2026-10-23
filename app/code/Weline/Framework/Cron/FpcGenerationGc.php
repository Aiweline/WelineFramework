<?php

declare(strict_types=1);

namespace Weline\Framework\Cron;

use Weline\Framework\Http\Fpc\FpcHtmlDiskStore;

/**
 * 删除超过保留天数的 FPC 世代目录和缓存文件。
 * 当前 live 世代和正在写入的 staging 不删。过期世代个数上限在轮转时保持为 3。
 */
class FpcGenerationGc implements CronTaskInterface
{
    public function name(): string
    {
        return __('整页缓存世代清理');
    }

    public function execute_name(): string
    {
        return 'fpc_generation_gc';
    }

    public function tip(): string
    {
        $tip = __('删除 %{1} 天前的整页缓存世代目录和缓存文件。当前世代不删。', FpcHtmlDiskStore::GENERATION_MAX_AGE_DAYS) . PHP_EOL;
        $tip .= __('执行频率：每日一次。');

        return $tip;
    }

    public function cron_time(): string
    {
        return '30 3 * * *';
    }

    public function execute(): string
    {
        $stats = FpcHtmlDiskStore::pruneOlderThanDays(FpcHtmlDiskStore::GENERATION_MAX_AGE_DAYS);
        $msg = __('整页缓存世代清理完成。');
        $msg .= ' ' . __('世代目录: %{1}', $stats['generations']);
        $msg .= '，' . __('缓存文件: %{1}', $stats['files']);

        return $msg;
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 10;
    }
}
