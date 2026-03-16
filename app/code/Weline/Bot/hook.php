<?php
/**
 * Weline_Bot 模块 Hook 规约文件
 *
 * 本文件定义了 Weline_Bot 模块提供的所有 Hook 扩展点
 * Hook 命名格式：{ModuleName}::{area}::{type}::{component}::{position}
 */

return [
    // ==================== 聊天控制台侧边栏 ====================
    'Weline_Bot::backend::partials::chat-sidebar::content' => [
        'name' => __('聊天侧边栏扩展'),
        'description' => __('在聊天控制台的侧边栏区域触发，允许其他模块在侧边栏添加自定义内容，如快捷指令、历史会话列表等。'),
        'doc' => 'backend/partials/chat/sidebar/sidebar.md',
    ],
    
    // ==================== 聊天消息工具栏 ====================
    'Weline_Bot::backend::partials::chat-message-tools::content' => [
        'name' => __('消息工具栏扩展'),
        'description' => __('在聊天消息的工具栏区域触发，允许其他模块为消息添加自定义操作按钮，如复制、重新生成、收藏等。'),
        'doc' => 'backend/partials/chat/message/tools/tools.md',
    ],
];
