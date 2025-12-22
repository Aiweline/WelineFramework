<?php

declare(strict_types=1);

/*
 * 自动寻客模块事件规约
 *
 * 仅定义本次使用到的事件：
 * - Weline_AutoLeadAgent::lead_search_task::collect_source_types
 */

return [
    'Weline_AutoLeadAgent::lead_search_task::collect_source_types' => [
        'name' => __('自动寻客来源类型收集'),
        'description' => __('用于在创建自动寻客任务前，收集各个模块提供的来源类型和配置项，例如店铺、产品等。'),
        'doc' => 'collect_source_types.md',
    ],
];


