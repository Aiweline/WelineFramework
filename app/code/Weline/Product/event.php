<?php

declare(strict_types=1);

/**
 * Events dispatched by Weline_Product (storefront / catalog).
 */
return [
    'Weline_Product::product_viewed' => [
        'name' => __('商品详情已浏览'),
        'description' => __('前台商品详情成功解析报价后派发，供最近浏览、联盟等监听。'),
        'doc' => 'doc/开发日志.md',
    ],
];
