<?php

declare(strict_types=1);

/**
 * Promotion 前台部件：页头右侧导航扩展（今日特价）。
 * Theme layouts/partials 禁止内嵌本模块 <w:widget>；靠 default_injections / 拖入补空槽。
 */
return [
    'header-deals-link' => [
        'name' => '页头今日特价链接',
        'description' => '页头右侧导航扩展槽：今日特价入口（/promotion/deals）；默认注入 header-nav-extensions。',
        'type' => 'navigation',
        'code' => 'header-deals-link',
        'area' => 'frontend',
        'template' => 'Weline_Promotion::templates/frontend/widgets/header-deals-link.phtml',
        'page_layouts' => ['*'],
        'position' => ['header'],
        'slot' => 'header-nav-extensions',
        'supports' => [
            'header-deals-link',
            'header-nav-link',
            'layout-header-nav-extensions',
        ],
        'default_injections' => [[
            'layout_type' => 'homepage',
            'slot' => 'header-nav-extensions',
            'area' => 'header',
            'sort_order' => 10,
            'required' => true,
            'reason' => '页头右侧扩展槽默认展示今日特价（活动模块）',
            'config' => [
                'label' => '今日特价',
            ],
        ]],
        'params' => [
            'label' => [
                'default' => '今日特价',
                'type' => 'string',
                'label' => '链接文字',
            ],
        ],
    ],
];
