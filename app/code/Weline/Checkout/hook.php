<?php
/**
 * Weline_Checkout 模块 Hook 规约文件
 * 
 * 本文件定义了 Weline_Checkout 模块提供的所有 Hook 扩展点
 * Hook 命名格式：{ModuleName}::{area}::{type}::{component}::{position}
 * 
 * 注意：Hook系统用于视图层（前端布局页面），用于在主题布局页面中推送模板内容。
 * 事件系统用于服务层（业务逻辑），两者不冲突，可以同时使用。
 * 
 * 所有 Hook 必须在 Weline\Framework\Hook\HookInterface 中定义为常量
 */
return [
    // ==================== Frontend Checkout Layout ====================
    
    // 结账页面头部之前
    'Weline_Checkout::frontend::layouts::checkout::head-before' => [
        'name' => __('结账页面头部之前'),
        'description' => __('在渲染结账页面的 <head> 标签之前触发，允许其他模块在头部开始处注入内容（如CSS、JavaScript等）。'),
        'doc' => 'frontend/layouts/checkout/head-before.md',
    ],
    
    // 结账页面头部之后
    'Weline_Checkout::frontend::layouts::checkout::head-after' => [
        'name' => __('结账页面头部之后'),
        'description' => __('在渲染结账页面的 <head> 标签之后触发，允许其他模块在头部结束处注入内容。'),
        'doc' => 'frontend/layouts/checkout/head-after.md',
    ],
    
    // 结账页面内容之前
    'Weline_Checkout::frontend::layouts::checkout::content-before' => [
        'name' => __('结账页面内容之前'),
        'description' => __('在渲染结账页面的主要内容之前触发，允许其他模块在内容开始处注入内容（如横幅、提示信息等）。'),
        'doc' => 'frontend/layouts/checkout/content-before.md',
    ],
    
    // 结账页面内容之后
    'Weline_Checkout::frontend::layouts::checkout::content-after' => [
        'name' => __('结账页面内容之后'),
        'description' => __('在渲染结账页面的主要内容之后触发，允许其他模块在内容结束处注入内容（如推荐商品、优惠信息等）。'),
        'doc' => 'frontend/layouts/checkout/content-after.md',
    ],
    
    // 结账表单之前
    'Weline_Checkout::frontend::layouts::checkout::form-before' => [
        'name' => __('结账表单之前'),
        'description' => __('在渲染结账表单之前触发，允许其他模块在表单开始处注入内容（如优惠券输入框、促销信息等）。'),
        'doc' => 'frontend/layouts/checkout/form-before.md',
    ],
    
    // 结账表单之后
    'Weline_Checkout::frontend::layouts::checkout::form-after' => [
        'name' => __('结账表单之后'),
        'description' => __('在渲染结账表单之后触发，允许其他模块在表单结束处注入内容（如支付方式选择、额外信息等）。'),
        'doc' => 'frontend/layouts/checkout/form-after.md',
    ],
    
    // 支付方式选择区域之前
    'Weline_Checkout::frontend::layouts::checkout::payment-methods-before' => [
        'name' => __('支付方式选择区域之前'),
        'description' => __('在渲染支付方式选择区域之前触发，允许支付模块在支付方式选择区域开始处注入内容。'),
        'doc' => 'frontend/layouts/checkout/payment-methods-before.md',
    ],
    
    // 支付方式选择区域之后
    'Weline_Checkout::frontend::layouts::checkout::payment-methods-after' => [
        'name' => __('支付方式选择区域之后'),
        'description' => __('在渲染支付方式选择区域之后触发，允许支付模块在支付方式选择区域结束处注入内容。'),
        'doc' => 'frontend/layouts/checkout/payment-methods-after.md',
    ],
    
    // ==================== Frontend Order List Layout ====================
    
    // 订单列表内容之前
    'Weline_Checkout::frontend::layouts::order-list::content-before' => [
        'name' => __('订单列表内容之前'),
        'description' => __('在渲染订单列表页面内容之前触发，允许其他模块在订单列表开始处注入内容（如筛选器、统计信息等）。'),
        'doc' => 'frontend/layouts/order/list/content-before.md',
    ],
    
    // 订单列表内容之后
    'Weline_Checkout::frontend::layouts::order-list::content-after' => [
        'name' => __('订单列表内容之后'),
        'description' => __('在渲染订单列表页面内容之后触发，允许其他模块在订单列表结束处注入内容。'),
        'doc' => 'frontend/layouts/order/list/content-after.md',
    ],
    
    // ==================== Frontend Order View Layout ====================
    
    // 订单详情内容之前
    'Weline_Checkout::frontend::layouts::order-view::content-before' => [
        'name' => __('订单详情内容之前'),
        'description' => __('在渲染订单详情页面内容之前触发，允许其他模块在订单详情开始处注入内容（如订单状态提示、操作按钮等）。'),
        'doc' => 'frontend/layouts/order/view/content-before.md',
    ],
    
    // 订单详情内容之后
    'Weline_Checkout::frontend::layouts::order-view::content-after' => [
        'name' => __('订单详情内容之后'),
        'description' => __('在渲染订单详情页面内容之后触发，允许其他模块在订单详情结束处注入内容（如相关推荐、评价入口等）。'),
        'doc' => 'frontend/layouts/order/view/content-after.md',
    ],
    
    // ==================== Backend Order Layout ====================
    
    // 后台订单列表筛选器
    'Weline_Checkout::backend::layouts::order-list::filters' => [
        'name' => __('后台订单列表筛选器'),
        'description' => __('在后台订单列表页面的筛选器区域注入内容，允许其他模块添加自定义筛选条件。'),
        'doc' => 'backend/order/list/filters.md',
    ],
    
    // 后台订单详情页之前
    'Weline_Checkout::backend::layouts::order-view::before' => [
        'name' => __('后台订单详情页之前'),
        'description' => __('在后台订单详情页面内容之前注入内容，允许其他模块在订单详情页顶部添加自定义内容。'),
        'doc' => 'backend/order/view/before.md',
    ],
    
    // 后台订单详情页之后
    'Weline_Checkout::backend::layouts::order-view::after' => [
        'name' => __('后台订单详情页之后'),
        'description' => __('在后台订单详情页面内容之后注入内容，允许其他模块在订单详情页底部添加自定义内容。'),
        'doc' => 'backend/order/view/after.md',
    ],
];

