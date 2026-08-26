<?php

declare(strict_types=1);

/**
 * Wishlist 前台部件：顶栏收藏入口（图标 + 数量）。
 */
return [
    'wishlist-icon' => [
        'name' => '收藏夹',
        'description' => '页头收藏入口，展示收藏数量并跳转至心愿单列表页。',
        'type' => 'header',
        'code' => 'wishlist-icon',
        'area' => 'frontend',
        'template' => 'Weline_Wishlist::theme/frontend/widgets/header/wishlist-icon/default.phtml',
        'page_layouts' => ['*'],
        'position' => ['header'],
        'slot' => 'user-area',
        'supports' => [
            'layout-header-actions',
            'layout-global-header-actions',
            'wishlist-icon',
        ],
        'default_injections' => [[
            'layout_type' => '*',
            'slot' => 'user-area',
            'area' => 'header',
            'sort_order' => 12,
            'required' => false,
            'reason' => '前台顶栏默认展示收藏入口',
            'config' => [
                'show_count' => true,
            ],
        ]],
        'params' => [
            'show_count' => [
                'default' => true,
                'type' => 'bool',
                'label' => '显示数量',
            ],
        ],
    ],
];
