<?php

declare(strict_types=1);

namespace Weline\Theme\Cron;

use Weline\Framework\App\Env;
use Weline\Framework\Cron\CronTaskInterface;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutOriginWatchService;

/**
 * Dev-only watch: design origin hash → ensure bound scopes → cascade solidify / chrome heal.
 * Production and other deploy modes must fast-skip (no disk scan, no bake).
 */
final class LayoutOriginWatch implements CronTaskInterface
{
    public function __construct(
        private readonly ThemeLayoutOriginWatchService $watch,
    ) {
    }

    public function name(): string
    {
        return '主题源变更自动重固（仅开发模式）';
    }

    public function execute_name(): string
    {
        return 'theme_layout_origin_watch';
    }

    public function tip(): string
    {
        return '仅 system.deploy=dev 时监视站绑主题 design 源 hash 并自动 solidify/chrome heal。'
            . '开发机须 Cron 进程在跑，否则改 design 后仍需手动 theme:upgrade。'
            . '非开发模式每分钟 skip:non_dev（不扫盘、不 bake）。'
            . '店面闸门+排水不能替代本任务。';
    }

    public function cron_time(): string
    {
        return '* * * * *';
    }

    public function execute(): string
    {
        if ((Env::system('deploy') ?? '') !== 'dev') {
            return 'skip:non_dev';
        }

        $start = \microtime(true);
        try {
            $report = $this->watch->run();
            $duration = \round(\microtime(true) - $start, 2);
            $messages = $report['messages'] ?? [];
            $extra = $messages === [] ? '' : ('; msgs=' . \implode('|', \array_slice($messages, 0, 5)));

            return \sprintf(
                'theme_layout_origin_watch ok：scanned=%d changed=%d solidified=%d chrome_healed=%d static=%d skipped=%d bootstrapped=%d，耗时 %s 秒%s',
                (int)($report['themes_scanned'] ?? 0),
                (int)($report['changed'] ?? 0),
                (int)($report['solidified'] ?? 0),
                (int)($report['chrome_healed'] ?? 0),
                (int)($report['static_published'] ?? 0),
                (int)($report['skipped_files'] ?? 0),
                (int)($report['bootstrapped'] ?? 0),
                (string)$duration,
                $extra,
            );
        } catch (\Throwable $e) {
            return 'theme_layout_origin_watch 异常: ' . $e->getMessage();
        }
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return \max(30, $minute);
    }
}
