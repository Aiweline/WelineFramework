<?php
/**
 * Weline_Cdn 模块 Hook 规范文件
 *
 * Hook 命名格式：{Module}::{area}::{type}::{component}::{position}
 */
return [
    'Weline_Cdn::backend::partials::domain::toolbar-after' => [
        'name' => __('CDN域名管理工具栏扩展'),
        'description' => __('在CDN域名管理列表页操作栏按钮区域后注入扩展内容。'),
        'doc' => 'backend/partials/domain/toolbar-after.md',
    ],
];
