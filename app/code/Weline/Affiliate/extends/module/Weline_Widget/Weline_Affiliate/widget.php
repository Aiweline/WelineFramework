<?php

declare(strict_types=1);

return [
    'affiliate-center' => [
        'name' => '分销中心',
        'description' => '顾客账户中心分销摘要、推广链接与佣金工作台。',
        'type' => 'marketing',
        'code' => 'affiliate-center',
        'area' => 'frontend',
        'template' => 'Weline_Affiliate::templates/frontend/widgets/affiliate-center.phtml',
        'page_layouts' => ['account'],
        'position' => ['content'],
        'slot' => 'account-affiliate-center',
        'supports' => ['account-affiliate-center', 'affiliate-center', 'affiliate'],
        'params' => [],
    ],
    'footer-promote-link' => [
        'name' => '页脚我要推广链接',
        'description' => '页脚合作信息扩展槽：分销推广入口；默认注入 footer-partner-links。',
        'type' => 'footer',
        'code' => 'footer-promote-link',
        'area' => 'frontend',
        'template' => 'Weline_Affiliate::templates/frontend/widgets/footer-promote-link.phtml',
        'page_layouts' => ['*'],
        'position' => ['footer'],
        'slot' => 'footer-partner-links',
        'supports' => [
            'footer-promote-link',
            'layout-footer-partner-links',
        ],
        'default_injections' => [[
            'layout_type' => '*',
            'slot' => 'footer-partner-links',
            'area' => 'footer',
            'sort_order' => 10,
            'required' => true,
            'reason' => '页脚合作信息默认展示我要推广',
            'config' => [
                'label' => '我要推广',
            ],
        ]],
        'params' => [
            'label' => [
                'default' => '我要推广',
                'type' => 'string',
                'label' => '链接文字',
            ],
        ],
    ],
];
