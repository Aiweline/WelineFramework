<?php

declare(strict_types=1);

return [
    'name' => 'Weline_Inventory',
    'version' => '2.5.23',
    'requires' => [
        'Weline_Framework' => '*',
        'Weline_Websites' => '*',
        'Weline_Theme' => '*',
        'Weline_I18n' => '*',
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
        \Weline\Inventory\Api\InventoryCatalogMaintenanceInterface::class
            => \Weline\Inventory\Service\InventoryService::class,
        \Weline\Inventory\Api\DefaultWarehouseResolverInterface::class
            => \Weline\Inventory\Service\DefaultLogicalWarehouseResolver::class,
        \Weline\Inventory\Api\WarehouseInventoryCapabilityInterface::class
            => \Weline\Inventory\Service\WarehouseInventoryService::class,
        \Weline\Inventory\Api\FulfillmentSplitPlanInterface::class
            => \Weline\Inventory\Service\FulfillmentSplitPlanService::class,
    ],
];
