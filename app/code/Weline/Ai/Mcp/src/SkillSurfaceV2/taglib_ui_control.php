<?php

declare(strict_types=1);

/**
 * skill-v2 surface: taglib_ui_control（Taglib 控件规范）
 *
 * 可执行程序：查场景映射 -> 命中标签则用标签 -> 属性/浮层规范 -> 新增标签同回合补文档与目录。
 * 政策正文在 hard_constraints（taglib_before_hand_rolled_controls）。
 * 标准：app/code/Weline/Ai/doc/AI技能编写标准.md
 */
return [
    'id' => 'taglib_ui_control',
    'spec' => 'skill-v2',
    'label' => 'Taglib 控件规范',
    'description' => '模板领域控件必须用官方 Taglib/Hook：写 HTML 前先查场景映射表，能映射就不手写；语言/站点/货币/地址等领域选择禁自造 select 与 ISO 码 input；标签属性内禁 PHP 标签。用于新增或改后台表单、选择器、表格与浮层控件。',
    'triggers' => [
        'taglib', 'w:', '<w:', 'select', 'switcher', 'picker', '手写', '原生',
        'language:select', 'website:select', 'd-table', 'd-form',
    ],
    'authoritative_skill' => 'weline-taglib-first',
    'authoritative_doc' => 'app/code/Weline/Taglib/doc/场景映射表.md',
    'authoritative_docs' => [
        'app/code/Weline/Taglib/doc/场景映射表.md',
        'app/code/Weline/Taglib/doc/标签全量索引.md',
        'app/code/Weline/Taglib/doc/README.md',
        'app/code/Weline/Taglib/doc/如何自定义Tag.md',
        'app/code/Weline/Framework/doc/4-内置标签/README.md',
    ],
    'when' => [
        '新增/改模板里的选择性控件、表格或表单',
        '语言/站点/货币/地址/文件/ACL 等领域控件',
        '标签内下拉、浮层与就地配置交互',
        '排除：纯视觉 CSS 调整（走 frontend_development）',
    ],
    'inputs' => [
        '场景一句话：选什么、给谁用、在哪个 area',
        '模板路径与归属模块',
        '是否已有官方标签或 Hook 覆盖',
    ],
    'procedure' => [
        '先读 场景映射表 决策流，再读 标签全量索引 对应条目。',
        '命中官方标签就写标签；无覆盖才写原生 HTML，并在交付说明原因。',
        '地址/国家用 <w:theme:address>；标签内浮层用 WelineTaglibFloatingDropdown。',
        '标签属性禁 <?= / <?php；动态值落 body 元素属性。',
        '新增标签同回合更新 场景映射表/README/如何自定义Tag 并跑 taglib:catalog:generate。',
    ],
    'checklist' => [
        '每个控件都能指到场景映射表的一行',
        '无手写 language/website/currency/ACL select',
        '标签属性无 <?= / <?php',
        '新增标签已跑 taglib:catalog:generate',
        'callback 返回 HTML 无裸 @static(...)',
    ],
    'anti_patterns' => [
        '裸 <select> 拼语言/站点/货币列表',
        '手写 ISO 国家码 input 替代 <w:theme:address>',
        'callback() 里写 @static(...) 字面量',
        '业务页各写一套筛选芯片',
        '无标签覆盖却不说原因直接写原生 HTML',
    ],
    'evidence' => [
        '控件→标签映射清单（需求 / 标签 / 文档行）',
        'php bin/w taglib:catalog:generate 输出',
        '更新后的 场景映射表/README/如何自定义Tag diff',
    ],
    'norms' => [
        ['id' => 'scenario_mapping_first', 'summary' => '写 HTML/控件前先读场景映射表并选合适 Taglib（含地址/国家选择）。'],
        ['id' => 'no_hand_rolled_select', 'summary' => '禁止手写 language/website/currency/ACL 等 domain select；禁止手写 ISO 国家码 input 代替 theme:address。'],
        ['id' => 'taglib_attr_no_php', 'summary' => 'w:* / Taglib 标签属性禁止 <?= / <?php。'],
        ['id' => 'no_php_tags_in_comments', 'summary' => '注释（// # /* */ <!-- -->）禁止 <?= / <?php 开标签；非禁止普通注释掉语句。'],
        ['id' => 'chinese_comments_friendly_style', 'summary' => '新增/修改的说明性注释与 PHPDoc 默认简体中文；风格友好可读、贴合周围代码。'],
        ['id' => 'taglib_callback_static_url', 'summary' => 'Taglib callback 返回 HTML 禁裸 @static(...)；须用 Template::fetchTagSource(DataInterface::dir_type_STATICS, Module::path) 解析静态 URL。'],
    ],
    'template_surface_rules' => [
        'required' => [
            'Consult app/code/Weline/Taglib/doc/场景映射表.md before adding HTML controls',
            'Use 标签全量索引.md for the full tag catalog',
            'Resolve static URLs via Template::fetchTagSource before returning callback HTML',
        ],
        'forbidden' => [
            'Literal @static(...) inside Taglib callback()/runtime_callback() HTML return strings',
            'Hand-rolled <select>/<input> for language, website, currency, ACL, file domains',
            'PHP tags inside w:* / Taglib tag attribute values',
            'Raw HTML where an official Taglib or Hook exists in scenario mapping',
        ],
    ],
    'verification_commands' => [
        'php bin/w taglib:catalog:generate',
        'test -f app/code/Weline/Taglib/doc/场景映射表.md',
        'rg -n "<?=|<\\?php" app/code/<Vendor>/<Module>/view/templates --glob "*.phtml"',
    ],
];
