<?php

declare(strict_types=1);

return [
    'name' => 'Weline_Affiliate',
    'version' => '1.0.26',
    'requires' => [
        'Weline_Framework' => '*',
        'Weline_Backend' => '*',
        'Weline_Customer' => '*',
        'Weline_I18n' => '*',
        'Weline_SystemConfig' => '*',
    ],
    'optional' => [
        'Weline_Websites' => '*',
        'Weline_Order' => '*',
        'Weline_Checkout' => '*',
        'Weline_Product' => '*',
        'Weline_Cart' => '*',
        'Weline_Social' => '*',
        'Weline_Wishlist' => '*',
        'Weline_Review' => '*',
        'Weline_Widget' => '*',
        'Weline_Theme' => '*',
    ],
];
