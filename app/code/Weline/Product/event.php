<?php

declare(strict_types=1);

/**
 * Events dispatched by Weline_Product (storefront / catalog / quotes).
 */
return [
    'Weline_Product::product_viewed' => [
        'name' => __('商品详情已浏览'),
        'description' => __('前台商品详情成功解析报价后派发，供最近浏览、联盟等监听。'),
        'doc' => 'doc/开发日志.md',
    ],
    'Weline_Product::storefront_offers_filter' => [
        'name' => __('店面报价列表筛选'),
        'description' => __('分类/商品列表在价格与排序之后派发，供 Filters 等模块应用 af_* 属性面筛选并回写 offers。'),
        'doc' => 'doc/开发日志.md',
    ],
    'Weline_Product::quote_request_submitted' => [
        'name' => __('商品询价已提交'),
        'description' => __('前台询价单落库成功后派发（含 quote_request_id / status=new）。'),
        'doc' => 'doc/event/quote_request_submitted.md',
    ],
    'Weline_Product::quote_request_status_can_transition' => [
        'name' => __('商品询价状态可否流转'),
        'description' => __('状态机规则检查时可扩展/改写 can_transition 与 transitions。'),
        'doc' => 'doc/event/quote_request_status_can_transition.md',
    ],
    'Weline_Product::quote_request_status_change_before' => [
        'name' => __('商品询价状态变更前'),
        'description' => __('写库前派发；观察者可将 can_change=false 阻止流转。'),
        'doc' => 'doc/event/quote_request_status_change_before.md',
    ],
    'Weline_Product::quote_request_status_changed' => [
        'name' => __('商品询价状态已变更'),
        'description' => __('写库成功后派发；含 old_status / new_status / quote_request_id。'),
        'doc' => 'doc/event/quote_request_status_changed.md',
    ],
];
