<?php

declare(strict_types=1);

namespace Weline\Framework\Cron;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Log\LogDirectoryGcService;

/**
 * 应用日志目录 GC 定时任务
 *
 * 每 30 分钟对 var/log 与 var/cron.log 执行超限 copytruncate，并 prune 轮转归档。
 * 与 process_gc（进程生命周期日志）职责分离。
 */
class LogGc implements CronTaskInterface
{
    public function name(): string
    {
        return __('应用日志目录清理');
    }

    public function execute_name(): string
    {
        return 'log_gc';
    }

    public function tip(): string
    {
        $tip = __('按 log.rotate.max_size 对 var/log/**/*.log 与 var/cron.log 做 copytruncate，并保留 max_files 个归档；清理 cron/history 过期快照。') . PHP_EOL;
        $tip .= __('执行频率：每 30 分钟。覆盖 php_error 等绕过框架 Logger 的文件。');
        return $tip;
    }

    public function cron_time(): string
    {
        return '*/30 * * * *';
    }

    public function execute(): string
    {
        /** @var LogDirectoryGcService $gcService */
        $gcService = ObjectManager::getInstance(LogDirectoryGcService::class);
        $stats = $gcService->run();
        $msg = __('应用日志目录清理完成。');
        $msg .= ' ' . __('扫描: %{1}', $stats['scanned']);
        $msg .= '，' . __('轮转: %{1}', $stats['rotated']);
        $msg .= '，' . __('归档清理: %{1}', $stats['pruned_archives']);
        $msg .= '，' . __('history清理: %{1}', $stats['pruned_history']);
        if ($stats['errors'] !== []) {
            $msg .= '，' . __('错误: %{1}', count($stats['errors']));
        }
        return $msg;
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 5;
    }
}
