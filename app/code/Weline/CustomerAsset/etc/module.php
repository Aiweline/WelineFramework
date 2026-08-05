<?php

declare(strict_types=1);

return [
    'name' => 'Weline_CustomerAsset',
    'version' => '1.3.0',
    'requires' => [
        'Weline_Backend' => '*',
        'Weline_Framework' => '*',
        'Weline_SystemConfig' => '*',
        'Weline_Websites' => '*',
    ],
    'optional' => [
        'Weline_Customer' => '*',
        'Weline_Payment' => '*',
        'Weline_Order' => '*',
    ],
    'provides' => [
        \Weline\CustomerAsset\Api\CustomerAssetFacadeInterface::class
            => \Weline\CustomerAsset\Service\CustomerAssetService::class,
        'view_warmup_contribution.Weline_CustomerAsset'
            => \Weline\CustomerAsset\Api\View\ViewWarmupContributionProvider::class,
    ],
];
