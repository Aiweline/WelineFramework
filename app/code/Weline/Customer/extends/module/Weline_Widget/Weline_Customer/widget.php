<?php

declare(strict_types=1);

/**
 * Customer 前台部件：页脚帮助中心扩展（我的账户 / 我的订单）。
 * Theme layouts/partials 禁止内嵌本模块 <w:widget>；靠 default_injections / 拖入补空槽。
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
            'layout_type' => '*',
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
            'layout_type' => '*',
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
];
