<?php

declare(strict_types=1);

return [
    'dropship_overview' => [
        'name' => '货源销售概览',
        'description' => 'CJ/货源近期刊登与推送异常概览',
        'type' => 'stats',
        'code' => 'dropship_overview',
        'area' => 'backend',
        'template' => 'Weline_Dropship::templates/dashboard/widgets/dropship-overview.phtml',
        'page_layouts' => ['dashboard'],
        'position' => ['dashboard-summary'],
        'slot' => 'dashboard-summary',
        'supports' => ['dashboard-widget', 'dashboard-slot-summary', 'dashboard-stat', 'dashboard-kpi'],
        'default_injections' => [[
            'layout_type' => 'dashboard',
            'layout_option' => 'default',
            'default_view' => 'default',
            'target_type' => 'website',
            'slot' => 'dashboard-summary',
            'area' => 'content',
            'sort_order' => 80,
            'required' => false,
            'reason' => '货源代发模块安装后展示销售概览',
            'config' => [
                'dashboard_layout' => [
                    'colSpan' => 3,
                    'rowSpan' => 1,
                    'sortOrder' => 80,
                ],
            ],
        ]],
    ],
];
