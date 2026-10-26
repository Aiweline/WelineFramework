<?php

declare(strict_types=1);

return [
    'name' => 'Weline_Points',
    'version' => '1.1.0',
    'requires' => [
        'Weline_Framework' => '*',
        'Weline_Customer' => '*',
        'Weline_CustomerAsset' => '*',
        'Weline_Eav' => '*',
        'Weline_SystemConfig' => '*',
    ],
    'optional' => [
        'Weline_Currency' => '*',
        'Weline_Order' => '*',
        'Weline_Checkout' => '*',
        'Weline_Payment' => '*',
        'Weline_Product' => '*',
        'Weline_Websites' => '*',
    ],
    'provides' => [
        \Weline\Points\Api\PointsVipFacadeInterface::class
            => \Weline\Points\Service\PointsVipService::class,
        \Weline\Points\Api\PointsDiscountSettlementInterface::class
            => \Weline\Points\Service\PointsDiscountSettlementService::class,
        \Weline\Points\Api\PointsDiscountApplySessionInterface::class
            => \Weline\Points\Service\PointsCheckoutApplySession::class,
        'payment.asset_policy.Weline_Points'
            => \Weline\Points\Service\PointsPaymentAssetPolicyProvider::class,
    ],
];
