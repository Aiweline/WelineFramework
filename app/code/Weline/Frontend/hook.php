<?php
/**
 * Weline_Frontend 模块 Hook 规约文件
 * 
 * 本文件定义了 Weline_Frontend 模块提供的所有 Hook 扩展点
 * Hook 命名格式：{ModuleName}::{area}::{type}::{component}::{position}
 * 
 * 所有 Hook 必须在 Weline\Framework\Hook\HookInterface 中定义为常量
 */
return [
    // ==================== Frontend Header ====================
    'Weline_Frontend::frontend::partials::head::before' => [
        'name' => __('前端头部'),
        'description' => __('在前端页面的 <head> 标签内注入内容，允许其他模块在前端页面头部注入额外的 CSS、JavaScript 或其他资源。'),
        'doc' => 'frontend/head.md',
    ],
];
