<?php

return [
    "name" => 'Weline_Dashboard',
    "version" => '1.0.0',
    "requires" => [
        'Weline_Acl' => '*',
        'Weline_Admin' => '*',
        'Weline_Backend' => '*',
        'Weline_SystemConfig' => '*',
        'Weline_Theme' => '*',
        'Weline_Websites' => '*',
        'Weline_Widget' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        'theme.target_type.dashboard_view' => \Weline\Dashboard\Integration\Theme\DashboardViewTargetTypeProvider::class,
    ],
];
