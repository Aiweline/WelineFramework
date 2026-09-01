<?php

declare(strict_types=1);

return [
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
            'layout_type' => '*',
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
