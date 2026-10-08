<?php

declare(strict_types=1);

namespace Weline\Theme\Cron;

use Weline\Framework\Cron\CronTaskInterface;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySolidifyQueue;

/**
 * Drain durable theme-layout solidify jobs (FPM-safe; WLS may also eager-drain post-response).
 */
final class LayoutSolidifyDrain implements CronTaskInterface
{
    public function __construct(
        private readonly ThemeLayoutEntitySolidifyQueue $queue,
    ) {
    }

    public function name(): string
    {
        return '主题布局固化物异步排水';
    }

    public function execute_name(): string
    {
        return 'theme_layout_solidify_drain';
    }

    public function tip(): string
    {
        return '每分钟排水 generated/theme-layout-entities/*/ .solidify-jobs（店面闸门落盘的重固任务）。FPM 依赖本任务；WLS 可另有响应后加速。';
    }

    public function cron_time(): string
    {
        // Every minute; keep light — lease serializes same key.
        return '* * * * *';
    }

    public function execute(): string
    {
        $start = microtime(true);
        try {
            $report = $this->queue->drainPendingJobs(16);
            $duration = round(microtime(true) - $start, 2);

            return sprintf(
                '布局固化物排水 ok：processed=%d skipped=%d failed=%d，耗时 %s 秒',
                (int)($report['processed'] ?? 0),
                (int)($report['skipped'] ?? 0),
                (int)($report['failed'] ?? 0),
                (string)$duration,
            );
        } catch (\Throwable $e) {
            return '布局固化物排水异常: ' . $e->getMessage();
        }
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return max(5, $minute);
    }
}
