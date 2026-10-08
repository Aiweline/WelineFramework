<?php

declare(strict_types=1);

return [
    'name' => 'Weline_Promotion',
    'version' => '1.1.33',
    'requires' => [
        'Weline_Framework' => '*',
        'Weline_Backend' => '*',
        'Weline_Theme' => '*',
        'Weline_I18n' => '*',
    ],
    'optional' => [
        'Weline_Marketing' => '*',
        'Weline_Product' => '*',
        'Weline_Websites' => '*',
        'Weline_Report' => '*',
        'Weline_CustomerService' => '*',
        'Weline_Seo' => '*',
        'Weline_Widget' => '*',
    ],
    'provides' => [
        'schedule_window_utc_migration.Weline_Promotion'
            => \Weline\Promotion\Api\Runtime\ScheduleWindowUtcMigrationContribution::class,
    ],
];
