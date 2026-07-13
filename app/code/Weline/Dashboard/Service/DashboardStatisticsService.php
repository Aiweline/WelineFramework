<?php

declare(strict_types=1);

namespace Weline\Dashboard\Service;

use Weline\Acl\Api\Statistics\MenuStatisticsInterface;
use Weline\Dashboard\Model\DashboardView;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;
use Weline\Widget\Api\WidgetRegistryInterface;

class DashboardStatisticsService
{
    private const VISIBLE_SIDEBAR_ENTRY_COUNT = 2;

    public function __construct(
        private readonly MenuStatisticsInterface $menuStatistics,
        private readonly DashboardView $dashboardView,
        private readonly WebsiteCatalogInterface $websiteCatalog,
        private readonly WidgetRegistryInterface $widgetRegistry
    ) {
    }

    /**
     * @return array<int, array{label: string, value: string, hint: string}>
     */
    public function getKpiItems(): array
    {
        $activeMenuCount = $this->countActiveBackendMenus();
        $hiddenMenuCount = max(0, $activeMenuCount - self::VISIBLE_SIDEBAR_ENTRY_COUNT);

        return [
            [
                'label' => (string)__('侧栏入口'),
                'value' => (string)self::VISIBLE_SIDEBAR_ENTRY_COUNT,
                'hint' => (string)__('仪表盘 / 首页'),
            ],
            [
                'label' => (string)__('已收入口'),
                'value' => $this->formatCount($hiddenMenuCount),
                'hint' => (string)__('改由面板部件承载'),
            ],
            [
                'label' => (string)__('统计部件'),
                'value' => $this->formatCount($this->countDashboardWidgets()),
                'hint' => (string)__('dashboard-widget'),
            ],
            [
                'label' => (string)__('视图 / 站点'),
                'value' => $this->formatCount($this->countActiveDashboardViews()) . ' / ' . $this->formatCount($this->countWebsites()),
                'hint' => (string)__('Dashboard 范围'),
            ],
        ];
    }

    private function countActiveBackendMenus(): int
    {
        return $this->safeCount(function (): int {
            return $this->menuStatistics->countActiveBackendMenus();
        });
    }

    private function countActiveDashboardViews(): int
    {
        return $this->safeCount(function (): int {
            return (int)$this->dashboardView->reset()
                ->where(DashboardView::schema_fields_IS_ACTIVE, 1)
                ->count(DashboardView::schema_fields_ID);
        });
    }

    private function countWebsites(): int
    {
        return $this->safeCount(function (): int {
            return $this->websiteCatalog->count();
        });
    }

    private function countDashboardWidgets(): int
    {
        try {
            $registry = $this->widgetRegistry->getRegistry(false);
        } catch (\Throwable) {
            return 0;
        }

        $count = 0;
        foreach ($registry as $widgets) {
            if (!is_array($widgets)) {
                continue;
            }
            foreach ($widgets as $widget) {
                if (!is_array($widget)) {
                    continue;
                }
                $supports = $widget['supports'] ?? [];
                if (!is_array($supports)) {
                    $supports = [$supports];
                }
                if (in_array('dashboard-widget', $supports, true) || in_array('dashboard-stat', $supports, true)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function safeCount(callable $callback): int
    {
        try {
            return max(0, (int)$callback());
        } catch (\Throwable) {
            return 0;
        }
    }

    private function formatCount(int $count): string
    {
        return number_format(max(0, $count));
    }
}
