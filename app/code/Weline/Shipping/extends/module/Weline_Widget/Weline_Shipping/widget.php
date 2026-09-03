<?php

declare(strict_types=1);

return [
    'checkout-shipping-address' => [
        'name' => '结账收货地址',
        'description' => '结账页收货信息：已存地址选择标签 + 主题地址级联。',
        'type' => 'content',
        'code' => 'checkout-shipping-address',
        'area' => 'frontend',
        'template' => 'Weline_Shipping::templates/frontend/widgets/checkout-shipping-address.phtml',
        'page_layouts' => ['checkout'],
        'position' => ['content'],
        'slot' => 'checkout-shipping-address',
        'supports' => [
            'checkout-shipping-address',
            'shipping-address',
            'delivery-address',
            'address',
        ],
        'default_injections' => [[
            'layout_type' => 'checkout',
            'layout_option' => 'default',
            'slot' => 'checkout-shipping-address',
            'area' => 'content',
            'sort_order' => 10,
            'required' => true,
            'reason' => '结账收货信息默认由 Shipping 地址部件提供（theme:address + 已存地址选择）',
            'config' => [
                'title' => '收货地址',
            ],
        ]],
        'params' => [
            'title' => [
                'default' => '收货地址',
                'type' => 'string',
                'label' => '标题',
            ],
        ],
    ],
    'footer-shipping-info-link' => [
        'name' => '页脚配送说明链接',
        'description' => '页脚帮助中心扩展槽：配送说明入口；默认注入 footer-help-links。',
        'type' => 'footer',
        'code' => 'footer-shipping-info-link',
        'area' => 'frontend',
        'template' => 'Weline_Shipping::templates/frontend/widgets/footer-shipping-info-link.phtml',
        'page_layouts' => ['*'],
        'position' => ['footer'],
        'slot' => 'footer-help-links',
        'supports' => [
            'footer-shipping-info-link',
            'layout-footer-help-links',
        ],
        'default_injections' => [[
            'layout_type' => 'homepage',
            'slot' => 'footer-help-links',
            'area' => 'footer',
            'sort_order' => 20,
            'required' => true,
            'reason' => '页脚帮助中心默认展示配送说明',
            'config' => [
                'label' => '配送说明',
            ],
        ]],
        'params' => [
            'label' => [
                'default' => '配送说明',
                'type' => 'string',
                'label' => '链接文字',
            ],
        ],
    ],
    'footer-returns-policy-link' => [
        'name' => '页脚退换政策链接',
        'description' => '页脚帮助中心扩展槽：退换货政策入口；默认注入 footer-help-links。',
        'type' => 'footer',
        'code' => 'footer-returns-policy-link',
        'area' => 'frontend',
        'template' => 'Weline_Shipping::templates/frontend/widgets/footer-returns-policy-link.phtml',
        'page_layouts' => ['*'],
        'position' => ['footer'],
        'slot' => 'footer-help-links',
        'supports' => [
            'footer-returns-policy-link',
            'layout-footer-help-links',
        ],
        'default_injections' => [[
            'layout_type' => 'homepage',
            'slot' => 'footer-help-links',
            'area' => 'footer',
            'sort_order' => 30,
            'required' => true,
            'reason' => '页脚帮助中心默认展示退换政策',
            'config' => [
                'label' => '退换政策',
            ],
        ]],
        'params' => [
            'label' => [
                'default' => '退换政策',
                'type' => 'string',
                'label' => '链接文字',
            ],
        ],
    ],
];
