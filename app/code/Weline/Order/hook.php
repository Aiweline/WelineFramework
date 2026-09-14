<?php

/**
 * Weline_Order module hook specification file.
 */
return [
    'Weline_Order::frontend::account::index::orders' => [
        'name' => \__('账户首页订单分区'),
        'description' => \__('在顾客账户首页「我的订单」分区注入订单列表、订单状态与售后入口等内容。'),
        'doc' => 'frontend/account/index/orders.md',
    ],
    'Weline_Order::backend::order::view::before' => [
        'name' => \__('订单详情页之前'),
        'description' => \__('在订单详情页内容之前注入内容，允许其他模块在订单详情页顶部添加自定义内容。'),
        'doc' => 'backend/order/view/before.md',
    ],
    'Weline_Order::backend::order::view::after' => [
        'name' => \__('订单详情页之后'),
        'description' => \__('在订单详情页内容之后注入内容，允许其他模块在订单详情页底部添加自定义内容。'),
        'doc' => 'backend/order/view/after.md',
    ],
    'Weline_Order::backend::order::view::payment-records' => [
        'name' => \__('订单详情支付记录槽'),
        'description' => \__('订单详情「支付记录」空槽内的默认扩展点；由万能支付模块注入 Attempt 记录，禁止 Order 直读 Payment 表。'),
        'doc' => 'backend/order/view/payment-records.md',
    ],
    'Weline_Order::backend::order::view::shipments' => [
        'name' => \__('订单详情发货记录槽'),
        'description' => \__('订单详情「发货记录」空槽内的默认扩展点；由配送模块注入发货/物流记录，禁止 Order 硬编码发货表 UI。'),
        'doc' => 'backend/order/view/shipments.md',
    ],
    'Weline_Order::backend::order::view::wholesale-chat' => [
        'name' => \__('订单详情批发沟通槽'),
        'description' => \__('订单详情「批发订单沟通」空槽内的默认扩展点；由 B2B 部件注入商家侧协商消息，禁止 Order 直出聊天 UI。'),
        'doc' => 'backend/order/view/wholesale-chat.md',
    ],
    'Weline_Order::backend::order::list::filters' => [
        'name' => \__('订单列表筛选器'),
        'description' => \__('在订单列表页面的筛选器区域注入内容，允许其他模块添加自定义筛选条件。'),
        'doc' => 'backend/order/list/filters.md',
    ],
    'Weline_Order::backend::order::list::shipping' => [
        'name' => \__('订单列表发货槽'),
        'description' => \__('订单列表页发货工作台空槽；由配送模块默认注入发货入口与待发货摘要，禁止 Order 硬编码配送运营 UI。'),
        'doc' => 'backend/order/list/shipping.md',
    ],
    'Weline_Order::frontend::order::create::before' => [
        'name' => \__('前端订单创建前'),
        'description' => \__('在前端订单创建之前注入内容，允许其他模块在订单创建前执行自定义逻辑。'),
        'doc' => 'frontend/order/create/before.md',
    ],
    'Weline_Order::frontend::order::create::after' => [
        'name' => \__('前端订单创建后'),
        'description' => \__('在前端订单创建之后注入内容，允许其他模块在订单创建后执行自定义逻辑。'),
        'doc' => 'frontend/order/create/after.md',
    ],
];
