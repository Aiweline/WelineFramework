<?php

return [
    'WeShop_Order::frontend::pages::order::list-before' => [
        'name' => __('Order list content before'),
        'description' => __('Inject content before the storefront order list page body.'),
        'doc' => 'frontend/order/list-before.md',
    ],
    'WeShop_Order::frontend::pages::order::list-after' => [
        'name' => __('Order list content after'),
        'description' => __('Inject content after the storefront order list page body.'),
        'doc' => 'frontend/order/list-after.md',
    ],
    'WeShop_Order::frontend::pages::order::view-before' => [
        'name' => __('Order detail content before'),
        'description' => __('Inject content before the storefront order detail page body.'),
        'doc' => 'frontend/order/view-before.md',
    ],
    'WeShop_Order::frontend::pages::order::view-items' => [
        'name' => __('Order detail items content'),
        'description' => __('Inject content inside the storefront order detail items section.'),
        'doc' => 'frontend/order/view-items.md',
    ],
    'WeShop_Order::frontend::pages::order::view-after' => [
        'name' => __('Order detail content after'),
        'description' => __('Inject content after the storefront order detail page body.'),
        'doc' => 'frontend/order/view-after.md',
    ],
];
