<?php

/**
 * Weline_Wishlist module hook specification file.
 */
return [
    'Weline_Wishlist::frontend::account::index::wishlist' => [
        'name' => \__('账户首页收藏分区'),
        'description' => \__('在顾客账户首页收藏分区注入愿望清单列表与管理入口。'),
        'doc' => 'frontend/account/index/wishlist.md',
    ],
    'header-wishlist-icon' => [
        'name' => \__('页头收藏夹图标'),
        'description' => \__('在页头 user-area 展示收藏入口；Theme partial 经 Hook 交付，避免内嵌非 Theme 部件。'),
        'doc' => 'header-wishlist-icon.md',
    ],
];
