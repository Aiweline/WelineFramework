<?php

declare(strict_types=1);

/**
 * RecentlyViewed 前台部件：最近浏览（真实 Cookie MRU）。
 */
return [
    'recently-viewed' => [
        'name' => '最近浏览',
        'description' => '展示当前访客真实浏览过的产品（Cookie MRU）。',
        'type' => 'product',
        'code' => 'recently-viewed',
        'area' => 'frontend',
        'template' => 'Weline_RecentlyViewed::templates/frontend/widgets/recently-viewed.phtml',
        'page_layouts' => ['*'],
        'position' => ['content', 'sidebar'],
        'slot' => 'product-recently-viewed',
        'supports' => [
            'layout-product-recently-viewed',
            'layout-product-sidebar',
            'layout-products-recommendations',
            'layout-search-recommendations',
            'recently-viewed',
        ],
        'default_injections' => [[
            'layout_type' => 'product',
            'layout_option' => 'default',
            'slot' => 'product-recently-viewed',
            'area' => 'content',
            'sort_order' => 0,
            'required' => true,
            'reason' => '商品详情默认最近浏览槽',
            'config' => [
                'title' => '最近浏览',
                'limit' => 24,
                'columns' => '6',
                'layout' => 'carousel',
            ],
        ]],
        'params' => [
            'title' => [
                'default' => '最近浏览',
                'type' => 'string',
                'label' => '标题',
            ],
            'limit' => [
                'default' => 24,
                'type' => 'number',
                'label' => '显示数量',
            ],
            'columns' => [
                'default' => '6',
                'type' => 'select',
                'label' => '每行列数',
                'options' => [
                    '3' => '3列',
                    '4' => '4列',
                    '5' => '5列',
                    '6' => '6列',
                ],
            ],
            'layout' => [
                'default' => 'carousel',
                'type' => 'select',
                'label' => '布局方式',
                'options' => [
                    'grid' => '网格',
                    'carousel' => '轮播',
                ],
            ],
            'show_price' => [
                'default' => true,
                'type' => 'bool',
                'label' => '显示价格',
            ],
            'show_wishlist' => [
                'default' => true,
                'type' => 'bool',
                'label' => '显示收藏',
            ],
            'show_compare' => [
                'default' => true,
                'type' => 'bool',
                'label' => '显示对比',
            ],
            'show_quickview' => [
                'default' => true,
                'type' => 'bool',
                'label' => '显示快速查看',
            ],
        ],
    ],
];
