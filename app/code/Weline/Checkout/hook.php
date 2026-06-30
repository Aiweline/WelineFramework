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

    'Weline_Checkout::frontend::layouts::checkout::identity-before' => [
        'name' => __('结账身份选择之前'),
        'description' => __('在默认一页式结账的账户/匿名身份选择区块之前触发，允许模块注入登录、会员权益或风控提示。'),
        'doc' => 'frontend/layouts/checkout/identity-before.md',
    ],

    'Weline_Checkout::frontend::layouts::checkout::identity-options-before' => [
        'name' => __('结账身份选项之前'),
        'description' => __('在账户结账与匿名结账选项列表之前触发，允许模块增加说明或调整选择体验。'),
        'doc' => 'frontend/layouts/checkout/identity-options-before.md',
    ],

    'Weline_Checkout::frontend::layouts::checkout::identity-options-after' => [
        'name' => __('结账身份选项之后'),
        'description' => __('在账户结账与匿名结账选项列表之后触发，允许模块补充匿名结账说明、协议或风险提示。'),
        'doc' => 'frontend/layouts/checkout/identity-options-after.md',
    ],

    'Weline_Checkout::frontend::layouts::checkout::identity-after' => [
        'name' => __('结账身份选择之后'),
        'description' => __('在默认一页式结账的账户/匿名身份选择区块之后触发，允许模块注入后续上下文或客户端扩展。'),
        'doc' => 'frontend/layouts/checkout/identity-after.md',
    ],

    'Weline_Checkout::frontend::layouts::checkout::notification-preferences' => [
        'name' => __('结账通知渠道偏好'),
        'description' => __('在结账身份选择后注入本次订单通知渠道选择，由通知模块通过 Hook 提供具体内容。'),
        'doc' => 'frontend/layouts/checkout/notification-preferences.md',
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
    
    'Weline_Checkout::frontend::layouts::checkout::shipping-methods-before' => [
        'name' => __('结账配送方式区域之前'),
        'description' => __('在默认一页式结账的配送方式区域之前触发，允许配送模块注入提示、限制或扩展内容。'),
        'doc' => 'frontend/layouts/checkout/shipping-methods-before.md',
    ],

    'Weline_Checkout::frontend::layouts::checkout::shipping-methods-after' => [
        'name' => __('结账配送方式区域之后'),
        'description' => __('在默认一页式结账的配送方式区域之后触发，允许配送模块注入补充说明或扩展内容。'),
        'doc' => 'frontend/layouts/checkout/shipping-methods-after.md',
    ],

    'Weline_Checkout::frontend::partials::checkout::shipping-methods' => [
        'name' => __('结账配送方式列表'),
        'description' => __('渲染默认一页式结账中的配送方式列表；具体电商模块通过该 hook 提供数据化实现。'),
        'doc' => 'frontend/partials/checkout/shipping-methods.md',
    ],

    'Weline_Checkout::frontend::partials::checkout::payment-methods' => [
        'name' => __('结账支付方式列表'),
        'description' => __('渲染默认一页式结账中的支付方式列表；支付模块通过该 hook 将支付方式放入结账布局。'),
        'doc' => 'frontend/partials/checkout/payment-methods.md',
    ],

    'Weline_Checkout::frontend::partials::checkout::payment-details' => [
        'name' => __('结账支付方式详情'),
        'description' => __('渲染所选支付方式的补充说明、重定向提示或线下支付指引。'),
        'doc' => 'frontend/partials/checkout/payment-details.md',
    ],

    'Weline_Checkout::frontend::layouts::checkout::review-before' => [
        'name' => __('结账确认区域之前'),
        'description' => __('在提交订单确认区域之前触发，用于促销、风控、协议或校验提示扩展。'),
        'doc' => 'frontend/layouts/checkout/review-before.md',
    ],

    'Weline_Checkout::frontend::layouts::checkout::review-after' => [
        'name' => __('结账确认区域之后'),
        'description' => __('在提交订单确认区域之后触发，用于补充说明或转化追踪扩展。'),
        'doc' => 'frontend/layouts/checkout/review-after.md',
    ],

    'Weline_Checkout::frontend::layouts::checkout::place-order-button-before' => [
        'name' => __('提交订单按钮之前'),
        'description' => __('在默认提交订单按钮之前触发，允许模块追加校验提示或替换性操作入口。'),
        'doc' => 'frontend/layouts/checkout/place-order-button-before.md',
    ],

    'Weline_Checkout::frontend::layouts::checkout::place-order-button-after' => [
        'name' => __('提交订单按钮之后'),
        'description' => __('在默认提交订单按钮之后触发，允许模块追加支付协议、风控或分析埋点内容。'),
        'doc' => 'frontend/layouts/checkout/place-order-button-after.md',
    ],

    'Weline_Checkout::frontend::layouts::checkout::summary-before' => ['name' => __('结账摘要之前'), 'description' => __('在订单摘要卡片标题之前触发。'), 'doc' => 'frontend/layouts/checkout/summary-before.md'],
    'Weline_Checkout::frontend::layouts::checkout::summary-after' => ['name' => __('结账摘要之后'), 'description' => __('在订单摘要卡片底部触发。'), 'doc' => 'frontend/layouts/checkout/summary-after.md'],
    'Weline_Checkout::frontend::layouts::checkout::summary-rows-before' => ['name' => __('结账金额行之前'), 'description' => __('在订单摘要金额明细行之前触发。'), 'doc' => 'frontend/layouts/checkout/summary-rows-before.md'],
    'Weline_Checkout::frontend::layouts::checkout::summary-rows-after' => ['name' => __('结账金额行之后'), 'description' => __('在订单摘要金额明细行之后触发。'), 'doc' => 'frontend/layouts/checkout/summary-rows-after.md'],
    'Weline_Checkout::frontend::layouts::checkout::summary-subtotal-before' => ['name' => __('商品小计之前'), 'description' => __('在商品小计行数值之前触发。'), 'doc' => 'frontend/layouts/checkout/summary-subtotal-before.md'],
    'Weline_Checkout::frontend::layouts::checkout::summary-subtotal-after' => ['name' => __('商品小计之后'), 'description' => __('在商品小计行数值之后触发。'), 'doc' => 'frontend/layouts/checkout/summary-subtotal-after.md'],
    'Weline_Checkout::frontend::layouts::checkout::summary-shipping-before' => ['name' => __('运费行之前'), 'description' => __('在运费行数值之前触发。'), 'doc' => 'frontend/layouts/checkout/summary-shipping-before.md'],
    'Weline_Checkout::frontend::layouts::checkout::summary-shipping-after' => ['name' => __('运费行之后'), 'description' => __('在运费行数值之后触发。'), 'doc' => 'frontend/layouts/checkout/summary-shipping-after.md'],
    'Weline_Checkout::frontend::layouts::checkout::summary-tax-before' => ['name' => __('税费行之前'), 'description' => __('在税费行数值之前触发。'), 'doc' => 'frontend/layouts/checkout/summary-tax-before.md'],
    'Weline_Checkout::frontend::layouts::checkout::summary-tax-after' => ['name' => __('税费行之后'), 'description' => __('在税费行数值之后触发。'), 'doc' => 'frontend/layouts/checkout/summary-tax-after.md'],
    'Weline_Checkout::frontend::layouts::checkout::summary-discount-before' => ['name' => __('优惠行之前'), 'description' => __('在优惠行数值之前触发。'), 'doc' => 'frontend/layouts/checkout/summary-discount-before.md'],
    'Weline_Checkout::frontend::layouts::checkout::summary-discount-after' => ['name' => __('优惠行之后'), 'description' => __('在优惠行数值之后触发。'), 'doc' => 'frontend/layouts/checkout/summary-discount-after.md'],
    'Weline_Checkout::frontend::layouts::checkout::summary-grand-total-before' => ['name' => __('应付合计之前'), 'description' => __('在应付合计行数值之前触发。'), 'doc' => 'frontend/layouts/checkout/summary-grand-total-before.md'],
    'Weline_Checkout::frontend::layouts::checkout::summary-grand-total-after' => ['name' => __('应付合计之后'), 'description' => __('在应付合计行数值之后触发。'), 'doc' => 'frontend/layouts/checkout/summary-grand-total-after.md'],

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

