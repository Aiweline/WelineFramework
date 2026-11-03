<?php

return [
    "name" => 'Weline_Maintenance',
    "version" => '1.1.17',
    "requires" => [
        'Weline_Backend' => '*',
        'Weline_SystemConfig' => '*',
        'Weline_Theme' => '*',
        'Weline_Websites' => '*',
        'Weline_Marketing' => '>=1.1.4',
    ],
    "optional" => [
        'Weline_I18n' => '*',
    ],
    "provides" => [
        \Weline\Backend\Api\Maintenance\MaintenanceOperationsProviderInterface::class => \Weline\Maintenance\Integration\Backend\MaintenanceOperationsProvider::class,
    ],
];
