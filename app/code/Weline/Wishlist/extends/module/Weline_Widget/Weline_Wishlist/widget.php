<?php

declare(strict_types=1);

/**
 * Wishlist 前台部件：顶栏收藏入口（图标 + 数量）。
 *
 * 默认店面由 Theme header 的 header-wishlist-icon Hook 交付，不走应用 Tab 默认注入，
 * 避免应用 Tab 显示「推荐/+」而预览顶栏已有入口。部件仍可手动拖入其它兼容槽。
 */
return [
    'wishlist-icon' => [
        'name' => '收藏夹',
        'description' => '页头收藏入口，展示收藏数量并跳转至心愿单列表页。默认店面由页头 Hook 交付。',
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
        'params' => [
            'show_count' => [
                'default' => true,
                'type' => 'bool',
                'label' => '显示数量',
            ],
        ],
    ],
];
