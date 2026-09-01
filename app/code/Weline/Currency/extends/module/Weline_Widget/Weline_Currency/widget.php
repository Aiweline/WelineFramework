<?php

declare(strict_types=1);

return [
    'footer-currency-rates-link' => [
        'name' => '页脚货币与汇率链接',
        'description' => '页脚支付与账户扩展槽：货币与汇率政策入口；默认注入 footer-payment-account-links。',
        'type' => 'footer',
        'code' => 'footer-currency-rates-link',
        'area' => 'frontend',
        'template' => 'Weline_Currency::templates/frontend/widgets/footer-currency-rates-link.phtml',
        'page_layouts' => ['*'],
        'position' => ['footer'],
        'slot' => 'footer-payment-account-links',
        'supports' => [
            'footer-currency-rates-link',
            'layout-footer-payment-account-links',
        ],
        'default_injections' => [[
            'layout_type' => '*',
            'slot' => 'footer-payment-account-links',
            'area' => 'footer',
            'sort_order' => 20,
            'required' => true,
            'reason' => '页脚支付与账户默认展示货币与汇率',
            'config' => [
                'label' => '货币与汇率',
            ],
        ]],
        'params' => [
            'label' => [
                'default' => '货币与汇率',
                'type' => 'string',
                'label' => '链接文字',
            ],
        ],
    ],
];
