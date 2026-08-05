<?php

return [
    "name" => 'Weline_Order',
    "version" => '2.12.4',
    "requires" => [
        'Weline_Acl' => '*',
        'Weline_Backend' => '*',
        'Weline_Customer' => '*',
        'Weline_Framework' => '*',
        'Weline_Inventory' => '*',
        'Weline_Payment' => '*',
        'Weline_Queue' => '*',
        'Weline_Websites' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        \Weline\Order\Api\OrderFacadeInterface::class => \Weline\Order\Service\OrderFacade::class,
        \Weline\Order\Api\OrderPostPaymentHookInterface::class => \Weline\Order\Service\NoopOrderPostPaymentHook::class,
        \Weline\Order\Api\RefundAssetReturnCapabilityInterface::class => \Weline\Order\Service\PaymentRefundAssetReturnCapability::class,
        \Weline\Payment\Api\OrderAssetAllocationSnapshotSinkInterface::class => \Weline\Order\Service\OrderAssetAllocationSnapshotService::class,
        'view_warmup_contribution.Weline_Order' => \Weline\Order\Api\View\ViewWarmupContributionProvider::class,
    ],
];
