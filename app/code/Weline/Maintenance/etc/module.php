<?php

return [
    "name" => 'Weline_Maintenance',
    "version" => '1.0.1',
    "requires" => [
        'Weline_Backend' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        \Weline\Backend\Api\Maintenance\MaintenanceOperationsProviderInterface::class => \Weline\Maintenance\Integration\Backend\MaintenanceOperationsProvider::class,
    ],
];
