<?php

declare(strict_types=1);

return [
    'name' => 'Weline_Inventory',
    'version' => '2.5.5',
    'requires' => [
        'Weline_Framework' => '*',
        'Weline_Websites' => '*',
    ],
    'optional' => [
        'Weline_Product' => '*',
    ],
    'provides' => [
        \Weline\Inventory\Api\InventoryCapabilityInterface::class
            => \Weline\Inventory\Service\InventoryService::class,
        \Weline\Inventory\Api\InventoryReservationCommitCapabilityInterface::class
            => \Weline\Inventory\Service\InventoryService::class,
        \Weline\Inventory\Api\InventoryRefundCapabilityInterface::class
            => \Weline\Inventory\Service\InventoryService::class,
        \Weline\Inventory\Api\DefaultWarehouseResolverInterface::class
            => \Weline\Inventory\Service\DefaultLogicalWarehouseResolver::class,
        \Weline\Inventory\Api\WarehouseInventoryCapabilityInterface::class
            => \Weline\Inventory\Service\WarehouseInventoryService::class,
    ],
];
