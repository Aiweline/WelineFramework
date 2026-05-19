<?php

return [
    'WeShop_Order::frontend::pages::order::list-before' => [
        'name' => __('订单列表页前置内容'),
        'description' => __('在前台订单列表页正文前注入扩展内容。'),
        'doc' => 'frontend/order/list-before.md',
    ],
    'WeShop_Order::frontend::pages::order::list-after' => [
        'name' => __('订单列表页后置内容'),
        'description' => __('在前台订单列表页正文后注入扩展内容。'),
        'doc' => 'frontend/order/list-after.md',
    ],
    'WeShop_Order::frontend::pages::order::view-before' => [
        'name' => __('订单详情页前置内容'),
        'description' => __('在前台订单详情页正文前注入扩展内容。'),
        'doc' => 'frontend/order/view-before.md',
    ],
    'WeShop_Order::frontend::pages::order::view-items' => [
        'name' => __('订单详情商品区内容'),
        'description' => __('在前台订单详情商品区块内注入扩展内容。'),
        'doc' => 'frontend/order/view-items.md',
    ],
    'WeShop_Order::frontend::pages::order::view-after' => [
        'name' => __('订单详情页后置内容'),
        'description' => __('在前台订单详情页正文后注入扩展内容。'),
        'doc' => 'frontend/order/view-after.md',
    ],
];
