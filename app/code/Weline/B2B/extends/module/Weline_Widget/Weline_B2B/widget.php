<?php

declare(strict_types=1);

return [
    'b2b-checkout-credit' => [
        'name' => '批发信用',
        'description' => '结账 / 迷你车 / 购物车：批发信用抵扣本期应付（资产类，非营销券；批发订单说明在结账顶栏）。',
        'type' => 'content',
        'code' => 'b2b-checkout-credit',
        'area' => 'frontend',
        'template' => 'Weline_B2B::templates/frontend/widgets/checkout-tob-deposit-note.phtml',
        'page_layouts' => ['checkout', 'mini-cart', 'cart'],
        'position' => ['summary', 'content', 'footer'],
        'slot' => 'checkout-summary-credit',
        'supports' => [
            'checkout-summary-credit',
            'cart-summary-credit',
            'footer-extras',
            'layout-mini-cart-footer-extras',
            'mini-cart-footer-extras',
            'b2b-checkout-credit',
            'b2b-deposit-note',
            'b2b',
        ],
        'default_injections' => [
            [
                'layout_type' => 'checkout',
                'layout_option' => 'default',
                'slot' => 'checkout-summary-credit',
                'area' => 'content',
                'sort_order' => 15,
                'required' => true,
                'reason' => '批发结账时展示批发信用页签（零售隐藏；站点未开启则模板不渲染）',
                'config' => [
                    'title' => '批发信用',
                    'surface' => 'checkout',
                ],
            ],
            [
                'layout_type' => 'mini-cart',
                'layout_option' => 'default',
                'slot' => 'footer-extras',
                'area' => 'footer',
                'sort_order' => 15,
                'required' => true,
                'reason' => '批发迷你车默认展示批发信用（站点开启时；零售隐藏）',
                'config' => [
                    'title' => '批发信用',
                    'surface' => 'mini-cart',
                    'compact' => true,
                ],
            ],
            [
                'layout_type' => 'cart',
                'layout_option' => 'default',
                'slot' => 'cart-summary-credit',
                'area' => 'content',
                'sort_order' => 15,
                'required' => true,
                'reason' => '批发购物车摘要默认展示批发信用（站点开启时；零售隐藏）',
                'config' => [
                    'title' => '批发信用',
                    'surface' => 'cart',
                ],
            ],
        ],
        'params' => [
            'title' => [
                'default' => '批发信用',
                'type' => 'string',
                'label' => '页签标题',
            ],
            'surface' => [
                'default' => 'checkout',
                'type' => 'string',
                'label' => '表面标识',
            ],
            'compact' => [
                'default' => false,
                'type' => 'bool',
                'label' => '紧凑样式',
            ],
        ],
    ],
    'b2b-backend-order-chat' => [
        'name' => '批发订单沟通',
        'description' => '订单后台详情：B2B 定金/尾款协商消息；默认注入 backend-order-b2b-chat。',
        'type' => 'content',
        'code' => 'b2b-backend-order-chat',
        'area' => 'backend',
        'template' => 'Weline_B2B::templates/Backend/widgets/backend-order-chat.phtml',
        'page_layouts' => ['backend-order-view'],
        'position' => ['content'],
        'slot' => 'backend-order-b2b-chat',
        'supports' => [
            'backend-order-b2b-chat',
            'b2b-order-chat',
            'b2b',
        ],
        'default_injections' => [[
            'layout_type' => 'backend-order-view',
            'layout_option' => 'default',
            'slot' => 'backend-order-b2b-chat',
            'area' => 'content',
            'sort_order' => 20,
            'required' => true,
            'reason' => '批发/挂单订单详情默认展示商家侧订单沟通',
            'config' => [
                'title' => '订单沟通',
            ],
        ]],
        'params' => [
            'title' => [
                'default' => '订单沟通',
                'type' => 'string',
                'label' => '标题',
            ],
        ],
    ],
];
