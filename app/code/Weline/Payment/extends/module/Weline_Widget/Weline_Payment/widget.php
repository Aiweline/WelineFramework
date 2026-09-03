<?php

declare(strict_types=1);

/**
 * Payment 部件：非 Theme 业务部件靠 default_injections / Hook 补空槽，禁止 Order 布局内嵌支付表。
 */
return [
    'checkout-express-payment' => [
        'name' => '结账快捷支付',
        'description' => '结账页快捷支付（Shopify 布局 + Amazon 风格）：默认注入 PayPal 快捷支付；部件开关控制是否展示。',
        'type' => 'content',
        'code' => 'checkout-express-payment',
        'area' => 'frontend',
        'template' => 'Weline_Payment::templates/frontend/widgets/checkout-express-payment.phtml',
        'page_layouts' => ['checkout'],
        'position' => ['content'],
        'slot' => 'checkout-express-payment',
        'supports' => [
            'checkout-express-payment',
            'express-checkout',
            'express-payment',
            'payment',
        ],
        'default_injections' => [[
            'layout_type' => 'checkout',
            'layout_option' => 'default',
            'slot' => 'checkout-express-payment',
            'area' => 'content',
            'sort_order' => 10,
            'required' => true,
            'reason' => '结账页提示条下方默认由万能支付提供快捷支付（PayPal）',
            'config' => [
                'enabled' => true,
                'title' => '快捷支付',
                'method_code' => 'paypal',
            ],
        ]],
        'params' => [
            'enabled' => [
                'default' => true,
                'type' => 'bool',
                'label' => '展示快捷支付',
            ],
            'title' => [
                'default' => '快捷支付',
                'type' => 'string',
                'label' => '标题',
            ],
            'method_code' => [
                'default' => 'paypal',
                'type' => 'string',
                'label' => '支付方式代码',
            ],
            'logo' => [
                'default' => '',
                'type' => 'image',
                'label' => '快捷支付 Logo',
                'description' => '覆盖默认 PayPal 快捷支付 logo；留空则使用系统配置或默认资源。',
            ],
        ],
    ],
    'backend-order-payment-records' => [
        'name' => '订单支付记录',
        'description' => '订单后台详情支付记录：从 PaymentAttempt 投影；默认注入 backend-order-payment-records。',
        'type' => 'content',
        'code' => 'backend-order-payment-records',
        'area' => 'backend',
        'template' => 'Weline_Payment::templates/Backend/widgets/backend-order-payment-records.phtml',
        'page_layouts' => ['backend-order-view'],
        'position' => ['content'],
        'slot' => 'backend-order-payment-records',
        'supports' => [
            'backend-order-payment-records',
            'payment-records',
            'payment',
        ],
        'default_injections' => [[
            'layout_type' => 'backend-order-view',
            'layout_option' => 'default',
            'slot' => 'backend-order-payment-records',
            'area' => 'content',
            'sort_order' => 10,
            'required' => true,
            'reason' => '订单详情支付记录默认由万能支付 Attempt 部件提供',
            'config' => [
                'title' => '支付记录',
            ],
        ]],
        'params' => [
            'title' => [
                'default' => '支付记录',
                'type' => 'string',
                'label' => '标题',
            ],
        ],
    ],
    'footer-payment-methods-link' => [
        'name' => '页脚支付方式链接',
        'description' => '页脚支付与账户扩展槽：支付方式指南入口；默认注入 footer-payment-account-links。',
        'type' => 'footer',
        'code' => 'footer-payment-methods-link',
        'area' => 'frontend',
        'template' => 'Weline_Payment::templates/frontend/widgets/footer-payment-methods-link.phtml',
        'page_layouts' => ['*'],
        'position' => ['footer'],
        'slot' => 'footer-payment-account-links',
        'supports' => [
            'footer-payment-methods-link',
            'layout-footer-payment-account-links',
        ],
        'default_injections' => [[
            'layout_type' => 'homepage',
            'slot' => 'footer-payment-account-links',
            'area' => 'footer',
            'sort_order' => 0,
            'required' => true,
            'reason' => '页脚支付与账户默认展示支付方式指南',
            'config' => [
                'label' => '支付方式',
            ],
        ]],
        'params' => [
            'label' => [
                'default' => '支付方式',
                'type' => 'string',
                'label' => '链接文字',
            ],
        ],
    ],
];
