<?php

declare(strict_types=1);

namespace Weline\Server\Cron;

use Weline\Framework\Cron\CronTaskInterface;
use Weline\Server\Runtime\Resumable\RuntimeTaskWatchdog;

/** 每分钟为未由 WLS 托管的部署执行一次可恢复任务检查。 */
final class RuntimeTaskWatch implements CronTaskInterface
{
    public function __construct(private readonly RuntimeTaskWatchdog $watchdog)
    {
    }

    public function name(): string
    {
        return (string) __('可恢复后台任务观察');
    }

    public function execute_name(): string
    {
        return 'weline_runtime_task_watch';
    }

    public function tip(): string
    {
        return (string) __('检查可恢复任务的 Runner、租约和过期心跳，并原子接管可恢复任务。');
    }

    public function cron_time(): string
    {
        return '* * * * *';
    }

    public function execute(): string
    {
        $report = $this->watchdog->tick();

        return (string) __(
            '可恢复任务观察完成：检查 %{1}，发起恢复 %{2}，恢复失败 %{3}。',
            [$report->inspected, $report->recoveriesLaunched, $report->launchFailures],
        );
    }

    public function unlock_timeout(int $minute = 2): int
    {
        return max(1, $minute);
    }
}
