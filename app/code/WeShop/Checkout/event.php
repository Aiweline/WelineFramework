<?php

return [
    'WeShop_Checkout::order_created' => [
        'name' => __('结账订单已创建'),
        'description' => __('WeShop 结账创建订单后触发，负载包含 order、order_id、customer_id、order_items、order_summary、guest_email、notification_channels。'),
        'doc' => 'order_created.md',
    ],
];
