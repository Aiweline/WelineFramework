<?php
/**
 * Theme 模块事件定义
 */
return [
    'Weline_Theme::notification' => [
        'name' => __('主题通知事件'),
        'description' => __('在主题发布、预览等操作时触发，允许其他模块监听并处理通知。
事件数据：
- type: 通知类型 (publish_success, publish_failure, cdn_purge_failure 等)
- data: 通知数据
- timestamp: 时间戳'),
    ],
    'Weline_Theme::build_preview_url' => [
        'name' => __('构建预览 URL 事件'),
        'description' => __('在构建前端预览 URL 时触发，允许其他模块（如多站点模块）修改基础 URL。
事件数据：
- base_url: 基础 URL（可修改）
- theme_id: 主题ID
- page_type: 页面类型'),
    ],
];
