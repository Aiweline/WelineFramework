<?php

declare(strict_types=1);

/**
 * CustomerService 前台部件：页脚帮助中心「联系客服」——打开悬浮聊天。
 * Theme layouts/partials 禁止内嵌本模块 <w:widget>；靠 default_injections / 拖入补空槽。
 */
return [
    'footer-contact-service-link' => [
        'name' => '页脚联系客服链接',
        'description' => '页脚帮助中心扩展槽：联系客服入口；点击打开悬浮客服聊天；默认注入 footer-help-links。',
        'type' => 'footer',
        'code' => 'footer-contact-service-link',
        'area' => 'frontend',
        'template' => 'Weline_CustomerService::templates/frontend/widgets/footer-contact-service-link.phtml',
        'page_layouts' => ['*'],
        'position' => ['footer'],
        'slot' => 'footer-help-links',
        'supports' => [
            'footer-contact-service-link',
            'layout-footer-help-links',
        ],
        'default_injections' => [[
            'layout_type' => 'homepage',
            'slot' => 'footer-help-links',
            'area' => 'footer',
            'sort_order' => 50,
            'required' => true,
            'reason' => '页脚帮助中心默认展示联系客服（打开悬浮聊天）',
            'config' => [
                'label' => '联系客服',
            ],
        ]],
        'params' => [
            'label' => [
                'default' => '联系客服',
                'type' => 'string',
                'label' => '链接文字',
            ],
        ],
    ],
];
