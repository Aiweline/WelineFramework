<?php
return [
    // ========== 库存事件 ==========
    'WeShop_Inventory::stock_change' => [
        'name' => __('库存变更'),
        'description' => __('库存数量变更时触发（增加/减少）。'),
        'doc' => '库存变更.md',
    ],
    'WeShop_Inventory::stock_low' => [
        'name' => __('低库存警告'),
        'description' => __('库存低于阈值时触发。'),
        'doc' => '低库存警告.md',
    ],
    'WeShop_Inventory::out_of_stock' => [
        'name' => __('缺货'),
        'description' => __('产品库存归零时触发。'),
        'doc' => '缺货.md',
    ],
];
