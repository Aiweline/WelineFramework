<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\Event\EventsManager;

class VisitorDashboardPageInstaller
{
    /**
     * E05：catalog 六个预设部件（追加进种子布局；不替换既有概览/趋势等部件）。
     *
     * @var list<string>
     */
    public const CATALOG_WIDGET_CODES = [
        'pixel_channels',
        'pixel_traffic_type',
        'pixel_paid',
        'pixel_social',
        'pixel_event_value',
        'pixel_value_by_channel',
    ];

    /**
     * F05a：电商三部件（漏斗 / 购成收入 / 商品表现；追加进种子；不进 report_catalog）。
     *
     * @var list<string>
     */
    public const ECOMMERCE_WIDGET_CODES = [
        'pixel_ecommerce_funnel',
        'pixel_ecommerce_revenue',
        'pixel_ecommerce_items',
    ];

    public function __construct(
        private readonly EventsManager $eventsManager
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function ensurePages(): array
    {
        $payload = [
            'module' => 'Weline_Visitor',
            'page_type' => 'dashboard',
            'layout_type' => 'dashboard',
            'layout_option' => 'default',
            'target_type' => 'website',
            'target_id' => '*',
            'code' => 'weline_visitor_event_statistics',
            'name' => (string)__('事件统计'),
            'visibility' => 'system',
            'sort_order' => 20,
            'copy_default_layout' => false,
            // 增量：已有运营布局不整页覆盖；空布局/新建页才写入含 catalog 的种子。
            'replace_layout' => false,
            'layout' => $this->eventStatisticsLayout(),
        ];

        $this->eventsManager->dispatch('Weline_Dashboard::layout_page_ensure', $payload);

        return is_array($payload['result'] ?? null)
            ? $payload['result']
            : ['success' => false, 'status' => 'dashboard_event_not_observed'];
    }

    /**
     * @return array<string,list<array<string,mixed>>>
     */
    private function eventStatisticsLayout(): array
    {
        return [
            'content' => array_merge(
                $this->legacyEventStatisticsWidgets(),
                $this->catalogReportWidgets(),
                $this->ecommerceWidgets()
            ),
        ];
    }

    /**
     * 既有事件统计六部件（概览/趋势/实时/热门事件/参与度/热门页面）。
     *
     * @return list<array<string,mixed>>
     */
    private function legacyEventStatisticsWidgets(): array
    {
        return [
            [
                'widget_module' => 'Weline_Visitor',
                'widget_type' => 'stats',
                'widget_code' => 'pixel_overview',
                'slot_id' => 'dashboard-summary',
                'sort_order' => 10,
                'config' => [
                    'dashboard_layout' => [
                        'colSpan' => 4,
                        'rowSpan' => 1,
                        'sortOrder' => 10,
                    ],
                ],
            ],
            [
                'widget_module' => 'Weline_Visitor',
                'widget_type' => 'chart',
                'widget_code' => 'pixel_event_trend',
                'slot_id' => 'dashboard-analysis',
                'sort_order' => 20,
                'config' => [
                    'dashboard_layout' => [
                        'colSpan' => 6,
                        'rowSpan' => 2,
                        'sortOrder' => 20,
                    ],
                ],
            ],
            [
                'widget_module' => 'Weline_Visitor',
                'widget_type' => 'list',
                'widget_code' => 'pixel_realtime',
                'slot_id' => 'dashboard-side',
                'sort_order' => 30,
                'config' => [
                    'dashboard_layout' => [
                        'colSpan' => 3,
                        'rowSpan' => 2,
                        'sortOrder' => 30,
                    ],
                ],
            ],
            [
                'widget_module' => 'Weline_Visitor',
                'widget_type' => 'table',
                'widget_code' => 'pixel_top_events',
                'slot_id' => 'dashboard-detail',
                'sort_order' => 40,
                'config' => [
                    'dashboard_layout' => [
                        'colSpan' => 5,
                        'rowSpan' => 1,
                        'sortOrder' => 40,
                    ],
                ],
            ],
            [
                'widget_module' => 'Weline_Visitor',
                'widget_type' => 'stats',
                'widget_code' => 'pixel_engagement',
                'slot_id' => 'dashboard-summary',
                'sort_order' => 50,
                'config' => [
                    'dashboard_layout' => [
                        'colSpan' => 4,
                        'rowSpan' => 1,
                        'sortOrder' => 50,
                    ],
                ],
            ],
            [
                'widget_module' => 'Weline_Visitor',
                'widget_type' => 'table',
                'widget_code' => 'pixel_pages',
                'slot_id' => 'dashboard-detail',
                'sort_order' => 60,
                'config' => [
                    'dashboard_layout' => [
                        'colSpan' => 4,
                        'rowSpan' => 1,
                        'sortOrder' => 60,
                    ],
                ],
            ],
        ];
    }

    /**
     * E05：仅追加 catalog 报表部件；sort_order 与 widget.php default_injections 对齐。
     *
     * @return list<array<string,mixed>>
     */
    private function catalogReportWidgets(): array
    {
        $sortByCode = [
            'pixel_channels' => 78,
            'pixel_traffic_type' => 79,
            'pixel_paid' => 80,
            'pixel_social' => 81,
            'pixel_event_value' => 82,
            'pixel_value_by_channel' => 83,
        ];

        $widgets = [];
        foreach (self::CATALOG_WIDGET_CODES as $code) {
            $sort = $sortByCode[$code];
            $widgets[] = [
                'widget_module' => 'Weline_Visitor',
                'widget_type' => 'table',
                'widget_code' => $code,
                'slot_id' => 'dashboard-detail',
                'sort_order' => $sort,
                'config' => [
                    'dashboard_layout' => [
                        'colSpan' => 4,
                        'rowSpan' => 1,
                        'sortOrder' => $sort,
                    ],
                ],
            ];
        }

        return $widgets;
    }

    /**
     * F05a：追加电商三部件；sort_order 与 widget.php default_injections 对齐。
     *
     * @return list<array<string,mixed>>
     */
    private function ecommerceWidgets(): array
    {
        $metaByCode = [
            'pixel_ecommerce_funnel' => ['type' => 'table', 'sort' => 84],
            'pixel_ecommerce_revenue' => ['type' => 'stats', 'sort' => 85],
            'pixel_ecommerce_items' => ['type' => 'table', 'sort' => 86],
        ];

        $widgets = [];
        foreach (self::ECOMMERCE_WIDGET_CODES as $code) {
            $meta = $metaByCode[$code];
            $sort = $meta['sort'];
            $widgets[] = [
                'widget_module' => 'Weline_Visitor',
                'widget_type' => $meta['type'],
                'widget_code' => $code,
                'slot_id' => 'dashboard-detail',
                'sort_order' => $sort,
                'config' => [
                    'dashboard_layout' => [
                        'colSpan' => 4,
                        'rowSpan' => 1,
                        'sortOrder' => $sort,
                    ],
                ],
            ];
        }

        return $widgets;
    }
}
