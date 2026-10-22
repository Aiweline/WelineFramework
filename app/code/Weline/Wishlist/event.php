<?php

declare(strict_types=1);

/**
 * Events dispatched by Weline_Wishlist.
 */
return [
    'Weline_Wishlist::add_to_wishlist_after' => [
        'name' => __('收藏新增加入后'),
        'description' => __('仅当商品首次写入当前收藏时派发。重复加入不派发。载荷 product_id 为店面商品 ID，created 恒为 true。'),
        'doc' => 'doc/event/add-to-wishlist-after.md',
    ],
];
