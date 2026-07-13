<?php

return [
    "name" => 'Weline_Payment',
    "version" => '1.0.2',
    "requires" => [
        'Weline_Backend' => '*',
        'Weline_Eav' => '*',
        'Weline_Framework' => '*',
        'Weline_Frontend' => '*',
        'Weline_Hook' => '*',
        'Weline_I18n' => '*',
        'Weline_SystemConfig' => '*',
        'Weline_Theme' => '*',
    ],
    "optional" => [
        'Weline_Marketing' => '*',
    ],
    "provides" => [
        \Weline\Payment\Api\PaymentFacadeInterface::class => \Weline\Payment\Service\PaymentFacade::class,
        \Weline\Payment\Api\Discount\DiscountActionSupportInterface::class => \Weline\Payment\Service\DiscountActionSupportService::class,
    ],
];
