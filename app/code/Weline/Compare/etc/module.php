<?php

declare(strict_types=1);

return [
    'name' => 'Weline_Compare',
    'version' => '1.0.4',
    'requires' => [
        'Weline_Framework' => '*',
        'Weline_Eav' => '*',
    ],
    'optional' => [
        'Weline_Product' => '*',
        'Weline_Wishlist' => '*',
        'Weline_Cart' => '*',
    ],
    'provides' => [
        'template_cache_policy.Weline_Compare' => \Weline\Compare\Api\View\TemplateCachePolicyProvider::class,
    ],
];
