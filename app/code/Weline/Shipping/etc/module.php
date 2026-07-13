<?php

return [
    "name" => 'Weline_Shipping',
    "version" => '1.0.0',
    "requires" => [
        'Weline_Backend' => '*',
        'Weline_Customer' => '*',
        'Weline_Framework' => '*',
        'Weline_I18n' => '*',
    ],
    "optional" => [
        'Weline_Frontend' => '*',
        'Weline_Theme' => '*',
    ],
    "provides" => [
        'template_cache_policy.Weline_Shipping' => \Weline\Shipping\Api\View\TemplateCachePolicyProvider::class,
        'view_warmup_contribution.Weline_Shipping' => \Weline\Shipping\Api\View\ViewWarmupContributionProvider::class,
    ],
];
