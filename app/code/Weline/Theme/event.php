<?php
/**
 * Theme 模块事件定义
 */
return [
    'Weline_Theme::notification' => [
        'name' => __('主题通知事件'),
        // 长字段说明禁止进 __()，避免被采集进词典
        'description' => __('主题发布、预览等操作时触发通知；payload 含 type/data/timestamp。'),
    ],
    'Weline_Theme::build_preview_url' => [
        'name' => __('构建预览 URL 事件'),
        'description' => __('构建前端预览 URL 时触发，可改 base_url；另含 theme_id/page_type。'),
    ],
    'Weline_Theme::theme_editor::result_after' => [
        'name' => __('Theme editor result after'),
        'description' => __('Triggered after ThemeEditor produces a string response. Payload includes action, result, controller, and request. Observers may replace result.'),
        'doc' => 'theme_editor_result_after.md',
    ],
    'Weline_Theme::layout_resolve' => [
        'name' => __('Layout path resolve'),
        'description' => __('Modules claim layout_path / entity_slug from the request path during FetchFileBefore when layoutType is empty.'),
        'doc' => 'layout_resolve.md',
    ],
    'Weline_Theme_Font::warmup_collect' => [
        'name' => __('字体子集预热收集'),
        'description' => __('系统升级预热前触发。默认已自动扫描各模块 view/fonts，语言列表为 charset 文件 ∪ 网站已启用语种；可向 fonts / languages 追加额外路径或语言。已有语言子集会跳过重建。'),
    ],
];
