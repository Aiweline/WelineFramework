<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\Event\EventsManager;

class VisitorDashboardPageInstaller
{
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
            'content' => [
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
                            'colSpan' => 9,
                            'rowSpan' => 1,
                            'sortOrder' => 40,
                        ],
                    ],
                ],
            ],
        ];
    }
}
