<?php

return [
    "name" => 'Weline_Marketing',
    "version" => '1.3.3',
    "requires" => [
        'Weline_Backend' => '*',
        'Weline_Currency' => '*',
        'Weline_Framework' => '*',
        'Weline_I18n' => '*',
    ],
    "optional" => [
        'Weline_Theme' => '*',
        'Weline_Widget' => '*',
        'Weline_Order' => '*',
        'Weline_Checkout' => '*',
        'Weline_Cart' => '*',
        'Weline_Customer' => '*',
        'Weline_Smtp' => '*',
        'Weline_Cron' => '*',
    ],
    "provides" => [
        \Weline\Marketing\Api\Rule\ActionCatalogInterface::class => \Weline\Marketing\Service\ActionCatalog::class,
        \Weline\Marketing\Api\Quote\DiscountQuoteServiceInterface::class => \Weline\Marketing\Service\DiscountQuoteService::class,
        \Weline\Marketing\Api\Deal\ExternalDealDiscountProviderInterface::class => \Weline\Marketing\Service\ExternalDealDiscountProvider::class,
        \Weline\Marketing\Api\Coupon\RandomCouponCampaignProviderInterface::class => \Weline\Marketing\Service\RandomCouponCampaignProvider::class,
    ],
];
