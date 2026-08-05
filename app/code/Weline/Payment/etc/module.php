<?php

return [
    "name" => 'Weline_Payment',
    "version" => '1.9.2',
    "requires" => [
        'Weline_Acl' => '*',
        'Weline_Backend' => '*',
        'Weline_Eav' => '*',
        'Weline_Framework' => '*',
        'Weline_Frontend' => '*',
        'Weline_Hook' => '*',
        'Weline_I18n' => '*',
        'Weline_Inventory' => '*',
        'Weline_Queue' => '*',
        'Weline_SystemConfig' => '*',
        'Weline_Theme' => '*',
    ],
    "optional" => [
        'Weline_CustomerAsset' => '*',
        'Weline_Marketing' => '*',
    ],
    "provides" => [
        \Weline\Payment\Api\PaymentFacadeInterface::class => \Weline\Payment\Service\PaymentFacade::class,
        \Weline\Payment\Api\PaymentFacadeV2Interface::class => \Weline\Payment\Service\PaymentFacadeV2::class,
        \Weline\Payment\Api\PaymentRefundFacadeInterface::class => \Weline\Payment\Service\PaymentRefundService::class,
        \Weline\Payment\Api\PaymentAssetFacadeInterface::class => \Weline\Payment\Service\AssetPaymentService::class,
        \Weline\Payment\Api\PaymentEffectOutboxProcessorInterface::class => \Weline\Payment\Service\PaymentEffectOutboxProcessor::class,
        \Weline\Payment\Api\Webhook\WebhookEndpointDirectoryInterface::class => \Weline\Payment\Service\WebhookEndpointDirectory::class,
        \Weline\Payment\Api\Discount\DiscountActionSupportInterface::class => \Weline\Payment\Service\DiscountActionSupportService::class,
    ],
];
