<?php

declare(strict_types=1);

/*
 * 订单模块事件规约
 */

return [
    'Weline_Order::order_created' => [
        'name' => __('订单创建后'),
        'description' => __('订单创建后触发，可用于处理订单创建后的相关操作。'),
        'doc' => 'order_created.md',
    ],
    'Weline_Order::order_updated' => [
        'name' => __('订单更新后'),
        'description' => __('订单更新后触发，可用于处理订单更新后的相关操作。'),
        'doc' => 'order_updated.md',
    ],
    'Weline_Order::order_status_change_before' => [
        'name' => __('订单状态变更前'),
        'description' => __('订单状态变更前触发，可用于验证或阻止状态变更。'),
        'doc' => 'order_status_change_before.md',
    ],
    'Weline_Order::order_status_changed' => [
        'name' => __('订单状态变更后'),
        'description' => __('订单状态变更后触发，可用于处理状态变更后的相关操作。'),
        'doc' => 'order_status_changed.md',
    ],
    'Weline_Order::order_status_can_transition' => [
        'name' => __('订单状态转换规则检查'),
        'description' => __('检查订单状态是否可以转换，允许其他模块扩展转换规则。'),
        'doc' => 'order_status_can_transition.md',
    ],
    'Weline_Order::order_status_save_before' => [
        'name' => __('订单状态保存前'),
        'description' => __('订单状态保存前触发，允许其他模块在状态保存前进行验证或修改。'),
        'doc' => 'order_status_save_before.md',
    ],
    'Weline_Order::order_status_saved' => [
        'name' => __('订单状态保存后'),
        'description' => __('订单状态保存后触发，可用于处理状态保存后的相关操作。'),
        'doc' => 'order_status_saved.md',
    ],
    'Weline_Order::order_status_delete_before' => [
        'name' => __('订单状态删除前'),
        'description' => __('订单状态删除前触发，允许其他模块在状态删除前进行验证或阻止。'),
        'doc' => 'order_status_delete_before.md',
    ],
    'Weline_Order::order_status_deleted' => [
        'name' => __('订单状态删除后'),
        'description' => __('订单状态删除后触发，可用于处理状态删除后的相关操作。'),
        'doc' => 'order_status_deleted.md',
    ],
    'Weline_Order::order_paid' => [
        'name' => __('订单支付后'),
        'description' => __('订单支付后触发，可用于处理支付后的相关操作。'),
        'doc' => 'order_paid.md',
    ],
    'Weline_Order::order_shipped' => [
        'name' => __('订单发货后'),
        'description' => __('订单发货后触发，可用于处理发货后的相关操作。'),
        'doc' => 'order_shipped.md',
    ],
    'Weline_Order::order_completed' => [
        'name' => __('订单完成后'),
        'description' => __('订单完成后触发，可用于处理完成后的相关操作。'),
        'doc' => 'order_completed.md',
    ],
    'Weline_Order::order_cancelled' => [
        'name' => __('订单取消后'),
        'description' => __('订单取消后触发，可用于处理取消后的相关操作。'),
        'doc' => 'order_cancelled.md',
    ],
    'Weline_Order::order_refunded' => [
        'name' => __('订单退款后'),
        'description' => __('订单退款后触发，可用于处理退款后的相关操作。'),
        'doc' => 'order_refunded.md',
    ],
    'Weline_Order::query::get_status_label' => [
        'name' => __('获取订单状态标签'),
        'description' => __('获取订单状态标签，允许其他模块扩展状态标签。'),
        'doc' => 'query_get_status_label.md',
    ],
    'Weline_Order::query::get_status_class' => [
        'name' => __('获取订单状态CSS类'),
        'description' => __('获取订单状态CSS类，允许其他模块扩展状态样式。'),
        'doc' => 'query_get_status_class.md',
    ],
    'Weline_Order::query::get_payment_status_label' => [
        'name' => __('获取支付状态标签'),
        'description' => __('获取支付状态标签，允许其他模块扩展支付状态标签。'),
        'doc' => 'query_get_payment_status_label.md',
    ],
    'Weline_Order::query::get_fulfillment_status_label' => [
        'name' => __('获取发货状态标签'),
        'description' => __('获取发货状态标签，允许其他模块扩展发货状态标签。'),
        'doc' => 'query_get_fulfillment_status_label.md',
    ],
    'Weline_Order::domain::resolve_status_info' => [
        'name' => __('解析完整状态信息'),
        'description' => __('解析完整状态信息，允许其他模块扩展状态信息解析。'),
        'doc' => 'domain_resolve_status_info.md',
    ],
];
