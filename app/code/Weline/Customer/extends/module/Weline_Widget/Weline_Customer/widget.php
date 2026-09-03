<?php

declare(strict_types=1);

/**
 * Customer storefront widgets. Theme layouts must not hard-code these widgets;
 * default_injections fill empty slots.
 */
return [
    'footer-my-account-link' => [
        'name' => '页脚我的账户链接',
        'description' => '页脚帮助中心扩展槽：顾客账户入口；默认注入 footer-help-links。',
        'type' => 'footer',
        'code' => 'footer-my-account-link',
        'area' => 'frontend',
        'template' => 'Weline_Customer::templates/frontend/widgets/footer-my-account-link.phtml',
        'page_layouts' => ['*'],
        'position' => ['footer'],
        'slot' => 'footer-help-links',
        'supports' => [
            'footer-my-account-link',
            'layout-footer-help-links',
        ],
        'default_injections' => [[
            'layout_type' => 'homepage',
            'slot' => 'footer-help-links',
            'area' => 'footer',
            'sort_order' => 0,
            'required' => true,
            'reason' => '页脚帮助中心默认展示我的账户',
            'config' => [
                'label' => '我的账户',
            ],
        ]],
        'params' => [
            'label' => [
                'default' => '我的账户',
                'type' => 'string',
                'label' => '链接文字',
            ],
        ],
    ],
    'footer-my-orders-link' => [
        'name' => '页脚我的订单链接',
        'description' => '页脚帮助中心扩展槽：账户订单分区入口；默认注入 footer-help-links。',
        'type' => 'footer',
        'code' => 'footer-my-orders-link',
        'area' => 'frontend',
        'template' => 'Weline_Customer::templates/frontend/widgets/footer-my-orders-link.phtml',
        'page_layouts' => ['*'],
        'position' => ['footer'],
        'slot' => 'footer-help-links',
        'supports' => [
            'footer-my-orders-link',
            'layout-footer-help-links',
        ],
        'default_injections' => [[
            'layout_type' => 'homepage',
            'slot' => 'footer-help-links',
            'area' => 'footer',
            'sort_order' => 10,
            'required' => true,
            'reason' => '页脚帮助中心默认展示我的订单',
            'config' => [
                'label' => '我的订单',
            ],
        ]],
        'params' => [
            'label' => [
                'default' => '我的订单',
                'type' => 'string',
                'label' => '链接文字',
            ],
        ],
    ],
    'checkout-success-guest-convert' => [
        'name' => '结账成功访客转化',
        'description' => '结账成功页访客转化：新邮箱建户登录并强制设密，已有邮箱引导登录；订单已绑定则不渲染。',
        'type' => 'content',
        'code' => 'checkout-success-guest-convert',
        'area' => 'frontend',
        'template' => 'Weline_Customer::templates/frontend/widgets/checkout-success-guest-convert.phtml',
        'page_layouts' => ['checkout'],
        'position' => ['content'],
        'slot' => 'checkout-success-guest-account',
        'supports' => [
            'checkout-success-guest-account',
            'guest-account-convert',
            'layout-checkout-success-guest-account',
            'checkout-success-guest-convert',
        ],
        'default_injections' => [[
            'layout_type' => 'checkout',
            'layout_option' => 'default',
            'slot' => 'checkout-success-guest-account',
            'area' => 'content',
            'sort_order' => 0,
            'required' => true,
            'reason' => '结账成功页默认提供访客账户转化入口（Customer 应用部件）',
            'config' => [
                'convert_label' => '登录并保存订单',
                'login_label' => '去登录',
            ],
        ]],
        'params' => [
            'convert_label' => [
                'default' => '登录并保存订单',
                'type' => 'string',
                'label' => '转化按钮文案',
            ],
            'login_label' => [
                'default' => '去登录',
                'type' => 'string',
                'label' => '已有账户登录文案',
            ],
        ],
    ],
];
