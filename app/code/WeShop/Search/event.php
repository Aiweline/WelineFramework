<?php
return [
    // ========== 搜索事件 ==========
    'WeShop_Search::search_before' => [
        'name' => __('搜索前'),
        'description' => __('执行搜索前触发，可用于记录搜索日志、修改搜索参数等。'),
        'doc' => 'doc/event/event/search_before.md',
    ],
    'WeShop_Search::search_after' => [
        'name' => __('搜索后'),
        'description' => __('执行搜索后触发，可用于统计搜索数据、缓存搜索结果等。'),
        'doc' => 'doc/event/event/search_after.md',
    ],
];
