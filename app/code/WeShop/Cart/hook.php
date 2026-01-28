<?php
/**
 * WeShop_Cart 模块 Hook 规约文件
 * 
 * 本文件定义了 WeShop_Cart 模块提供的所有 Hook 扩展点
 * Hook 命名格式：{ModuleName}::{area}::{type}::{component}::{position}
 */
return [
    // ==================== Cart Page Hooks ====================
    'WeShop_Cart::frontend::partials::cart-items::before' => [
        'name' => __('购物车商品列表前'),
        'description' => __('在购物车商品列表前渲染内容。'),
        'doc' => 'hook/cart/before_items.md',
    ],
    'WeShop_Cart::frontend::partials::cart-items::after' => [
        'name' => __('购物车商品列表后'),
        'description' => __('在购物车商品列表后渲染内容。'),
        'doc' => 'hook/cart/after_items.md',
    ],
    'WeShop_Cart::frontend::partials::cart-item::after' => [
        'name' => __('购物车单个商品后'),
        'description' => __('在购物车每个商品后渲染内容，可用于添加赠品、促销信息等。'),
        'doc' => 'hook/cart/after_item.md',
    ],
    'WeShop_Cart::frontend::partials::cart-totals::before' => [
        'name' => __('购物车总计前'),
        'description' => __('在购物车总计区域前渲染内容。'),
        'doc' => 'hook/cart/before_totals.md',
    ],
    'WeShop_Cart::frontend::partials::cart-totals::after' => [
        'name' => __('购物车总计后'),
        'description' => __('在购物车总计区域后渲染内容。'),
        'doc' => 'hook/cart/after_totals.md',
    ],
    'WeShop_Cart::frontend::partials::cart::sidebar' => [
        'name' => __('购物车侧边栏'),
        'description' => __('购物车页面侧边栏区域，可用于展示促销信息、推荐商品等。'),
        'doc' => 'hook/cart/sidebar.md',
    ],
    
    // ==================== Mini Cart Hooks ====================
    'WeShop_Cart::frontend::partials::mini-cart-items::before' => [
        'name' => __('迷你购物车商品列表前'),
        'description' => __('在迷你购物车商品列表前渲染内容。'),
        'doc' => 'hook/mini_cart/before_items.md',
    ],
    'WeShop_Cart::frontend::partials::mini-cart-items::after' => [
        'name' => __('迷你购物车商品列表后'),
        'description' => __('在迷你购物车商品列表后渲染内容。'),
        'doc' => 'hook/mini_cart/after_items.md',
    ],
];
