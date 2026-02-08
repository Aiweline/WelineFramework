<?php
/**
 * Weline_DeveloperWorkspace 模块 Hook 规约文件
 * 
 * 本文件定义了 Weline_DeveloperWorkspace 模块提供的所有 Hook 扩展点
 */
return [
    // ==================== Developer Tools ====================
    'dev-tool-panel' => [
        'name' => __('开发工具面板'),
        'description' => __('在页面中显示开发工具面板，提供路由查看、API文档等功能。仅在开发模式下显示。'),
        'doc' => 'dev-tool-panel.md',
    ],
    'Weline_DeveloperWorkspace::backend::partials::dev-tool-panel::tabs-after' => [
        'name' => __('开发工具面板-扩展标签'),
        'description' => __('在开发工具面板标签栏「耗时统计」之后注入扩展标签按钮，由各模块自行实现（如 WLS 等）。'),
        'doc' => 'backend/partials/dev-tool-panel/tabs-after.md',
    ],
    'Weline_DeveloperWorkspace::backend::partials::dev-tool-panel::search-areas-after' => [
        'name' => __('开发工具面板-扩展搜索区'),
        'description' => __('在开发工具面板搜索区域之后注入扩展标签对应的搜索区 HTML 与注册脚本。'),
        'doc' => 'backend/partials/dev-tool-panel/search-areas-after.md',
    ],
    'title' => [
        'name' => __('开发工具标题'),
        'description' => __('在开发工具面板中显示标题内容。'),
        'doc' => 'title.md',
    ],
];
