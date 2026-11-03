<?php

return [
    "name" => 'Weline_Order',
    "version" => '2.13.78',
    "requires" => [
        'Weline_Acl' => '*',
        'Weline_Backend' => '*',
        'Weline_Currency' => '*',
        'Weline_Customer' => '*',
        'Weline_FileManager' => '*',
        'Weline_Framework' => '*',
        'Weline_Shipping' => '*',
        'Weline_SystemConfig' => '*',
        'Weline_Inventory' => '*',
        'Weline_Payment' => '*',
        'Weline_Queue' => '*',
        'Weline_Smtp' => '*',
        'Weline_Theme' => '*',
        'Weline_Websites' => '*',
    ],
    "optional" => [
        'Weline_Admin' => '*',
        'Weline_B2B' => '*',
        'Weline_Checkout' => '*',
        'Weline_Widget' => '*',
    ],
    "provides" => [
        \Weline\Order\Api\OrderFacadeInterface::class => \Weline\Order\Service\OrderFacade::class,
        \Weline\Order\Api\OrderPostPaymentHookInterface::class => \Weline\Order\Service\OrderPaidStateHook::class,
        \Weline\Order\Api\RefundAssetReturnCapabilityInterface::class => \Weline\Order\Service\PaymentRefundAssetReturnCapability::class,
        \Weline\Payment\Api\OrderAssetAllocationSnapshotSinkInterface::class => \Weline\Order\Service\OrderAssetAllocationSnapshotService::class,
        \Weline\Order\Api\OrderAssetAllocationSnapshotReaderInterface::class => \Weline\Order\Service\OrderAssetAllocationSnapshotService::class,
        'view_warmup_contribution.Weline_Order' => \Weline\Order\Api\View\ViewWarmupContributionProvider::class,
        'theme.public_route.order' => \Weline\Order\Controller\Router::class,
    ],
];
