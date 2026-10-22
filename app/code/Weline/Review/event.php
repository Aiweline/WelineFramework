<?php

declare(strict_types=1);

/**
 * Events dispatched by Weline_Review.
 */
return [
    'Weline_Review::review_created' => [
        'name' => __('评论已写入'),
        'description' => __('评论落库成功后派发。商品评论的 product_id 是店面商品 ID（由 global uuid 映射）；映射不到时为 0，禁止把身份注册表 ID 当作商品 ID。'),
        'doc' => 'doc/event/review-created.md',
    ],
];
