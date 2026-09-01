<?php

declare(strict_types=1);

return [
    'order-notice' => [
        'name' => '订单留言',
        'description' => '迷你购物车底部订单留言输入，会话保存后在结账创建订单时带入。',
        'type' => 'form',
        'code' => 'order-notice',
        'area' => 'frontend',
        'template' => 'Weline_Order::templates/frontend/widgets/order-notice.phtml',
        'page_layouts' => ['mini-cart'],
        'position' => ['footer'],
        'slot' => 'footer-extras',
        'supports' => [
            'layout-mini-cart-footer-extras',
            'order-notice',
            'mini-cart-footer-extras',
            'order',
        ],
        'default_injections' => [[
            'layout_type' => 'mini-cart',
            'layout_option' => 'default',
            'slot' => 'footer-extras',
            'area' => 'footer',
            'sort_order' => 20,
            'required' => true,
            'reason' => '迷你购物车默认提供订单留言输入',
            'config' => [
                'title' => '订单留言',
                'placeholder' => '如有特殊要求请留言',
            ],
        ]],
        'params' => [
            'title' => [
                'default' => '订单留言',
                'type' => 'string',
                'label' => '标题',
            ],
            'placeholder' => [
                'default' => '如有特殊要求请留言',
                'type' => 'string',
                'label' => '占位提示',
            ],
        ],
    ],
];
