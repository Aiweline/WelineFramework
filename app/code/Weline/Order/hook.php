<?php
/**
 * Weline_Order 模块 Hook 规约文件
 * 
 * 本文件定义了 Weline_Order 模块提供的所有 Hook 扩展点
 * Hook 命名格式：{ModuleName}::{area}::{type}::{component}::{position}
 */
return [
    // ==================== Backend Order View ====================
    'Weline_Order::backend::order::view::before' => [
        'name' => __('订单详情页之前'),
        'description' => __('在订单详情页面内容之前注入内容，允许其他模块在订单详情页顶部添加自定义内容。'),
        'doc' => 'backend/order/view/before.md',
    ],
    
    'Weline_Order::backend::order::view::after' => [
        'name' => __('订单详情页之后'),
        'description' => __('在订单详情页面内容之后注入内容，允许其他模块在订单详情页底部添加自定义内容。'),
        'doc' => 'backend/order/view/after.md',
    ],
    
    // ==================== Backend Order List ====================
    'Weline_Order::backend::order::list::filters' => [
        'name' => __('订单列表筛选器'),
        'description' => __('在订单列表页面的筛选器区域注入内容，允许其他模块添加自定义筛选条件。'),
        'doc' => 'backend/order/list/filters.md',
    ],
    
    // ==================== Frontend Order Create ====================
    'Weline_Order::frontend::order::create::before' => [
        'name' => __('前端订单创建前'),
        'description' => __('在前端订单创建之前注入内容，允许其他模块在订单创建前执行自定义逻辑。'),
        'doc' => 'frontend/order/create/before.md',
    ],
    
    'Weline_Order::frontend::order::create::after' => [
        'name' => __('前端订单创建后'),
        'description' => __('在前端订单创建之后注入内容，允许其他模块在订单创建后执行自定义逻辑。'),
        'doc' => 'frontend/order/create/after.md',
    ],
];

