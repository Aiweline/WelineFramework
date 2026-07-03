<?php

declare(strict_types=1);

return [
    'overview_kpi' => [
        'name' => '后台统计',
        'description' => '后台 Dashboard 默认统计部件，展示侧栏收敛、面板部件、视图和站点概览。',
        'type' => 'stats',
        'code' => 'overview_kpi',
        'area' => 'backend',
        'template' => 'Weline_Dashboard::templates/dashboard/widgets/overview-kpi.phtml',
        'page_layouts' => ['dashboard'],
        'position' => ['dashboard-summary'],
        'slot' => 'dashboard-summary',
        'supports' => ['dashboard-widget', 'dashboard-slot-summary', 'dashboard-stat', 'dashboard-kpi'],
    ],
    'activity_trend' => [
        'name' => '活跃趋势',
        'description' => '后台 Dashboard 默认趋势图部件。',
        'type' => 'chart',
        'code' => 'activity_trend',
        'area' => 'backend',
        'template' => 'Weline_Dashboard::templates/dashboard/widgets/activity-trend.phtml',
        'page_layouts' => ['dashboard'],
        'position' => ['dashboard-analysis'],
        'slot' => 'dashboard-analysis',
        'supports' => ['dashboard-widget', 'dashboard-slot-analysis', 'dashboard-chart', 'dashboard-trend'],
    ],
    'system_status' => [
        'name' => '系统状态',
        'description' => '后台 Dashboard 默认系统状态表格。',
        'type' => 'table',
        'code' => 'system_status',
        'area' => 'backend',
        'template' => 'Weline_Dashboard::templates/dashboard/widgets/system-status.phtml',
        'page_layouts' => ['dashboard'],
        'position' => ['dashboard-side', 'dashboard-detail'],
        'slot' => 'dashboard-side',
        'supports' => ['dashboard-widget', 'dashboard-slot-side', 'dashboard-status', 'dashboard-table'],
    ],
    'detail_snapshot' => [
        'name' => '默认明细',
        'description' => '后台 Dashboard 默认明细表格。',
        'type' => 'table',
        'code' => 'detail_snapshot',
        'area' => 'backend',
        'template' => 'Weline_Dashboard::templates/dashboard/widgets/detail-snapshot.phtml',
        'page_layouts' => ['dashboard'],
        'position' => ['dashboard-detail'],
        'slot' => 'dashboard-detail',
        'supports' => ['dashboard-widget', 'dashboard-slot-detail', 'dashboard-table', 'dashboard-list'],
    ],
];
