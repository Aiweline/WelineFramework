<?php

declare(strict_types=1);

/**
 * skill-v2 surface: template_i18n（前台模板 i18n 规范）
 *
 * .phtml 用户可见文案用 <lang>/@lang；HTML 内禁 __()；@lang 含逗号须引号。
 * 源串默认简体中文，中英靠模块 CSV + i18n:collect。
 * 标准：app/code/Weline/Ai/doc/AI技能编写标准.md
 */
return [
    'id' => 'template_i18n',
    'spec' => 'skill-v2',
    'label' => '模板 i18n 规范',
    'description' => '前台 .phtml 用户可见文案用 <lang> 或 @lang()，HTML 正文/属性禁 __()。@lang()/{} 源文含逗号须加引号或改用 <lang>，否则编译 ParseError。源串默认简体中文；中英双语靠模块 zh_Hans_CN.csv + en_US.csv（en 第二列真实英文），改后必须 i18n:collect。官方模块禁非中英模块 CSV。',
    'triggers' => [
        '__', 'lang', '翻译', 'i18n', 'phtml', '文案', 'csv',
        'en_us', 'zh_hans_cn', '@lang', 'ParseError',
    ],
    'authoritative_doc' => 'app/code/Weline/I18n/doc/模块翻译CSV规范.md',
    'authoritative_docs' => [
        'app/code/Weline/I18n/doc/模块翻译CSV规范.md',
        'app/code/Weline/Framework/doc/4-内置标签/01-lang标签使用指南.md',
        'app/code/Weline/Framework/doc/3-开发/01-翻译函数使用指南.md',
        'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
    ],
    'when' => [
        '前台 .phtml 新增或修改用户可见文案',
        '模板出现 __()、@lang、<lang> 或编译 ParseError',
        '英文/非中文 locale 露出中文或英文源串',
        '新增或改模块 i18n CSV 后要 collect',
        '排除：商品/详情多语字段走 content_ops 翻译优化',
    ],
    'inputs' => [
        '目标 .phtml 与声明该模板的归属模块',
        '活跃/默认 locale（用于确认应显示语种）',
        '模块 i18n/zh_Hans_CN.csv + en_US.csv 现状',
    ],
    'procedure' => [
        '定位文案所在 .phtml 与归属模块；跨模块覆写按实际声明该模板的模块归属。',
        '源串写简体中文；HTML 正文/属性用 <lang>…</lang> 或 @lang(…)，禁 <?= __() ?>。',
        '源文含逗号/引号：用 <lang>a, b</lang> 或带引号的 @lang(\'a, b\') / @lang{"a, b"}。',
        '在归属模块两份 CSV 补同一中文 source：zh 身份译、en 真实英文译文。',
        '运行 php bin/w i18n:collect <归属模块>，再切 en_US/非中文 locale 抽检页面。',
        '官方框架模块 i18n/ 只保留 zh_Hans_CN.csv + en_US.csv；其它已选 locale 走系统词典/实体。',
    ],
    'checklist' => [
        'HTML 正文/属性无 <?= __() ?> 或 __() 直出？',
        '源串为简体中文，非英文默认源串？',
        '含逗号的 @lang 已加引号或改用 <lang>，无 ParseError？',
        'zh_Hans_CN.csv / en_US.csv 均有该 source，en 列为真实英文？',
        '已执行 i18n:collect 归属模块？',
        '官方模块 i18n/ 下无非中英 locale CSV？',
    ],
    'anti_patterns' => [
        '在 .phtml HTML 正文或属性写 <?= __(\'…\') ?>',
        '@lang{支持 .ico, .png} 不加引号导致 ParseError',
        '用英文当默认源串（__(\'Role Management\') / 英文 menu title）',
        'en_US 第二列留空或原样中文占位',
        '英文环境露中文 source，或为「修」显示把模板改英文',
        '改 CSV 不 collect 就宣称翻译完成',
        '官方模块新建 ja_JP.csv / ru_RU.csv',
        '新加 <lang> 文案却不补 en_US 行',
    ],
    'evidence' => [
        '改动 .phtml 路径 + 归属模块 CSV 相关行',
        'i18n:collect 命令与输出',
        'en_US 与 ≥1 非中文 locale 页面 URL/截图',
    ],
    'norms' => [
        ['id' => 'lang_tag_not_php', 'summary' => 'HTML 正文/属性用 <lang>/@lang，不用 <?= __() ?>；__() 只用于 PHP 逻辑层。'],
        ['id' => 'at_lang_no_unquoted_comma', 'summary' => '@lang()/{} 源文含逗号必须加引号或改用 <lang>；禁止 @lang{a, b} 造成编译 ParseError。'],
        ['id' => 'chinese_source_default', 'summary' => '模板/菜单/ACL/__() 用户可见源串默认简体中文；禁止英文当默认源串。'],
        ['id' => 'active_locale_must_show_target_language', 'summary' => '活跃/默认 locale 非中文时必须显示该语种译文；禁止因源串是中文就在英文站露中文；禁止 en_US 第二列中文占位。'],
        ['id' => 'csv_bilingual_aligned', 'summary' => 'zh_Hans_CN.csv 与 en_US.csv source 键对齐；en_US 第二列必须英文译文，禁留空或中文 source 当英文。'],
        ['id' => 'collect_after_csv', 'summary' => '改 CSV 或新增源串后必须 php bin/w i18n:collect，否则运行时词典不更新。'],
        ['id' => 'en_us_no_chinese_placeholder', 'summary' => '交付前抽检 en_US：用户可见词条第二列不得仍为纯中文（与 source 相同）。'],
        ['id' => 'user_mentions_translation_all_default_website_locales', 'summary' => '用户把「翻译」当任务时按默认站已选语言全语种译写；官方模块 CSV 仅 zh_Hans_CN+en_US、其它进系统词典（非框架模块可自备其它 CSV）；禁止只译 en_US。'],
    ],
    'template_surface_rules' => ['required' => [], 'forbidden' => []],
    'verification_commands' => [
        'php bin/w i18n:collect Weline_Module',
        'php bin/w i18n:collect',
    ],
];
