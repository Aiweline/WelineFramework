<?php
return [
    // ========== 产品事件 ==========
    'WeShop_Product::product_save_before' => [
        'name' => __('产品保存前'),
        'description' => __('产品保存前触发，可用于验证产品数据。'),
        'doc' => 'product_save_before.md',
    ],
    'WeShop_Product::product_save_after' => [
        'name' => __('产品保存后'),
        'description' => __('产品保存后触发，可用于更新索引、缓存、库存等。'),
        'doc' => 'product_save_after.md',
    ],
    'WeShop_Product::product_delete_before' => [
        'name' => __('产品删除前'),
        'description' => __('产品删除前触发，可用于验证是否可删除。'),
        'doc' => 'product_delete_before.md',
    ],
    'WeShop_Product::product_delete_after' => [
        'name' => __('产品删除后'),
        'description' => __('产品删除后触发，可用于清理相关数据。'),
        'doc' => 'product_delete_after.md',
    ],
    'WeShop_Product::category_save_after' => [
        'name' => __('分类保存后'),
        'description' => __('分类保存后触发，可用于更新分类缓存。'),
        'doc' => 'category_save_after.md',
    ],
    'WeShop_Product::category_delete_after' => [
        'name' => __('分类删除后'),
        'description' => __('分类删除后触发，可用于清理相关数据。'),
        'doc' => 'category_delete_after.md',
    ],
    'WeShop_Product::product_status_change' => [
        'name' => __('产品状态变更'),
        'description' => __('产品状态变更时触发（启用/禁用）。'),
        'doc' => 'product_status_change.md',
    ],
    'WeShop_Product::product_price_change' => [
        'name' => __('产品价格变更'),
        'description' => __('产品价格变更时触发。'),
        'doc' => 'product_price_change.md',
    ],
];
