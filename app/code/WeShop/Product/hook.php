<?php
/**
 * WeShop_Product 模块 Hook 规约文件
 * 
 * 本文件定义了 WeShop_Product 模块提供的所有 Hook 扩展点
 */
return [
    // ==================== Product Detail Hooks ====================
    'WeShop_Product::frontend::product::detail::after-add-to-cart' => [
        'name' => __('产品详情页加入购物车之后'),
        'description' => __('在产品详情页面加入购物车之后触发，允许其他模块在加入购物车后执行自定义逻辑。'),
        'doc' => 'frontend/product/detail/after-add-to-cart.md',
    ],
];
