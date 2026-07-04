<?php

declare(strict_types=1);

return [
    'install_default_card' => [
        'name' => 'Widget Demo Install Card',
        'description' => 'Demo widget used by E2E to verify first DB registration default injection.',
        'type' => 'stats',
        'code' => 'install_default_card',
        'area' => 'backend',
        'template' => 'Weline_WidgetDemo::templates/dashboard/widgets/install-default-card.phtml',
        'page_layouts' => ['dashboard'],
        'position' => ['dashboard-side'],
        'slot' => 'dashboard-side',
        'supports' => ['dashboard-widget', 'dashboard-slot-side', 'widget-demo-install'],
        'default_injections' => [[
            'layout_type' => 'dashboard',
            'layout_option' => 'default',
            'target_type' => 'website',
            'slot' => 'dashboard-side',
            'area' => 'content',
            'sort_order' => 15,
            'required' => true,
            'reason' => 'Demo widget should be installed into Dashboard side slot on first DB registration',
            'config' => [
                'demo_label' => 'first-db-registration',
                'dashboard_layout' => [
                    'colSpan' => 3,
                    'rowSpan' => 1,
                    'sortOrder' => 15,
                ],
            ],
        ]],
    ],
];
