<?php

return [
    "name" => 'Weline_Checkout',
    "version" => '1.5.46',
    "requires" => [
        'Weline_Backend' => '*',
        'Weline_Cart' => '*',
        'Weline_Customer' => '*',
        'Weline_Framework' => '*',
        'Weline_I18n' => '*',
        'Weline_Inventory' => '*',
        'Weline_Order' => '*',
        'Weline_Payment' => '*',
        'Weline_Shipping' => '*',
    ],
    "optional" => [
        'Weline_Tax' => '*',
        'Weline_Marketing' => '*',
    ],
    "provides" => [
        \Weline\Checkout\Api\CheckoutSessionStoreInterface::class
            => \Weline\Checkout\Service\OrmCheckoutSessionStore::class,
        \Weline\Checkout\Api\ContinuePayBindingInterface::class
            => \Weline\Checkout\Service\ContinuePayBindingService::class,
        \Weline\Tax\Api\TaxShadowQuoteSourceInterface::class
            => \Weline\Checkout\Service\CheckoutTaxShadowQuoteSource::class,
        'payment.express_address_sink.Weline_Checkout'
            => \Weline\Checkout\Service\CheckoutPaymentExpressAddressSink::class,
    ],
];
