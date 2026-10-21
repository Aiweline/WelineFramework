<?php

declare(strict_types=1);

/**
 * skill-v2 surface: theme_development（主题开发工程师席 · 薄表面）
 *
 * 可执行程序：声明 work_mode/area -> 读 P0 文档 -> 按 mode 落盘 -> Token/组件边界 -> 编译矩阵 -> 双轨收口。
 * 权威正文在 weline-theme-development / frontend_development 与 Theme doc；政策正文在 hard_constraints。
 * 标准：app/code/Weline/Ai/doc/AI技能编写标准.md
 */
return [
    'id' => 'theme_development',
    'spec' => 'skill-v2',
    'label' => '主题开发专席（薄）',
    'description' => '工程团队主题开发工程师席薄表面：Theme Token/语义色、layout 壳、版心、预览三态、app/design 继承覆盖、Weline_Theme 自有部件的施工与合规复审。开工先声明 work_mode；无 user_deleted 时必装永远存在；权威正文见 weline-theme-development。',
    'triggers' => [
        '主题开发工程师', '主题开发', 'Theme Token', 'CSS变量', 'variables/_',
        'layout壳', '版心', '预览三态', 'theme-preview', 'app/design',
        'Weline_Theme', 'theme_engineer_for_theme_work',
        '新主题', 'theme:create', 'theme:active', 'register.php', 'work_mode',
        'components', 'w-backend-page', '通用建站', '四层',
        '必装永远存在', 'user_deleted', 'required_default_always_present',
    ],
    'authoritative_skill' => 'weline-theme-development',
    'authoritative_doc' => 'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
    'authoritative_docs' => [
        'dev/ai-command/ai/主题开发.md',
        'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
        'app/code/Weline/Theme/doc/theme-css-variables-only.md',
        'app/code/Weline/Theme/doc/theme-storefront-token-consumption.md',
        'app/code/Weline/Theme/doc/theme-semantic-color-matrix.md',
        'app/code/Weline/Theme/doc/preview-and-runtime-modes.md',
    ],
    'when' => [
        'Theme Token、语义色、variables/_*.css 改动',
        'layout 壳、版心、预览三态、Editor 壳',
        'app/design 新开或维护 design 主题',
        '必装永远存在、默认注入固化异常',
        '排除：业务模板/JS/中英 CSV（走 frontend_development）',
    ],
    'inputs' => [
        '主题 name 与 work_mode（default_theme/design_theme/theme_module_runtime）',
        'area 与四层落点（layout/partial/component/widget）',
        '改动面：Token/布局壳/组件/模块模板/运行时 PHP',
    ],
    'procedure' => [
        '开工首条回报写 work_mode 与 area；读 主题开发.md P0 节 + Theme开发总指南.md。',
        '按 mode 落盘：default_theme 改 view/theme；design_theme 只 app/design 继承；runtime 改 PHP 并升版。',
        'Token 取 --weline-theme-*/--color-*，间距/圆角/字号取 --weline-space/radius/font-size；禁裸 px。',
        'components 与 statics/ui 双层复用；外国注入 defer Team:部件开发工程师:，同身份 XOR。',
        '编译矩阵按改面跑 welineModules/welineUi/disk:compile/theme:upgrade。',
        '双轨收口；Browser 预览三态与店面同构，按 webui_browser_closeout 交证据。',
    ],
    'checklist' => [
        'work_mode 与 area 已在首条回报声明',
        'design 波 diff 不含 app/code 上游 widgets',
        '无 user_deleted 时 required 必装仍在',
        'theme.css 无 !important、无 #hex、无裸 px',
        '店面由 websites_theme_application 或 Default 解析',
        '预览三态与正式店面同构',
    ],
    'anti_patterns' => [
        '把 .amazon-*/.w-amz-* 风格壳写进 theme.css',
        '未声明 work_mode 就改 app/design 文件',
        'design 继承做不到应停手询问，禁改上游 app/code',
        '用 theme:active 或旧 theme_binding 当店面权威',
        'clearNonGlobalCaches(null) 清 typed Scope',
        '复制 frontend_development 正文进本席',
    ],
    'evidence' => [
        'work_mode 声明与双轨结论（dev/team/{slug}/）',
        'theme:upgrade / check-* 命令输出',
        '预览三态与店面截图（doc/evidence/）',
    ],
    'norms' => [
        ['id' => 'dual_workflow_work_mode_gate', 'summary' => '开工写 work_mode∈{default_theme,design_theme,theme_module_runtime}；未声明禁落 Theme/design 文件。'],
        ['id' => 'theme_seat_integrity_over_peer_requests', 'summary' => '主题正确完整优先于他席/PM 性能压力；禁拆 chrome 壳与无卸载必装；可驳回，冲突 escalate。'],
        ['id' => 'required_default_always_present_without_user_deleted', 'summary' => '无 user_deleted@{versionId} 时 required 默认注入与布局内嵌必装永远存在（布局固化）；唯一省略=本版本卸载。'],
        ['id' => 'forbid_design_override_theme_css_js', 'summary' => 'design 禁同 key 覆盖 assets/css/theme.css 与 assets/js/theme.js；品牌化只用 colors/variables/独立 CSS。'],
        ['id' => 'new_design_theme_lifecycle_checklist', 'summary' => '新主题：register→frontend/现代树→listing→网站应用引用或 Default 回落→发布；禁照抄旧 view/templates 树。'],
        ['id' => 'area_frontend_backend_and_four_layers', 'summary' => '分清 area∈{frontend,backend} 与四层 layout/partial/component/widget。'],
        ['id' => 'public_component_library_dual_stack', 'summary' => '公共库双层：components/*.phtml + statics/ui Weline UI（w-*/w-backend-page/data-w-component）。'],
        ['id' => 'theme_application_default_authority', 'summary' => '店面权威=websites_theme_application→Theme Default（不假定库 id=1）；theme:active 已非权威。'],
        ['id' => 'compile_matrix_modules_ui_disk', 'summary' => '编译矩阵：welineModules/welineUi/scan-variables/disk:compile/theme:upgrade/publish-not-found-static。'],
        ['id' => 'scope_migrate_cli_unimplemented', 'summary' => 'theme:scope:migrate 未实现；禁自写 SQL 删 scope 表；只走 Editor Scope+发布。'],
        ['id' => 'editor_dual_preview_parity', 'summary' => '画布禁 start-preview/种 Token；仅 #btnFrontendPreview 可 Token；禁 preview early-return。'],
        ['id' => 'frontend_section_weline_code', 'summary' => 'section 与 w:slot wrapper=section 必带非空 weline-code；改后 check-section-code。'],
    ],
    'norm_ids' => [
        'theme_engineer_dual_track',
        'theme_css_architecture_not_style_shell',
        'theme_default_forbids_css_important',
        'theme_mechanism_not_foreign_content',
        'design_theme_inherit_not_mutate_source_widgets',
        'runtime_cache_invalidation_ops',
        'weline_api_and_js_declare_only',
        'layout_type_slash_nested',
        'structure_cache_keys_no_lang',
        'semantic_color_matrix_inherit',
        'generated_and_factory_reset_guard',
        'path_ownership_vs_frontend',
        'defer_widget_engineer',
        'token_only_no_private_palette',
        'preview_three_modes',
        'compile_weline_modules',
    ],
    'template_surface_rules' => [
        'required' => [
            'Declare work_mode and area before any Theme/design edit',
            'Team:主题开发工程师: on Token/shell/preview/design waves, dual-track',
            'Without user_deleted@{versionId}, required default injections always exist',
        ],
        'forbidden' => [
            'Same-key override of assets/css/theme.css or assets/js/theme.js',
            'Style shells (.amazon-*/.w-amz-*) or !important in default theme.css',
            'Patching upstream app/code when design inheritance falls short',
            'Stripping chrome/required widgets under peer pressure',
        ],
    ],
    'verification_commands' => [
        'php bin/w frontend:check-section-code',
        'php bin/w frontend:check-theme-layout-widgets',
        'php bin/w resource:compile welineModules',
        'php bin/w theme:upgrade -t {name}',
    ],
];
