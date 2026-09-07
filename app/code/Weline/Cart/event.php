<?php

declare(strict_types=1);

/*
 * 购物车模块事件规约
 */

return [
    'Weline_Cart::cart_item_added' => [
        'name' => \__('购物车加购成功后'),
        'description' => \__('购物车加购成功后触发；追加 cart_type + type_payload（非破坏）。'),
    ],
    'Weline_Cart::cart_cleared' => [
        'name' => \__('购物车清空成功后'),
        'description' => \__('购物车清空成功后触发；追加 cart_type + type_payload（非破坏）。'),
    ],
    'Weline_Cart::cart_merged' => [
        'name' => \__('游客车合并成功后'),
        'description' => \__('游客车合并进客户车成功后触发；追加 cart_type + type_payload（非破坏）。'),
    ],
];
