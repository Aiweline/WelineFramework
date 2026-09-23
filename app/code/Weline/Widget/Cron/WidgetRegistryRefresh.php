<?php

declare(strict_types=1);

namespace Weline\Widget\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Widget\Service\WidgetRegistryRefreshService;

/**
 * 定时入库：复用 WidgetRegistryRefreshService（扫码 → generated/widgets.php → widget_registry_entry）。
 * 有 default_injections 变更时派发 widget_install_after → 主题 rebake。
 */
class WidgetRegistryRefresh implements CronTaskInterface
{
    public function __construct(
        private readonly WidgetRegistryRefreshService $refreshService,
    ) {
    }

    public function name(): string
    {
        return 'Widget 注册账本定时刷新';
    }

    public function execute_name(): string
    {
        return 'widget_registry_refresh';
    }

    public function tip(): string
    {
        return '每小时复用 widget:refresh：扫码同步 widget_registry_entry（含 default_injections 计划），变更后触发固化对账。';
    }

    public function cron_time(): string
    {
        // Stagger after i18n_dictionary_collect at :10.
        return '20 * * * *';
    }

    public function execute(): string
    {
        $startTime = microtime(true);

        try {
            $report = $this->refreshService->refresh('cron_widget_registry_refresh');
            $duration = round(microtime(true) - $startTime, 2);
            $created = (int)($report['created_count'] ?? 0);
            $updated = (int)($report['updated_count'] ?? 0);
            $injection = (int)($report['created_default_injection_count'] ?? 0);
            $dispatched = !empty($report['widget_install_event_dispatched']) ? 'yes' : 'no';
            $ok = !empty($report['success']) ? 'ok' : 'fail';

            return "Widget 注册账本刷新 {$ok}：created={$created} updated={$updated} default_injection={$injection} install_event={$dispatched}，耗时 {$duration} 秒";
        } catch (\Throwable $throwable) {
            return 'Widget 注册账本刷新异常: ' . $throwable->getMessage();
        }
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 120;
    }
}
