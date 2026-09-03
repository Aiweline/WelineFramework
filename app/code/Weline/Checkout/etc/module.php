<?php

return [
    "name" => 'Weline_Checkout',
    "version" => '1.4.22',
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
        \Weline\Tax\Api\TaxShadowQuoteSourceInterface::class
            => \Weline\Checkout\Service\CheckoutTaxShadowQuoteSource::class,
    ],
];
