<?php

declare(strict_types=1);

/**
 * Weline_Product 模块 Hook 规约
 */
return [
    'Weline_Product::frontend::product::detail::after-price' => [
        'name' => (string)__('商品详情价格之后'),
        'description' => (string)__('在商品详情页价格区块之后注入扩展内容，例如 ToC/ToB 售卖模式切换。'),
        'doc' => 'frontend/product/detail/after-price.md',
    ],
    'Weline_Product::frontend::product::detail::after-gallery' => [
        'name' => (string)__('商品详情图库之后'),
        'description' => (string)__('在商品详情页主图/图库区块之后注入扩展内容，例如分销分享脚条。'),
        'doc' => 'frontend/product/detail/after-gallery.md',
    ],
    'Weline_Product::frontend::product::detail::after-add-to-cart' => [
        'name' => (string)__('商品详情加购之后'),
        'description' => (string)__('在商品详情页加购操作区域之后注入扩展内容，例如默认收起的分销分享。'),
        'doc' => 'frontend/product/detail/after-add-to-cart.md',
    ],
    'Weline_Product::backend::catalog::products::bulk-actions' => [
        'name' => (string)__('商品目录批量操作扩展'),
        'description' => (string)__('在商品目录列表批量操作栏注入扩展按钮，例如分类模块的批量调整分类。'),
        'doc' => 'backend/catalog/products/bulk-actions.md',
    ],
    'Weline_Product::backend::catalog::edit::basic-after' => [
        'name' => (string)__('商品编辑 · 基础信息扩展'),
        'description' => (string)__('在后台商品编辑「基础信息」面板末尾注入扩展区块，例如 B2B 产品级批发开关与批发数据管理入口。'),
        'doc' => 'backend/catalog/edit/basic-after.md',
    ],
    'Weline_Product::backend::catalog::create::basic-after' => [
        'name' => (string)__('商品新增 · 基础信息扩展'),
        'description' => (string)__('在后台商品创建向导「基础信息」步骤注入扩展区块，例如 B2B 产品级批发开关。'),
        'doc' => 'backend/catalog/create/basic-after.md',
    ],
    'Weline_Product::backend::catalog::edit::offers-after' => [
        'name' => (string)__('商品编辑 · 规格与价格扩展'),
        'description' => (string)__('在后台商品编辑「规格与价格」面板末尾注入扩展区块，例如 B2B 启用批发与 SKU 数量阶梯价编辑。'),
        'doc' => 'backend/catalog/edit/offers-after.md',
    ],
];
