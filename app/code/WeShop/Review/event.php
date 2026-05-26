<?php

return [
    'WeShop_Review::review_created' => [
        'name' => __('评价已创建'),
        'description' => __('商品评价创建后触发，负载包含 review、review_id、product_id、customer_id、rating、status。'),
        'doc' => 'review_created.md',
    ],
    'WeShop_Review::review_status_changed' => [
        'name' => __('评价状态已变更'),
        'description' => __('评价审核状态变化后触发，负载包含 review、review_id、product_id、customer_id、old_status、new_status。'),
        'doc' => 'review_status_changed.md',
    ],
];
