<?php

return [
    "name" => 'Weline_Marketing',
    "version" => '1.2.5',
    "requires" => [
        'Weline_Backend' => '*',
        'Weline_Framework' => '*',
        'Weline_I18n' => '*',
    ],
    "optional" => [
        'Weline_Theme' => '*',
        'Weline_Widget' => '*',
    ],
    "provides" => [
        \Weline\Marketing\Api\Rule\ActionCatalogInterface::class => \Weline\Marketing\Service\ActionCatalog::class,
        \Weline\Marketing\Api\Quote\DiscountQuoteServiceInterface::class => \Weline\Marketing\Service\DiscountQuoteService::class,
        \Weline\Marketing\Api\Deal\ExternalDealDiscountProviderInterface::class => \Weline\Marketing\Service\ExternalDealDiscountProvider::class,
        \Weline\Marketing\Api\Coupon\RandomCouponCampaignProviderInterface::class => \Weline\Marketing\Service\RandomCouponCampaignProvider::class,
    ],
];
