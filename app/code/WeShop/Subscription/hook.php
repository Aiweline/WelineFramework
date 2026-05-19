<?php

return [
    'WeShop_Subscription::frontend::layouts::subscription::page-before' => [
        'name' => __('订阅页前置扩展内容'),
        'description' => __('在前台订阅列表前注入扩展内容。'),
        'doc' => 'frontend/subscription/page-before.md',
    ],
    'WeShop_Subscription::frontend::layouts::subscription::item-after' => [
        'name' => __('订阅卡片后置扩展内容'),
        'description' => __('在每个订阅卡片后注入扩展内容。'),
        'doc' => 'frontend/subscription/item-after.md',
    ],
];
