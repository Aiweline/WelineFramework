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
    'Weline_Theme::theme_editor::result_after' => [
        'name' => __('Theme editor result after'),
        'description' => __('Triggered after ThemeEditor produces a string response. Payload includes action, result, controller, and request. Observers may replace result.'),
        'doc' => 'theme_editor_result_after.md',
    ],
    'Weline_Theme_Font::warmup_collect' => [
        'name' => __('字体子集预热收集'),
        'description' => __('系统升级预热前触发。默认已自动扫描各模块 view/fonts；可向 fonts / languages 追加额外路径或语言。已有语言子集会跳过重建。'),
    ],
];
