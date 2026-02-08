<?php

declare(strict_types=1);

namespace Weline\Framework\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Framework\System\Process\Processer;

/**
 * 进程管理器 GC 定时任务
 *
 * 定期清理进程索引与日志：移除已不存活进程的索引、清理 N 天前的生命周期日志、
 * 清理遗留的 {pid}.pid 文件等。
 *
 * @package Weline\Framework\Cron
 */
class ProcessGc implements CronTaskInterface
{
    /** 日志保留天数 */
    private const LOG_RETENTION_DAYS = 7;

    public function name(): string
    {
        return __('进程管理器清理任务');
    }

    public function execute_name(): string
    {
        return 'process_gc';
    }

    public function tip(): string
    {
        $tip = __('清理已不存活进程的索引与 PID 文件，以及 %{1} 天前的生命周期日志。', self::LOG_RETENTION_DAYS) . PHP_EOL;
        $tip .= __('执行频率：每日一次。');
        return $tip;
    }

    public function cron_time(): string
    {
        // 每天 03:00 执行
        return '0 3 * * *';
    }

    public function execute(): string
    {
        $stats = Processer::runProcessGc(self::LOG_RETENTION_DAYS);
        $msg = __('进程管理器清理完成。');
        $msg .= ' ' . __('日志行清理: %{1}', $stats['log_lines_removed']);
        $msg .= '，' . __('陈旧索引项: %{1}', $stats['stale_pids_removed']);
        $msg .= '，' . __('陈旧 PID 文件: %{1}', $stats['stale_json_files_removed']);
        $msg .= '，' . __('遗留 .pid 文件: %{1}', $stats['legacy_pid_files_removed']);
        return $msg;
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 5;
    }
}
