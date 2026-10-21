<?php

declare(strict_types=1);

/**
 * skill-v2 surface: module_i18n_csv（模块翻译 CSV 门禁）
 *
 * 中文源串正确；中英 CSV 必须齐；官方框架模块仅中英（Git 瘦身）；
 * 用户提「翻译」覆盖默认站全语种；改 CSV 必须 i18n:collect。
 * 标准：app/code/Weline/Ai/doc/AI技能编写标准.md
 */
return [
    'id' => 'module_i18n_csv',
    'spec' => 'skill-v2',
    'label' => '模块翻译 CSV 门禁',
    'description' => '模块翻译 CSV 门禁：源串默认简体中文（正确），活跃/默认 locale 非中文须显示目标语译文。每模块至少 zh_Hans_CN.csv + en_US.csv 且 source 键对齐、en 第二列真实英文；官方框架模块 Git 瘦身仅中英 CSV（非框架模块可自备其它 locale CSV）；用户提「翻译」须覆盖默认站已选全部语言（官方模块非中英进系统词典）；改 CSV 后必须 i18n:collect。',
    'triggers' => [
        'csv', 'i18n:collect', 'en_us', 'zh_hans_cn', '翻译文件', '词典', 'collect',
        '国际化', 'locale', '语言包', '翻译', 'translate', '默认语言', '英文环境',
    ],
    'authoritative_doc' => 'app/code/Weline/I18n/doc/模块翻译CSV规范.md',
    'authoritative_docs' => [
        'app/code/Weline/I18n/doc/模块翻译CSV规范.md',
        'app/code/Weline/Framework/doc/3-开发/01-翻译函数使用指南.md',
    ],
    'when' => [
        '新增/修改模块 i18n CSV 或用户可见源串',
        '处理 i18n:collect、词典/语言包问题',
        '英文或非中文 locale 露出中文/英文源串',
        '用户把「翻译」当任务，需覆盖默认站全语种',
        '排除：模板语法细节走 template_i18n；商品翻译优化走 content_ops',
    ],
    'inputs' => [
        '归属模块与新增/变更的 source 清单',
        '默认站 language_codes（Website::ID_DEFAULT）',
        '模块 i18n/zh_Hans_CN.csv、en_US.csv 现状',
    ],
    'procedure' => [
        '源串写简体中文；zh 身份译、en 真实英文，两份 CSV 的 source 键对齐。',
        '前台新增词同步补后台同类 CSV 与 en 译文，不留下落词条。',
        '官方框架模块 i18n/ 只维护 zh_Hans_CN + en_US；其它已选 locale 写系统词典/归属实体（非框架模块可自备 CSV）。',
        '用户提「翻译」未收窄 → 解析默认站 language_codes，逐语种写真实译文，禁只译 en_US。',
        '改 CSV 后跑 php bin/w i18n:collect <模块>；禁用 cache:clear 代替。',
        '抽检活跃/默认 locale 页面，把 collect 与抽检结果记入模块 doc/开发日志.md。',
    ],
    'checklist' => [
        '源串为简体中文且非英文默认源串？',
        'zh_Hans_CN.csv + en_US.csv 存在且 source 键对齐？',
        'en_US 第二列为真实英文（非空、非中文占位）？',
        '官方模块 i18n/ 无 zh/en 以外 locale CSV？',
        '用户提「翻译」时默认站其它已选 locale 已进词典/实体？',
        '已执行 i18n:collect 并在开发日志记录？',
    ],
    'anti_patterns' => [
        '用英文当默认 i18n 源串却期待中文界面',
        'en_US 第二列仍是中文 source 却称已翻译',
        '只改 CSV 不 collect，或用 cache:clear 代替',
        '前台加 <lang> 词条却不补 en_US 行',
        '用户说翻译却只做 en_US 就收口',
        '官方模块新建 ja_JP/ru_RU 等 locale CSV',
        '把「官方模块仅中英」读成「非框架也禁其它 CSV/其它语种可不做」',
        '改模板源串为英文来「修」英文站显示',
    ],
    'evidence' => [
        '模块两份 CSV 的相关行（source + translate）',
        'i18n:collect 命令与输出',
        '活跃/默认 locale + ≥1 非中英 locale 抽检 URL/截图',
    ],
    'norms' => [
        ['id' => 'chinese_source_default', 'summary' => '模板/菜单/ACL/__() 用户可见源串默认简体中文；禁止英文源串导致中英 CSV 串列。'],
        ['id' => 'active_locale_must_show_target_language', 'summary' => '网站默认语或请求 locale 非中文时界面必须显示该语种译文；禁止因源串中文就在英文站露中文；禁止 en_US 第二列中文占位。'],
        ['id' => 'bilingual_csv_required', 'summary' => '每模块至少 zh_Hans_CN.csv + en_US.csv 且 source 键一致；官方框架模块 Git 瘦身禁其它 locale CSV；非框架模块不受此禁。'],
        ['id' => 'en_us_real_english', 'summary' => 'en_US 第二列必须是英文译文，禁止留空或把中文 source 原样当作英文。'],
        ['id' => 'user_mentions_translation_all_default_website_locales', 'summary' => '用户提翻译任务时解析默认站 language_codes（website_id=0）并逐语种写真实译文；官方模块 CSV 仅中英、其它进系统词典；非框架模块可自备其它 locale CSV；禁只译 en_US。'],
        ['id' => 'frontend_backend_csv_sync', 'summary' => '前台加词须同步补后台 CSV 与 en 译文。'],
        ['id' => 'collect_mandatory', 'summary' => 'CSV 处理后必须 i18n:collect，禁止只改文件不收集。'],
    ],
    'template_surface_rules' => ['required' => [], 'forbidden' => []],
    'verification_commands' => [
        'php bin/w i18n:collect Weline_Module',
        'test -f app/code/Weline/I18n/doc/模块翻译CSV规范.md',
    ],
];
