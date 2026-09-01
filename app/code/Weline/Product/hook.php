<?php

declare(strict_types=1);

/**
 * Weline_Product 模块 Hook 规约
 */
return [
    'Weline_Product::frontend::product::detail::after-add-to-cart' => [
        'name' => (string)__('商品详情加购之后'),
        'description' => (string)__('在商品详情页加购操作区域之后注入扩展内容，例如分销分享面板。'),
        'doc' => 'frontend/product/detail/after-add-to-cart.md',
    ],
    'Weline_Product::backend::catalog::products::bulk-actions' => [
        'name' => (string)__('商品目录批量操作扩展'),
        'description' => (string)__('在商品目录列表批量操作栏注入扩展按钮，例如分类模块的批量调整分类。'),
        'doc' => 'backend/catalog/products/bulk-actions.md',
    ],
];
