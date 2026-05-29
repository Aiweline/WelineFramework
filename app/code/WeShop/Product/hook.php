<?php
/**
 * WeShop_Product 模块 Hook 规约文件
 * 
 * 本文件定义了 WeShop_Product 模块提供的所有 Hook 扩展点
 * Hook 命名格式：{ModuleName}::{area}::{type}::{component}::{position}
 */
return [
    // ==================== Product Detail Hooks ====================
    'WeShop_Product::frontend::product::detail::after-add-to-cart' => [
        'name' => __('产品详情页加入购物车之后'),
        'description' => __('在产品详情页面加入购物车之后触发，允许其他模块在加入购物车后执行自定义逻辑。'),
        'doc' => 'frontend/product/detail/after-add-to-cart.md',
    ],
    
    // ==================== Product Add to Cart Slot ====================
    'WeShop_Product::frontend::product::add-to-cart::button' => [
        'name' => __('产品加入购物车按钮'),
        'description' => __('产品加入购物车按钮的slot，其他模块（如Cart模块）可以实现此hook来处理加购逻辑。对于可配置产品，会弹出规格选择弹窗。'),
        'doc' => 'frontend/product/add-to-cart/button.md',
        'slot' => true, // 标记为slot，允许其他模块替换
    ],
    'WeShop_Product::frontend::product::add-to-cart::options-popup' => [
        'name' => __('可配置产品规格选择弹窗'),
        'description' => __('可配置产品的规格选择弹窗，用户选择规格后再加入购物车。'),
        'doc' => 'frontend/product/add-to-cart/options-popup.md',
    ],
    
    // ==================== Product List Layout Hooks ====================
    'WeShop_Product::frontend::layouts::product-list::pagination-content' => [
        'name' => __('产品列表分页内容'),
        'description' => __('在产品列表页分页区域渲染分页组件，包括页码导航、跳转功能等。如果只有一页或没有数据，则不渲染分页。'),
        'doc' => 'frontend/layouts/product-list/pagination-content.md',
    ],
    'WeShop_Product::frontend::layouts::product-list::grid-content' => [
        'name' => __('产品列表网格内容'),
        'description' => __('在产品列表页网格区域渲染产品列表，支持网格视图和列表视图两种显示模式。如果没有产品数据，不渲染任何内容。'),
        'doc' => 'frontend/layouts/product-list/grid-content.md',
    ],
    'WeShop_Product::frontend::layouts::product-list::toolbar-content' => [
        'name' => __('产品列表工具栏内容'),
        'description' => __('在产品列表页工具栏区域渲染排序、视图切换、每页数量等选项，允许用户自定义产品列表的显示方式和排序规则。'),
        'doc' => 'frontend/layouts/product-list/toolbar-content.md',
    ],
    
    // ==================== Product Detail Layout Hooks ====================
    'WeShop_Product::frontend::layouts::product::main-content' => [
        'name' => __('产品详情主内容'),
        'description' => __('在产品详情页主内容区域渲染产品图片、信息、购买选项等。包括产品图片画廊、基本信息、价格、库存状态、购买按钮等核心内容。'),
        'doc' => 'frontend/layouts/product/main-content.md',
    ],
    'WeShop_Product::frontend::layouts::product::tabs-content' => [
        'name' => __('产品详情标签内容'),
        'description' => __('在产品详情页标签区域渲染产品描述、规格、评价、问答等内容。支持多个标签页切换，包括商品详情、规格参数、用户评价、问答等。'),
        'doc' => 'frontend/layouts/product/tabs-content.md',
    ],
    'WeShop_Product::frontend::layouts::product::description-content' => [
        'name' => __('产品详情描述内容'),
        'description' => __('替换产品详情页描述标签面板的默认内容。未实现时使用产品描述字段和空态文案。'),
        'doc' => 'frontend/layouts/product/description-content.md',
    ],
    'WeShop_Product::frontend::layouts::product::specifications-content' => [
        'name' => __('产品详情规格内容'),
        'description' => __('替换产品详情页规格标签面板的默认内容。未实现时使用规格表格和空态文案。'),
        'doc' => 'frontend/layouts/product/specifications-content.md',
    ],
    'WeShop_Product::frontend::layouts::product::recommendations-content' => [
        'name' => __('产品详情推荐区域内容'),
        'description' => __('替换产品详情页推荐区域的整体内容。适用于完整接管相关推荐、热销、最近浏览和搭配推荐区域。'),
        'doc' => 'frontend/layouts/product/recommendations-content.md',
    ],
    'WeShop_Product::frontend::layouts::product::related-products' => [
        'name' => __('产品详情相关产品'),
        'description' => __('在产品详情页推荐区域渲染相关产品列表。适用于只接管相关产品，不影响其他推荐组件。'),
        'doc' => 'frontend/layouts/product/related-products.md',
    ],
    'WeShop_Product::frontend::layouts::product::bestsellers' => [
        'name' => __('产品详情热销商品'),
        'description' => __('在产品详情页推荐区域渲染热销商品列表。'),
        'doc' => 'frontend/layouts/product/bestsellers.md',
    ],
    'WeShop_Product::frontend::layouts::product::recently-viewed' => [
        'name' => __('产品详情最近浏览'),
        'description' => __('在产品详情页推荐区域渲染最近浏览商品列表。'),
        'doc' => 'frontend/layouts/product/recently-viewed.md',
    ],
    'WeShop_Product::frontend::layouts::product::cross-sell' => [
        'name' => __('产品详情搭配推荐'),
        'description' => __('在产品详情页推荐区域渲染搭配购买、交叉销售或向上销售商品列表。'),
        'doc' => 'frontend/layouts/product/cross-sell.md',
    ],

    // ==================== Backend Product Edit Hooks ====================
    'WeShop_Product::backend::product::edit::nav-after' => [
        'name' => __('产品编辑页步骤导航后'),
        'description' => __('在后台产品编辑页步骤导航末尾追加模块自己的管理入口。'),
        'doc' => 'backend/product/edit/nav-after.md',
    ],
    'WeShop_Product::backend::product::edit::content-after' => [
        'name' => __('产品编辑页内容后'),
        'description' => __('在后台产品编辑页内容区域末尾追加当前产品相关的模块管理面板。'),
        'doc' => 'backend/product/edit/content-after.md',
    ],
];
