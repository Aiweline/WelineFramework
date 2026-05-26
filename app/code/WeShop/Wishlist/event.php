<?php

return [
    'WeShop_Wishlist::add_to_wishlist_before' => [
        'name' => __('收藏添加前'),
        'description' => __('商品加入收藏前触发，负载包含 customer_id、product_id。'),
        'doc' => 'add_to_wishlist_before.md',
    ],
    'WeShop_Wishlist::add_to_wishlist_after' => [
        'name' => __('收藏添加后'),
        'description' => __('商品加入收藏后触发，负载包含 wishlist、wishlist_id、customer_id、product_id、created。'),
        'doc' => 'add_to_wishlist_after.md',
    ],
    'WeShop_Wishlist::remove_from_wishlist_before' => [
        'name' => __('收藏移除前'),
        'description' => __('收藏条目移除前触发，负载包含 wishlist、wishlist_id、customer_id、product_id。'),
        'doc' => 'remove_from_wishlist_before.md',
    ],
    'WeShop_Wishlist::remove_from_wishlist_after' => [
        'name' => __('收藏移除后'),
        'description' => __('收藏条目移除后触发，负载包含 wishlist_id、customer_id、product_id、removed。'),
        'doc' => 'remove_from_wishlist_after.md',
    ],
];
