<?php

declare(strict_types=1);

/**
 * Filters 前台部件：分类布局左侧筛选。
 * Theme 布局禁止内嵌本模块 <w:widget>；靠 default_injections / 拖入补空槽。
 */
return [
    'category-filters' => [
        'name' => '分类筛选',
        'description' => '分类页左侧筛选：部门树、价格与 EAV 属性筛选；默认注入 category-filters。',
        'type' => 'sidebar',
        'code' => 'category-filters',
        'area' => 'frontend',
        'template' => 'Weline_Filters::templates/frontend/widgets/category-filters.phtml',
        'page_layouts' => ['category', 'search', 'products'],
        'position' => ['sidebar'],
        // 顶栏 slot 偏好 category；products 页注入目标见 default_injections → list-filters。
        'slot' => 'category-filters',
        // placement=injection：宿主布局只留空槽；禁止布局内嵌 + JSON 双路径。
        'placement' => 'injection',
        'supports' => [
            'layout-category-filters',
            'layout-products-filters',
            'category-filters',
            'attribute-filter',
            'price-filter',
            'category-filter',
            'rating-filter',
        ],
        'default_injections' => [
            [
                'layout_type' => 'category',
                'layout_option' => 'default',
                'slot' => 'category-filters',
                'area' => 'sidebar',
                'sort_order' => 0,
                'required' => true,
                'reason' => '分类页默认在左侧筛选槽展示 Filters 部件（含属性筛选）',
            ],
            [
                'layout_type' => 'products',
                'layout_option' => 'default',
                'slot' => 'list-filters',
                'area' => 'sidebar',
                'sort_order' => 0,
                'required' => true,
                'reason' => '商品列表默认在筛选槽展示 Filters 部件',
            ],
        ],
        'params' => [],
    ],
];
