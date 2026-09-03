<?php

declare(strict_types=1);

return [
    'checkout-coupon' => [
        'name' => '结账优惠券',
        'description' => '万能优惠规则：结账页优惠券输入与应用摘要。',
        'type' => 'content',
        'code' => 'checkout-coupon',
        'area' => 'frontend',
        'template' => 'Weline_Marketing::templates/frontend/widgets/checkout-coupon.phtml',
        'page_layouts' => ['checkout'],
        'position' => ['summary'],
        'slot' => 'checkout-summary-discount',
        'supports' => ['checkout-summary-discount', 'checkout-coupon', 'marketing'],
        'default_injections' => [[
            'layout_type' => 'checkout',
            'layout_option' => 'default',
            'slot' => 'checkout-summary-discount',
            'area' => 'content',
            'sort_order' => 10,
            'required' => true,
            'reason' => '结账摘要默认展示优惠券部件（Amazon 默认样式）',
            'config' => [
                'title' => '优惠券',
            ],
        ]],
        'params' => [
            'title' => [
                'default' => '优惠券',
                'type' => 'string',
                'label' => '标题',
            ],
        ],
    ],
    'mini-cart-coupon' => [
        'name' => '迷你购物车优惠券',
        'description' => '迷你购物车底部优惠券输入，会话绑定后在结账报价时生效。',
        'type' => 'content',
        'code' => 'mini-cart-coupon',
        'area' => 'frontend',
        'template' => 'Weline_Marketing::templates/frontend/widgets/mini-cart-coupon.phtml',
        'page_layouts' => ['mini-cart'],
        'position' => ['footer'],
        'slot' => 'footer-extras',
        'supports' => [
            'layout-mini-cart-footer-extras',
            'mini-cart-coupon',
            'mini-cart-footer-extras',
            'marketing',
        ],
        'default_injections' => [[
            'layout_type' => 'mini-cart',
            'layout_option' => 'default',
            'slot' => 'footer-extras',
            'area' => 'footer',
            'sort_order' => 10,
            'required' => true,
            'reason' => '迷你购物车默认提供优惠券输入',
            'config' => [
                'title' => '优惠券',
                'compact' => true,
            ],
        ]],
        'params' => [
            'title' => [
                'default' => '优惠券',
                'type' => 'string',
                'label' => '标题',
            ],
            'compact' => [
                'default' => true,
                'type' => 'bool',
                'label' => '紧凑样式',
            ],
        ],
    ],
    'cart-coupon' => [
        'name' => '购物车优惠券',
        'description' => '购物车页订单摘要优惠券输入，会话绑定后在结账报价时生效。',
        'type' => 'content',
        'code' => 'cart-coupon',
        'area' => 'frontend',
        'template' => 'Weline_Marketing::templates/frontend/widgets/checkout-coupon.phtml',
        'page_layouts' => ['cart'],
        'position' => ['summary'],
        'slot' => 'cart-summary-discount',
        'supports' => [
            'cart-summary-discount',
            'cart-coupon',
            'checkout-coupon',
            'marketing',
        ],
        'default_injections' => [[
            'layout_type' => 'cart',
            'layout_option' => 'default',
            'slot' => 'cart-summary-discount',
            'area' => 'content',
            'sort_order' => 10,
            'required' => true,
            'reason' => '购物车摘要默认展示优惠券部件（Amazon 默认样式）',
            'config' => [
                'title' => '优惠券',
            ],
        ]],
        'params' => [
            'title' => [
                'default' => '优惠券',
                'type' => 'string',
                'label' => '标题',
            ],
        ],
    ],
    'footer-campaign-link' => [
        'name' => '页脚活动链接',
        'description' => '页脚支付与账户扩展槽：活动入口（跳转 /promotion/deals）；默认注入 footer-payment-account-links。',
        'type' => 'footer',
        'code' => 'footer-campaign-link',
        'area' => 'frontend',
        'template' => 'Weline_Marketing::templates/frontend/widgets/footer-campaign-link.phtml',
        'page_layouts' => ['*'],
        'position' => ['footer'],
        'slot' => 'footer-payment-account-links',
        'supports' => [
            'footer-campaign-link',
            'layout-footer-payment-account-links',
        ],
        'default_injections' => [[
            'layout_type' => 'homepage',
            'slot' => 'footer-payment-account-links',
            'area' => 'footer',
            'sort_order' => 10,
            'required' => true,
            'reason' => '页脚支付与账户默认展示活动入口',
            'config' => [
                'label' => '活动',
            ],
        ]],
        'params' => [
            'label' => [
                'default' => '活动',
                'type' => 'string',
                'label' => '链接文字',
            ],
        ],
    ],
];
