<?php

declare(strict_types=1);

/**
 * skill-v2 surface: frontend_development（前端开发：模板 / Token / BinQuery / 中英 CSV）
 *
 * 可执行程序：定层 -> 取 Token 与 w-* -> Taglib/URL/浮层 -> BinQuery -> 简中源串+中英 CSV -> 门禁与 Browser 证据。
 * 政策正文在 hard_constraints，此处只留判据与规则索引（norm_ids）。
 * 标准：app/code/Weline/Ai/doc/AI技能编写标准.md
 */
return [
    'id' => 'frontend_development',
    'spec' => 'skill-v2',
    'label' => '前端开发规范',
    'description' => '前台/后台模板与业务 CSS 的可执行程序：先定层，视觉只用 Theme Token 与 Weline UI 2.0；浏览器业务数据只走 BinQuery，禁原生 fetch/axios 与 HTTP 回退。前端开发语言默认简体中文：双语靠 zh_Hans_CN.csv + en_US.csv（不是 CSS），改文案同回合补 CSV 并 i18n:collect。',
    'triggers' => [
        '部件', 'widget', '主题', 'theme', '布局', 'layout', 'partial',
        'phtml', '前端', 'frontend', '模板', 'section', 'slot',
        'UI', 'ui', 'frontend-design', '样式', '颜色', '间距',
        'css', 'CSS', 'stylesheet', 'prototype', '原型',
        'BinQuery', 'query-bin', 'Weline.Api', 'fetch', 'axios',
        '文案', '翻译', 'i18n', 'csv', '中文', '双语', 'zh_Hans', 'en_US',
    ],
    'authoritative_skill' => 'weline-theme-development',
    'required_companion_skills' => [
        'frontend-design',
        'prototype',
        'design_taste',
        'weline-theme-development',
        'template_i18n',
        'module_i18n_csv',
    ],
    'authoritative_doc' => 'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
    'authoritative_docs' => [
        'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
        'app/code/Weline/Theme/doc/theme-css-variables-only.md',
        'app/code/Weline/Theme/doc/设计品味.md',
        'app/code/Weline/I18n/doc/模块翻译CSV规范.md',
        'app/code/Weline/Frontend/doc/Weline.Api使用指南.md',
        'app/code/Weline/Framework/doc/BinQuery/README.md',
    ],
    'when' => [
        '改 .phtml/partial/layout/部件模板（前后台）',
        '改业务 CSS、颜色、间距、版心',
        '菜单/ACL/模板文案改中文并补中英 CSV',
        '后台管理页对齐 w-backend-page/w-card/w-field',
        '排除：纯 Model/Service 无模板（N/A）',
    ],
    'inputs' => [
        '目标文件路径与 area（frontend/backend）',
        '改动层：layout/partial/component/widget',
        '模块 i18n 目录与默认站点语言',
    ],
    'procedure' => [
        '读 Theme开发总指南.md、theme-css-variables-only.md 与 设计品味.md。',
        'CSS/主题按 prototype→design_taste(6问)→frontend-design→weline-theme-development 加载。',
        '先定层再落文件：layout/partial/component/widget；禁改 generated/ 与 view/tpl。',
        '视觉只取 Token 与 w-*；后台页对齐 w-backend-page/w-card/w-field/w-table。',
        '控件查 场景映射表：区域 <w:theme:address>、浮层 Weline.UI、站内 URL 用 @url/<url> 或 Url::getUrl。',
        '业务数据只走 Weline.Api→worker/query-bin；源串简中+同回合补 zh_Hans_CN.csv/en_US.csv 并 i18n:collect。',
        '跑 verification_commands，按 webui_browser_closeout 交证据与「交付地址」。',
    ],
    'checklist' => [
        '无第三方 UI 类、无 #hex/rgb、无裸 px',
        '业务请求只有 Weline.Api / query-bin',
        '区域控件为 <w:theme:address>',
        'zh_Hans_CN.csv 与 en_US.csv 同键，en 列英文',
        'section 与 slot wrapper=section 带 weline-code',
        '768/1024 已验，375 相关时也验',
        '四条 verification_commands 全过',
    ],
    'anti_patterns' => [
        'Bootstrap/Element/Ant 类名或内联 style 拼视觉',
        '.w-container 内叠 max-width+padding-inline 双 gutter',
        '原生 fetch/axios 当主链、BinQuery 当回退',
        '英文当默认源串；en_US 留中文',
        '手写 ISO input 替代 <w:theme:address>',
        '改 generated/ 或 view/tpl 当源',
        '裸 <img src> 无 width+height（CLS）',
    ],
    'evidence' => [
        'frontend:check-section-code / check-theme-layout-widgets 输出',
        'i18n:collect 输出 + zh_Hans_CN.csv/en_US.csv diff',
        'Browser 截图路径 + 交付地址小节',
    ],
    'norms' => [
        ['id' => 'browser_api_binquery_default', 'summary' => '业务 I/O 只 Weline.Api→worker/query-bin；禁原生 fetch/XMLHttpRequest/$.ajax/axios，禁 BinQuery 当回退。'],
        ['id' => 'frontend_dev_language_chinese_default', 'summary' => '用户可见源串默认简体中文；禁英文当默认源串。'],
        ['id' => 'frontend_ui_requires_zh_en_csv', 'summary' => '同回合齐 zh_Hans_CN.csv（中文身份）+ en_US.csv（真实英文），改后 i18n:collect。'],
        ['id' => 'theme_address_for_region_pickers', 'summary' => '国家/省/市/区必须 <w:theme:address>；禁手写 select、ISO input、自造 chips。'],
        ['id' => 'weline_ui_floating_primitives', 'summary' => '浮层用 Weline.UI.dialog / anchored-float；禁私造 modal、手写 left/top。'],
        ['id' => 'section_identity', 'summary' => 'section 与 w:slot wrapper="section" 必带非空 weline-code；改后 check-section-code。'],
        ['id' => 'weline_ui_theme_first', 'summary' => '视觉只用 Weline UI 2.0（w-field/w-input/w-button…）+ Theme Token；禁第三方 UI 与硬编码色值。'],
        ['id' => 'ui_skill_requires_theme_skill', 'summary' => '用 UI/原型技能写界面也必须先 get_skill(weline-theme-development) 并服从 Token；即便用户未提 CSS。', 'authoritative_skill' => 'weline-theme-development'],
        ['id' => 'css_or_theme_requires_ui_prototype_theme_skills', 'summary' => '凡提 CSS/主题：先加载 frontend-design + prototype + weline-theme-development，再写样式；禁自造色板。', 'required_companion_skills' => ['frontend-design', 'prototype', 'weline-theme-development']],
        ['id' => 'backend_admin_ui_requires_frontend_theme_skills', 'summary' => '后台页先 get_skill(frontend_development|weline-theme-development)+Read Theme开发总指南；对齐 w-backend-page/w-card/w-field，搜索条紧凑。', 'authoritative_skill' => 'weline-theme-development'],
        ['id' => 'theme_layout_widget_owner', 'summary' => '同模块才可 <w:widget> 内嵌；跨模块只空槽 + 拥有模块 default_injections；禁同部件双路径。'],
        ['id' => 'required_default_always_present_without_user_deleted', 'summary' => '无 user_deleted@{versionId} 时 required 默认注入与布局内嵌必装永远存在；遗漏归因固化方案。'],
    ],
    'norm_ids' => [
        'theme_preview_runtime_three_modes',
        'theme_default_forbids_css_important',
        'theme_design_inherit_stop_ask_on_gap',
        'storefront_internal_url_via_url_helper',
        'layer_choice',
        'no_generated_edit',
        'taglib_attr_no_php',
        'no_php_tags_in_comments',
        'chinese_comments_friendly_style',
        'widget_external_js',
        'theme_js_module_declare_only',
        'weline_js_loader_framework_only',
        'slot_fallback_no_demo',
        'css_variables_only',
        'layout_content_width_tokens',
        'taglib_before_hand_rolled_controls',
        'responsive_tablet_pc',
        'docs_reconcile_per_feature',
        'feature_delivery_urls',
    ],
    'template_surface_rules' => [
        'required' => [
            'Use Simplified Chinese sources; keep zh_Hans_CN.csv + en_US.csv aligned',
            'Visual UI only via Weline UI 2.0 + theme CSS variable tokens',
            'Browser data only via Weline.Api → query-bin (BinQuery), no HTTP fallback',
        ],
        'forbidden' => [
            'English default source strings instead of Simplified Chinese',
            'Native fetch/ajax as business I/O or as BinQuery fallback',
            'Editing generated/ or view/tpl; demo copy in slot <else/>',
        ],
    ],
    'verification_commands' => [
        'php bin/w frontend:check-section-code',
        'php bin/w frontend:check-theme-layout-widgets',
        'php bin/w resource:compile welineModules',
        'php bin/w i18n:collect Weline_Module',
    ],
];
