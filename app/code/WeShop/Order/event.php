<?php

return [
    'WeShop_Order::payment_status_changed' => [
        'name' => __('订单支付状态已变更'),
        'description' => __('订单支付状态变化后触发，负载包含 order、order_id、customer_id、old_payment_status、new_payment_status。'),
        'doc' => 'payment_status_changed.md',
    ],
    'WeShop_Order::order_status_changed' => [
        'name' => __('订单状态已变更'),
        'description' => __('订单业务状态变化后触发，负载包含 order、order_id、customer_id、old_status、new_status。'),
        'doc' => 'order_status_changed.md',
    ],
    'WeShop_Order::cancelled' => [
        'name' => __('订单已取消'),
        'description' => __('订单取消后触发，负载包含 order、order_id、customer_id。'),
        'doc' => 'cancelled.md',
    ],
];
