<?php

declare(strict_types=1);

namespace Weline\Theme\Cron;

use Weline\Cron\CronTaskPreflightInterface;
use Weline\Framework\App\Env;
use Weline\Framework\Cron\CronTaskInterface;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySolidifyQueue;

/**
 * Drain durable theme-layout solidify jobs (FPM-safe; WLS may also eager-drain post-response).
 *
 * 仅开发环境运行：生产 WLS 由 PostResponseTaskQueue 响应后自行排水，
 * 请求闸门会按 slot 重新入队，本任务只兜 dev/FPM 的残留 job。
 */
final class LayoutSolidifyDrain implements CronTaskInterface, CronTaskPreflightInterface
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
        return '仅开发环境：每分钟排水 generated/theme-layout-entities/*/ .solidify-jobs（店面闸门落盘的重固任务）。'
            . '非开发环境跳过：WLS 由响应后加速自行排水，请求闸门会按 slot 重新入队。';
    }

    public function cron_time(): string
    {
        // Every minute; keep light — lease serializes same key.
        return '* * * * *';
    }

    /**
     * 非开发环境不产生子进程：生产由 WLS 响应后排水，本任务只兜 dev/FPM 残留。
     */
    public function hasPendingWork(): bool
    {
        if (!$this->isDevMode() && !$this->drainInProductionEnabled()) {
            return false;
        }

        return $this->queue->hasPendingJobs();
    }

    private function isDevMode(): bool
    {
        try {
            return (Env::system('deploy') ?? '') === 'dev';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 生产环境排水开关（默认关闭，保持「生产由 WLS 响应后排水」的既有契约）。
     * 打开后本 cron 任务也参与排水：用于「固化物 job 长期 pending、分类/搜索布局
     * 永远走 fallback original → 页面 no-store/BYPASS」的场景（2026-10-29 实测）。
     * 配置：app/etc/env.php → theme.layout_solidify.drain_in_production = true
     */
    private function drainInProductionEnabled(): bool
    {
        try {
            $config = Env::get('theme.layout_solidify', []);

            return \is_array($config) && (bool)($config['drain_in_production'] ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    public function execute(): string
    {
        if (!$this->isDevMode() && !$this->drainInProductionEnabled()) {
            return 'skip:non_dev';
        }
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
