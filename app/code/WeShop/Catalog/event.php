<?php
/**
 * WeShop_Catalog 模块事件规约
 *
 * 分类加载后、分类保存后等事件，供其他模块（如 WeShop_Filters）在 event.xml 中监听。
 */
return [
    'WeShop_Catalog::category_load_after' => [
        'name' => __('分类加载后'),
        'description' => __('分类页面加载后触发，筛选模块可监听以收集筛选器。'),
        'doc' => 'category_load_after.md',
    ],
    'WeShop_Catalog::category_save_after' => [
        'name' => __('分类保存后'),
        'description' => __('分类保存后触发，筛选模块可监听以清除分类筛选缓存。'),
        'doc' => 'category_save_after.md',
    ],
];
