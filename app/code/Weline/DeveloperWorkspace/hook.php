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
    'title' => [
        'name' => __('开发工具标题'),
        'description' => __('在开发工具面板中显示标题内容。'),
        'doc' => 'title.md',
    ],
];
