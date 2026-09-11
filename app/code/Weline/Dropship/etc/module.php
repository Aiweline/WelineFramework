<?php

return [
    'name' => 'Weline_Dropship',
    'version' => '1.0.9',
    'requires' => [
        'Weline_SystemConfig',
        'Weline_Product',
        'Weline_Order',
        'Weline_Inventory',
        'Weline_Queue',
        'Weline_Cron',
        'Weline_Backend',
        'Weline_Websites',
    ],
    'optional' => [
        'Weline_CjDropshipping',
        'Weline_Dashboard',
        'Weline_Widget',
        'Weline_Shipping',
    ],
    'provides' => [
        \Weline\Dropship\Api\DropshipFacadeInterface::class => \Weline\Dropship\Service\DropshipFacade::class,
        \Weline\Dropship\Api\DropshipChannelManagerInterface::class => \Weline\Dropship\Service\DropshipChannelManager::class,
    ],
];
