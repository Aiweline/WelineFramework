<?php
/**
 * Weline_Promotion 模块 Hook 规约文件
 *
 * Hook 命名格式：{ModuleName}::{area}::{type}::{component}::{position}
 */
return [
    'Weline_Promotion::frontend::layouts::promotion::page-before' => [
        'name' => __('活动页商品列表前'),
        'description' => __('在活动页商品列表渲染前输出轻量扩展内容，适合提示、活动说明或筛选入口。'),
        'doc' => 'frontend/layouts/promotion/page-before.md',
    ],
    'Weline_Promotion::frontend::layouts::promotion::page-after' => [
        'name' => __('活动页商品列表后'),
        'description' => __('在活动页商品列表渲染后输出轻量扩展内容，适合承接推荐、权益说明或客服入口。'),
        'doc' => 'frontend/layouts/promotion/page-after.md',
    ],
];
