<?php

declare(strict_types=1);

/**
 * skill-v2 surface: translation_engineer（工程团队「翻译工程师」专席，别名 i18n）
 *
 * 全站界面/流程文案与活跃·默认 locale 匹配巡检，发现漏译即译修。
 * 固定 collect 先行；官方框架模块 CSV 仅 zh_Hans_CN + en_US。
 * 标准：app/code/Weline/Ai/doc/AI技能编写标准.md
 */
return [
    'id' => 'translation_engineer',
    'spec' => 'skill-v2',
    'label' => '翻译工程师（全站漏译巡检 + 中英 CSV）',
    'description' => '「翻译工程师」专席（别名 i18n）：全站界面/流程文案与活跃·默认 locale 匹配巡检，漏译即译修（品牌/商标/专有名词除外）。collect 先行：解析默认站 language_codes → i18n:collect → 中文源串 → 对比缺口 → 译修 → 再 collect → 抽检 ≥1 非中英。官方模块 CSV 仅 zh_Hans_CN+en_US，其它已选 locale 进词典/实体。不代跑 content_ops 商品翻译。',
    'triggers' => [
        '翻译工程师', 'Team:翻译工程师', 'Team:i18n',
        '漏译', 'locale leak', '界面文案翻译', '流程提示翻译',
        '英文环境中文', '非中文环境露中文', 'i18n:collect',
        'translation engineer', 'site i18n audit', '词典收集',
        'weline-translation-engineer',
    ],
    'authoritative_skill' => 'weline-translation-engineer',
    'authoritative_doc' => 'dev/ai-command/ai/翻译工程师.md',
    'authoritative_docs' => [
        'dev/ai-command/ai/翻译工程师.md',
        'app/code/Weline/I18n/doc/模块翻译CSV规范.md',
        'app/code/Weline/Framework/doc/3-开发/01-翻译函数使用指南.md',
    ],
    'when' => [
        '新文案或改用户可见文案、i18n 被列入 in_scope',
        '漏译截图、非中文环境露中文、locale 不匹配',
        '用户把界面/流程「翻译」当工程任务（非商品翻译优化）',
        '批量词典收集后需对比补译',
        '排除：商品/详情多语字段走 content_ops 翻译优化技能',
    ],
    'inputs' => [
        '范围：全站 / 若干模块 / 漏译截图点名区域',
        '默认站已选语言：WebsiteLanguage::getWebsiteLanguageCodes(Website::ID_DEFAULT)',
        '归属模块 i18n/zh_Hans_CN.csv、en_US.csv 与当前运行词典',
        '活跃/默认 locale 页面 URL 或截图',
    ],
    'procedure' => [
        '圈定范围，本机解析默认站 language_codes，列出全部目标 locale。',
        '先跑 php bin/w i18n:collect（模块或全仓），用收集后的词典对比缺口。',
        '确认用户可见源串为简体中文；英文源串默认 → 改代码源串为中文并重写 CSV 键。',
        '对比缺口：en_US 缺行/空列/中文占位；非中文 locale 页面仍露中文或回落英文 catalog。',
        '译修落盘：官方模块中英写 CSV，其它已选 locale 写系统词典/实体；禁新建非中英模块 CSV。',
        '中英 CSV 改后再次 collect；抽检 en_US + ≥1 非中英 locale。',
        '写 meetings/翻译-review.md（pass|fail + 缺口清单 + collect/抽检证据）；无法闭环 escalate 项目经理。',
    ],
    'checklist' => [
        '已跑 i18n:collect（改前或改后至少一次有效收集）？',
        '源串为简体中文，非英文默认源串？',
        'zh_Hans_CN.csv 与 en_US.csv 键对齐，en 第二列为真实英文？',
        '官方模块 i18n/ 下无非中英 locale CSV？',
        '默认站其它已选 locale 已写系统词典/归属实体？',
        '非中文 locale 抽检（含 ≥1 非中英）无中文正文、无英文回落？',
        '品牌/商标/技术专有名词未被误译？',
    ],
    'anti_patterns' => [
        '只改 CSV 不 collect，或用 cache:clear 代替 i18n:collect',
        '把模板源串改成英文以假装英文站正常',
        'en_US 第二列留中文占位仍称英文环境正常',
        '非中文 locale 仍露中文正文或错误回落英文 catalog',
        '把「官方模块 CSV 仅中英」读成「其它语种默认不做」',
        '在官方模块 i18n/ 新建或写回 ja_JP/ru_RU 等 CSV',
        '只译 en_US 就宣称翻译完成',
        '把 content_ops 商品翻译优化算作本席工作',
    ],
    'evidence' => [
        'meetings/翻译-review.md：pass|fail + 缺口清单 + 已修路径',
        'i18n:collect 命令与输出（模块/全仓）',
        '目标 locale 抽检证据：URL/截图 + 实际显示语种（含 ≥1 非中英）',
    ],
    'norms' => [
        ['id' => 'i18n_must_site_audit', 'summary' => '本职为全站/范围内界面与流程提示巡检，对照活跃·默认 locale；禁止旁听式签字。'],
        ['id' => 'i18n_collect_then_compare_translate', 'summary' => '固定顺序：解析 language_codes → i18n:collect → 中文源串 → 对比缺口 → 译修 → 再 collect → 抽检 ≥1 非中英。'],
        ['id' => 'i18n_zh_en_csv_only_this_phase', 'summary' => '官方模块 i18n/ 仅 zh_Hans_CN.csv+en_US.csv（Git 瘦身）；非框架模块可自备其它 locale CSV；其它已选 locale 进词典/实体，禁读成「其它语种默认不做」。'],
        ['id' => 'i18n_default_website_all_locales_on_copy_change', 'summary' => '工程改用户可见文案须同波覆盖默认站每个已选 locale：官方模块 zh/en→CSV，其它→词典/实体（user_mentions_translation_all_default_website_locales / active_locale_must_show_target_language）。'],
        ['id' => 'i18n_active_locale_no_chinese_leak', 'summary' => '非中文活跃/默认 locale 禁止露中文正文或错误回落英文 catalog；en_US 第二列禁止中文占位；品牌/商标/专有名词除外。'],
        ['id' => 'i18n_chinese_source_default', 'summary' => '源串默认简体中文；禁止英文当默认源串；禁止为修漏译把模板源串改成英文。'],
        ['id' => 'i18n_mandatory_on_copy_scope', 'summary' => '新文案/改用户可见文案/i18n in_scope/漏译的复杂 team 必须 Team:翻译工程师:（别名 Team:i18n:）上场。'],
        ['id' => 'i18n_dual_track', 'summary' => '巡检施工轨+合规复审轨：meetings/翻译-review.md 记 CSV+词典/实体+collect+locale 抽检，pass|fail。'],
        ['id' => 'i18n_not_product_translate_ops', 'summary' => '不代跑 content_ops「翻译优化/商品翻译」；商品多语走 product/翻译优化.md。'],
        ['id' => 'i18n_wake_pm_when_blocked', 'summary' => '缺归属/需跨席改源串/无法闭环 → escalate @项目经理：请立刻组队解决。'],
        ['id' => 'i18n_companion_skills', 'summary' => '开工前分别 get_skill(translation_engineer/template_i18n/module_i18n_csv)，Read 翻译工程师.md + 模块翻译CSV规范.md。'],
    ],
    'template_surface_rules' => ['required' => [], 'forbidden' => []],
    'verification_commands' => [
        'php bin/w i18n:collect Weline_Module',
        'test -f dev/ai-command/ai/翻译工程师.md',
    ],
];
