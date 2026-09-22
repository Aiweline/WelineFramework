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
        // placement=injection：布局只留空槽；同身份禁止再在成功页 fetch。
        'placement' => 'injection',
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
    'footer-social-login-link' => [
        'name' => '页脚社媒登录链接',
        'description' => '页脚支付与账户扩展槽：社媒登录指南入口；默认注入 footer-payment-account-links。',
        'type' => 'footer',
        'code' => 'footer-social-login-link',
        'area' => 'frontend',
        'template' => 'Weline_Customer::templates/frontend/widgets/footer-social-login-link.phtml',
        'page_layouts' => ['*'],
        'position' => ['footer'],
        'slot' => 'footer-payment-account-links',
        'supports' => [
            'footer-social-login-link',
            'layout-footer-payment-account-links',
        ],
        'default_injections' => [[
            'layout_type' => 'homepage',
            'slot' => 'footer-payment-account-links',
            'area' => 'footer',
            'sort_order' => 5,
            'required' => true,
            'reason' => '页脚支付与账户默认展示社媒登录指南',
            'config' => [
                'label' => '社媒登录',
            ],
        ]],
        'params' => [
            'label' => [
                'default' => '社媒登录',
                'type' => 'string',
                'label' => '链接文字',
            ],
        ],
    ],
    'account-social-login' => [
        'name' => '社媒登录',
        'description' => '顾客登录页 Google / Facebook / Instagram 入口；须统一配置中心填写凭据并激活后展示，部件参数可再单独关闭。',
        'type' => 'form',
        'code' => 'account-social-login',
        'area' => 'frontend',
        'template' => 'Weline_Customer::templates/frontend/widgets/account-social-login.phtml',
        'page_layouts' => ['account'],
        'position' => ['content'],
        'slot' => 'account-login-social-providers',
        'supports' => [
            'account-login-social-providers',
            'layout-account-login-social-providers',
            'social-login',
            'account-social-login',
        ],
        // placement=injection：登录页只留空槽；同身份禁止再旁路 fetch。
        'placement' => 'injection',
        'default_injections' => [
            [
                'layout_type' => 'account/login',
                'layout_option' => 'default',
                'slot' => 'account-login-social-providers',
                'area' => 'content',
                'sort_order' => 0,
                'required' => true,
                'reason' => '登录表单社媒区默认注入 Google/Facebook/Instagram 应用部件',
                'config' => [
                    'enable_google' => true,
                    'enable_facebook' => true,
                    'enable_instagram' => true,
                ],
            ],
            [
                'layout_type' => 'account.auth',
                'layout_option' => 'default',
                'slot' => 'account-login-social-providers',
                'area' => 'content',
                'sort_order' => 0,
                'required' => true,
                'reason' => '遗留 account.auth 壳默认注入社媒登录',
                'config' => [
                    'enable_google' => true,
                    'enable_facebook' => true,
                    'enable_instagram' => true,
                ],
            ],
        ],
        'params' => [
            'enable_google' => [
                'default' => true,
                'type' => 'bool',
                'label' => '启用 Google',
            ],
            'enable_facebook' => [
                'default' => true,
                'type' => 'bool',
                'label' => '启用 Facebook',
            ],
            'enable_instagram' => [
                'default' => true,
                'type' => 'bool',
                'label' => '启用 Instagram',
            ],
        ],
    ],
];
