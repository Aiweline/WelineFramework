<?php

declare(strict_types=1);

/*
 * 生成式引擎优化模块事件规约
 */

return [
    'Weline_GenerativeEngineOptimization::feed_item_add' => [
        'name' => __('Feed条目添加'),
        'description' => __('Feed条目添加后触发，可用于处理Feed条目的相关操作。'),
        'doc' => 'feed_item_add.md',
    ],
    'Weline_GenerativeEngineOptimization::feed_item_update' => [
        'name' => __('Feed条目更新'),
        'description' => __('Feed条目更新后触发，可用于处理Feed条目的相关操作。'),
        'doc' => 'feed_item_update.md',
    ],
    'Weline_GenerativeEngineOptimization::feed_item_delete' => [
        'name' => __('Feed条目删除'),
        'description' => __('Feed条目删除后触发，可用于处理Feed条目的相关操作。'),
        'doc' => 'feed_item_delete.md',
    ],
];

