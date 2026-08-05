<?php

declare(strict_types=1);

return [
    'name' => 'Weline_Subscription',
    'version' => '2.3.0',
    'requires' => [
        'Weline_Backend' => '*',
        'Weline_Framework' => '*',
        'Weline_SystemConfig' => '*',
        'Weline_Websites' => '*',
        'Weline_Order' => '*',
        'Weline_Payment' => '*',
        'Weline_Queue' => '*',
    ],
    'optional' => [
        'Weline_Customer' => '*',
        'Weline_Product' => '*',
    ],
    'provides' => [
        \Weline\Subscription\Api\SubscriptionFacadeInterface::class
            => \Weline\Subscription\Service\SubscriptionService::class,
        \Weline\Subscription\Api\SubscriptionOrderPortInterface::class
            => \Weline\Subscription\Service\OrderFacadeSubscriptionOrderPort::class,
        \Weline\Subscription\Api\SubscriptionPaymentPortInterface::class
            => \Weline\Subscription\Service\PaymentFacadeSubscriptionPaymentPort::class,
    ],
];
