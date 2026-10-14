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
        return '仅 system.deploy=dev 时监视默认主题+站绑自定义主题内部 .phtml hash。'
            . 'layouts 按级联 solidify；widgets 先账本 layout_type，无计划则快扫布局 <w:widget> 内嵌；'
            . '账本 * / 共享模板→全主题；chrome heal。含 Taglib→com_*。'
            . '固化/编译完成后仅按涉及布局 impact.slugs 清 FPC（禁止全站 clearAll）。'
            . '内嵌/账本用到的外模块部件以 __ext__ 监视；不监视 assets/。'
            . '开发机须 Cron 进程在跑。非开发 skip:non_dev。店面闸门不能替代本任务。';
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
                'theme_layout_origin_watch ok：scanned=%d changed=%d solidified=%d chrome_healed=%d cache_cleared=%d static=%d skipped=%d bootstrapped=%d，耗时 %s 秒%s',
                (int)($report['themes_scanned'] ?? 0),
                (int)($report['changed'] ?? 0),
                (int)($report['solidified'] ?? 0),
                (int)($report['chrome_healed'] ?? 0),
                (int)($report['cache_cleared'] ?? 0),
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
