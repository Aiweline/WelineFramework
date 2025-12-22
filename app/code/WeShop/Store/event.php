<?php

declare(strict_types=1);

/*
 * WeShop店铺模块事件规约
 */

return [
    'WeShop_Store::store_save_after' => [
        'name' => __('店铺保存后'),
        'description' => __('店铺保存后触发，可用于更新缓存、通知等。'),
        'doc' => 'store_save_after.md',
    ],
    'WeShop_Store::store_delete_after' => [
        'name' => __('店铺删除后'),
        'description' => __('店铺删除后触发，可用于清理相关数据。'),
        'doc' => 'store_delete_after.md',
    ],
    'WeShop_Store::store_status_change' => [
        'name' => __('店铺状态变更'),
        'description' => __('店铺状态变更时触发（启用/禁用）。'),
        'doc' => 'store_status_change.md',
    ],
];

