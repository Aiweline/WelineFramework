<?php

declare(strict_types=1);

/**
 * skill-v2 surface: custom_theme_development（自定义 design 主题开发路线 · 薄表面）
 *
 * 可执行程序：声明 work_mode/验收站点 → 建骨架 → 色盘/刻度 → overlay 挂盘 → 骨架 CSS → 皮肤 → 图片/JS/phtml meta → 编译矩阵 → 三档验收。
 * 权威正文：Theme/doc/开发/自定义主题开发路线.md（完整路线 + 踩坑）；机制正文见各 Theme doc；政策正文在 hard_constraints。
 * 标准：app/code/Weline/Ai/doc/AI技能编写标准.md
 */
return [
    'id' => 'custom_theme_development',
    'spec' => 'skill-v2',
    'label' => '自定义主题开发路线（design theme）',
    'description' => '在 app/design/{Vendor}/{theme}/ 从零做自定义主题的可执行路线：骨架→色盘/刻度→overlay 挂盘→骨架 CSS→皮肤→图片/JS/phtml meta→编译→三档验收。含合并机制、版心、响应式单一权威、部件/组件边界、图片预算（WebP、banner 70–120KB）、踩坑清单。任何 CSS/JS/前端主题改动必须先本技能。',
    'triggers' => [
        '自定义主题', '做主题', '新主题', 'design theme', 'theme 开发',
        'app/design', 'register.php', 'theme:install',
        '主题 CSS', '主题样式', '皮肤', 'brand css', 'overlay', 'theme-head-css-overlay',
        '版心', 'w-container', '响应式断点', 'Token', '色盘', 'variables/_', 'colors/_',
        '布局骨架', 'layout phtml', 'phtml meta', '@param', 'weline-code',
        '主题图片', 'banner 体积', 'WebP', '缩略图', 'weline.js', 'theme:js',
        'custom_theme_development', '自定义主题开发路线',
    ],
    'authoritative_skill' => 'weline-custom-theme-development',
    'authoritative_doc' => 'app/code/Weline/Theme/doc/开发/自定义主题开发路线.md',
    'authoritative_docs' => [
        'app/code/Weline/Theme/doc/开发/自定义主题开发路线.md',
        'app/code/Weline/Theme/doc/theme-unified-spec.md',
        'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
        'app/code/Weline/Theme/doc/theme-css-variables-only.md',
        'app/code/Weline/Theme/doc/theme-storefront-token-consumption.md',
        'app/code/Weline/Theme/doc/theme-css-architecture-vs-style.md',
        'app/code/Weline/Theme/doc/theme-inheritance-and-file-conventions.md',
        'app/code/Weline/Theme/doc/前端JS模块加载规范.md',
        'app/code/Weline/Theme/doc/部件开发指南.md',
        'app/code/Weline/Theme/doc/storefront-image-placeholder.md',
    ],
    'when' => [
        '新开自定义主题或给既有站做品牌皮肤',
        '主题验收建站：申请本地域名→建站→绑域名→绑主题',
        '改主题 CSS/JS/图片/phtml meta；排除默认层与 Theme PHP',
    ],
    'inputs' => [
        'work_mode=design_theme、{Vendor}/{theme} 与要覆盖的 layout/partial',
        '验收站点：本机子域 + website code（新站选该主题）',
        '品牌色/字体/图片素材与图片预算目标',
    ],
    'procedure' => [
        '建站四步：域名池→website:create→domain 行→绑主题；地址看 Host 形态（§0.1/§0.2）。',
        '建骨架：register + frontend/{colors,variables,assets,head,layouts}。',
        '色盘/刻度：colors/_*.css 只覆盖叶子；variables/_*.css 补字号与节奏。',
        '挂盘：色盘/刻度/皮肤写进 theme-head-css-overlay.php 的 common 或 layouts。',
        '骨架 CSS 先行（版心/section/栅格），再皮肤，最后动效；断点单一权威。',
        '图片 WebP + 预算（banner 70–120KB），img 给尺寸；JS 走 theme:js。',
        '编译矩阵 → 三档验收（溢出/对比度/破图/locale）→ 交付 URL 带穿章与 _wb_nc。',
    ],
    'checklist' => [
        'work_mode 已声明，改动只在 app/design/{Vendor}/{theme}/',
        '色盘/刻度已进 theme-head-css-overlay（否则不生效）',
        '版心只用 max-width + gutter',
        '断点只在一个权威文件，@media 内无 var()',
        '图片 WebP，体积/长边达标，img 有尺寸',
        'phtml 保留 showHeader/showFooter 与 weline-code',
        '验收用新站 ~site/{code} + 穿章 + _wb_nc',
        '1440/1024/390 无横向溢出、对比度 ≥4.5、破图 0',
    ],
    'anti_patterns' => [
        '在默认站/既有站临时切主题验收',
        '同 key 覆盖 assets/css/theme.css 或 theme.js',
        '品牌色盘只写文件不进 overlay，然后说变量没生效',
        '裸 px 或自造变量名',
        '自写色板绕开 --color-*',
        '模板裸 script 拉模块 JS 或 fetch',
        '改 CSS 后用无 _wb_nc 的 URL 截图验收',
    ],
    'evidence' => [
        'theme:listing 与三档截图（1440/1024/390）',
        '对比度/溢出/破图实测输出（ego 或 CDP）',
        'en_US 与 zh 页的 locale 与 CJK 统计',
        '交付 URL（新站 ~site/{code} + w_fpc_pierce + _wb_nc）',
    ],
    'norms' => [
        ['id' => 'custom_theme_acceptance_new_local_website', 'summary' => '自定义主题验收必须新开本地站选该主题；禁切默认/既有站；交付 ~site/{code}。'],
        ['id' => 'theme_design_must_inherit_not_mutate_source_widgets', 'summary' => '只 app/design 同 key 继承；禁改上游主题源。'],
        ['id' => 'theme_design_must_not_override_core_runtime_assets', 'summary' => '禁同 key 覆盖 theme.css/theme.js；品牌化只走 colors/variables/独立 CSS。'],
        ['id' => 'theme_default_forbids_css_important', 'summary' => '默认层视觉 CSS 禁 !important。'],
        ['id' => 'theme_css_architecture_not_style_shell', 'summary' => 'theme.css 只写架构（骨架/表面机制）；品牌风格壳进独立 CSS。'],
        ['id' => 'frontend_unified_content_container', 'summary' => '版心只认 max-width + gutter；禁 calc(100%-Npx)。'],
        ['id' => 'section_weline_code', 'summary' => 'section 与 slot wrapper=section 必带非空 weline-code；改后跑 check-section-code。'],
        ['id' => 'web_images_webp_compressed', 'summary' => '图片终态 WebP，按展示尺寸派生；banner 实践 70–120KB，Hero 上限 350KB。'],
        ['id' => 'weline_js_loader_framework_only', 'summary' => 'weline.js 只做加载引擎；业务 JS 走模块声明。'],
        ['id' => 'theme_js_module_declare_only', 'summary' => '前台业务 JS 只走 weline.modules + data-weline-load/declare；禁 fetch/XHR。'],
        ['id' => 'widget_static_assets_bake_to_head', 'summary' => '部件静态 CSS/JS 用 layout-source 由布局 bake。'],
        ['id' => 'required_default_always_present_without_user_deleted', 'summary' => '无 user_deleted 时 required 注入固化进布局；新主题首页须保留默认 slot/hook。'],
    ],
    'norm_ids' => [
        'custom_theme_acceptance_new_local_website',
        'theme_engineer_for_theme_work',
        'theme_design_must_inherit_not_mutate_source_widgets',
        'theme_design_must_not_override_core_runtime_assets',
        'theme_default_forbids_css_important',
        'theme_css_architecture_not_style_shell',
        'frontend_unified_content_container',
        'section_weline_code',
        'web_images_webp_compressed',
        'weline_js_loader_framework_only',
        'theme_js_module_declare_only',
        'widget_static_assets_bake_to_head',
        'dom_mutation_observe_via_weline_dom',
        'required_default_always_present_without_user_deleted',
    ],
    'template_surface_rules' => [
        'required' => [
            'Declare work_mode=design_theme and keep every edit under app/design/{Vendor}/{theme}',
            'Register brand palette/scale/skin in theme-head-css-overlay.php (PACK_OVERLAY)',
            'Layout skeleton (shell/band/grid) before skin; single responsive authority',
            'Verify on a NEW local website (~site/{code}) with pierce stamp and _wb_nc',
        ],
        'forbidden' => [
            'Switching the default/existing site theme for acceptance',
            'Same-key override of assets/css/theme.css or assets/js/theme.js',
            'Bare px spacing/radius/font-size or invented token names',
            'Screenshot acceptance with a URL that lacks _wb_nc after a CSS change',
        ],
    ],
    'verification_commands' => [
        'php bin/w theme:install -t {theme}',
        'php bin/w theme:listing',
        'php bin/w frontend:check-section-code',
        'php bin/w frontend:check-theme-layout-widgets',
        'php bin/w cache:clear --no-warm',
    ],
];
