<?php

return [
    "name" => 'Weline_Checkout',
    "version" => '1.5.116',
    "requires" => [
        'Weline_Backend' => '*',
        'Weline_Cart' => '*',
        'Weline_Captcha' => '*',
        'Weline_Customer' => '*',
        'Weline_Dropship' => '*',
        'Weline_Framework' => '*',
        'Weline_I18n' => '*',
        'Weline_Inventory' => '*',
        'Weline_Order' => '*',
        'Weline_Payment' => '*',
        'Weline_Shipping' => '*',
        'Weline_Theme' => '*',
        'Weline_Websites' => '*',
    ],
    "optional" => [
        'Weline_Tax' => '*',
        'Weline_Marketing' => '*',
        'Weline_Product' => '*',
        'Weline_Points' => '*',
        'Weline_Visitor' => '*',
        'Weline_Currency' => '*',
        'Weline_B2B' => '*',
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
