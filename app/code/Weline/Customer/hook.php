<?php

/**
 * Weline_Customer module hook specification file.
 */
return [
    'Weline_Customer::frontend::account::login::providers' => [
        'name' => __('前台顾客登录扩展区'),
        'description' => __('在顾客登录页注入第三方登录入口等扩展内容。'),
        'doc' => 'frontend/account/login/providers.md',
    ],
    'Weline_Customer::frontend::account::index::subscriptions' => [
        'name' => __('账户首页订阅分区'),
        'description' => __('在顾客账户首页「订阅」分区注入周期性订阅入口等内容。'),
        'doc' => 'frontend/account/index/subscriptions.md',
    ],
    'Weline_Customer::frontend::account::index::orders' => [
        'name' => __('账户首页订单分区'),
        'description' => __('在顾客账户首页“我的订单”分区注入订单列表、订单状态与售后入口等内容。'),
        'doc' => 'frontend/account/index/orders.md',
    ],
];
