<?php
declare(strict_types=1);

/**
 * Weline_Websites 模块 Hook 规约文件
 *
 * 本文件定义了 Weline_Websites 模块提供的所有 Hook 扩展点。
 * Hook 命名格式：{ModuleName}::{area}::{type}::{component}::{position}
 */
return [
    'Weline_Websites::backend::partials::domain::tabs' => [
        'name' => __('域名管理标签页导航'),
        'description' => __('在域名管理页面的标签页导航区域动态追加标签页按钮。其他模块（如 Server 模块的证书管理）可通过此 Hook 注入新的 Tab 按钮。'),
        'doc' => 'backend/partials/domain/tabs.md',
    ],
    'Weline_Websites::backend::partials::domain::tabs-content' => [
        'name' => __('域名管理标签页内容'),
        'description' => __('在域名管理页面的标签页内容区域动态追加标签页内容面板。与 tabs Hook 配合使用，注入对应的 Tab 内容。'),
        'doc' => 'backend/partials/domain/tabs-content.md',
    ],
    'Weline_Websites::backend::website::form::sections-after' => [
        'name' => __('网站编辑表单扩展区'),
        'description' => __('在网站编辑表单核心配置之后追加模块配置区。SEO/GEO 等模块应通过此 Hook 注入字段，Website 模块不直接依赖业务模块。'),
        'doc' => 'backend/website/form/sections-after.md',
    ],
];
