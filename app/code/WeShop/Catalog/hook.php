<?php
/**
 * WeShop_Catalog 模块 Hook 规约文件
 * 
 * 本文件定义了 WeShop_Catalog 模块提供的所有 Hook 扩展点
 * Hook 命名格式：{ModuleName}::{area}::{type}::{component}::{position}
 */
return [
    // ==================== Frontend Category Layout ====================
    'WeShop_Catalog::frontend::layouts::category::products-content' => [
        'name' => __('分类页产品列表内容'),
        'description' => __('在分类页产品区域渲染该分类下的产品列表。如果分类有子分类，优先显示子分类网格；如果没有产品数据，显示空状态提示。'),
        'doc' => 'frontend/layouts/category/products-content.md',
    ],
];
