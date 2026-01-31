<?php
/**
 * WeShop_Frontend 模块 Hook 规约文件
 *
 * 本文件定义了 WeShop_Frontend 模块提供的 Hook 扩展点
 * Hook 命名格式：{ModuleName}::{area}::{type}::{component}::{position}
 */
return [
    // ==================== Header Hooks ====================
    'WeShop_Frontend::frontend::partials::header::mini-cart' => [
        'name' => __('Header 迷你购物车'),
        'description' => __('站点头部迷你购物车入口区域。'),
        'doc' => 'frontend/partials/header/mini-cart.md',
    ],
];