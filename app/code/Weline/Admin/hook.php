<?php
/**
 * Weline_Admin 模块 Hook 规约文件
 *
 * 定义后台首页（system_dashboard）的扩展点，允许像素统计等模块输出真实数据。
 *
 * 命名规范：Weline_Admin::backend::layouts::dashboard::{position}
 */
return [
    // 新规范（推荐）：带 layouts 维度的布局型 Hook
    'Weline_Admin::backend::layouts::dashboard::top-statistics' => [
        'name'        => __('后台首页顶部统计卡片'),
        'description' => __('在后台首页顶部区域渲染统计卡片，例如访客数、事件数、站点数等。'),
        'doc'         => 'backend/dashboard/top-statistics.md',
    ],
    'Weline_Admin::backend::layouts::dashboard::main-overview'   => [
        'name'        => __('后台首页主要概览'),
        'description' => __('在后台首页主要内容区域渲染核心数据概览，例如趋势、热门事件等。'),
        'doc'         => 'backend/dashboard/main-overview.md',
    ],
    'Weline_Admin::backend::layouts::dashboard::main-tabs'       => [
        'name'        => __('后台首页标签页导航'),
        'description' => __('在后台首页标签页导航区域动态追加标签页按钮，例如像素统计、数据分析等。'),
        'doc'         => 'backend/dashboard/main-tabs.md',
    ],
    'Weline_Admin::backend::layouts::dashboard::main-tabs-content' => [
        'name'        => __('后台首页标签页内容'),
        'description' => __('在后台首页标签页内容区域动态追加标签页内容面板，例如像素统计面板、数据分析面板等。'),
        'doc'         => 'backend/dashboard/main-tabs-content.md',
    ],
];

