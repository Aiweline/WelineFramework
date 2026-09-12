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
];
