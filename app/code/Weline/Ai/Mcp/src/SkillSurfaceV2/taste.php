<?php

declare(strict_types=1);

/**
 * skill-v2 surface: design_taste（设计品味 / taste）
 *
 * 判断层技能：UI / 原型 / 审图在动手前先定层级、密度、字号阶梯、强调预算与文案声音。
 * 不产出 CSS；颜色/间距/圆角/阴影/字号一律取 Theme Token。
 * 标准：app/code/Weline/Ai/doc/AI技能编写标准.md
 */
return [
    'id' => 'design_taste',
    'spec' => 'skill-v2',
    'label' => '设计品味（视觉判断层）',
    'description' => '设计品味判断层：UI/原型/审图动手前先定层级、密度、字号阶梯、强调色预算与文案声音。只给判断与验收口径，不产出 CSS；颜色/间距/圆角/阴影/字号一律取 Theme Token。用于新页面、改版、审图人性化、被吐槽丑/乱/廉价/AI 味，以及原型转 UI 的交接。',
    'triggers' => [
        '设计品味', '品味', 'taste', 'design taste', 'design_taste', 'weline-taste', 'design-taste',
        '审美', '好看', '高级感', '廉价感', 'AI 味', 'AI味', '丑', '乱', '不够人性化',
        '排版', '层级', '视觉层级', '密度', '字号', '间距', '强调色', '视觉决策', 'visual hierarchy',
    ],
    'authoritative_skill' => 'weline-taste',
    'authoritative_doc' => 'app/code/Weline/Theme/doc/设计品味.md',
    'authoritative_docs' => [
        'app/code/Weline/Theme/doc/设计品味.md',
        'app/code/Weline/Theme/doc/theme-storefront-token-consumption.md',
        'app/code/Weline/Theme/doc/theme-css-variables-only.md',
        'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
        'dev/ai-command/theme/审图.md',
    ],
    'when' => [
        '新页面/改版/布局重排，动手前先定视觉决策',
        '审图后要做人性化与审美修复（非报错图）',
        '原型转 UI：线框已定，待定层级与密度',
        '用户说丑/乱/廉价/AI 味/不够高级',
        '只是"美化一下"的改色改距需求',
        '排除：纯 Token 重命名或纯后端（走 frontend_development）',
    ],
    'inputs' => [
        '当前页面截图或线稿（无图先取图，禁凭记忆判断）',
        '页面唯一主职责（一句话；写不出就先拆页）',
        'Theme Token 清单：间距/字号/圆角/阴影（见 theme-storefront-token-consumption）',
        '参考 UI：同类页面或竞品截图（没有就先找，禁空谈风格）',
    ],
    'procedure' => [
        '一句话写清页面唯一主职责；写不出就拆页或改顶部 Tab。',
        '标三视线：第一视线（唯一焦点）、第二视线（主操作/数据）、第三视线（辅助说明）。',
        '定字号阶梯 ≥3 级，全部取 --weline-font-size-*；正文与辅助文字至少差一级。',
        '定间距节奏：全部取 --weline-space-*；组内同间距，组间距≈组内×2。',
        '定强调预算：每屏 ≤1 强调色、≤1 主按钮、≤2 套圆角（控件 md、卡片 lg）。',
        '改文案声音：按钮=动词+对象；空态=发生了什么+下一步；错误=原因+出口。',
        '产出《品味自检》6 问结果 + 采用 Token 清单，交 UI/主题席落地。',
    ],
    'checklist' => [
        '第一视线只有一个焦点？',
        '字号 ≥3 级且全部来自 --weline-font-size-*？',
        '间距全部落在 --weline-space-* 刻度？',
        '强调色 ≤1、主按钮 ≤1？',
        '圆角/阴影 ≤2 套且均为 Token？',
        '空态与错误态都有可执行出口？',
        '375px 宽无溢出、无一字竖排？',
        '去掉装饰后信息仍完整？',
    ],
    'anti_patterns' => [
        '渐变+大阴影+多色图标堆"高级感"',
        '卡片套卡片，每个区块都描边',
        '一页出现 3 种以上圆角或阴影',
        '字号全是 14px，只靠加粗区分层级',
        '同一屏 3 个彩色主按钮抢焦点',
        '按钮文案写功能名（"提交"）而非用户动作',
        '为"丰富"加无信息量插画或图标',
        '只调颜色不改层级就宣称优化',
    ],
    'evidence' => [
        'meetings/审图-taste.md：6 问结果 + 采用 Token 列表',
        '改前/改后截图路径（Browser 免缓存）',
        '375px 与桌面两档截图',
    ],
    'norms' => [
        [
            'id' => 'taste_judgement_not_css',
            'summary' => '本技能只做判断，不写 CSS、不选色值；落地由 UI 席与主题开发工程师用 Theme Token 完成。',
        ],
        [
            'id' => 'taste_theme_tokens_only',
            'summary' => '颜色/间距/圆角/字号/阴影只消费 --color-*、--weline-space-*、--weline-font-size-*、--weline-theme-radius-*、--weline-shadow-*；禁止私有刻度（theme_base_components_token_only）。',
        ],
        [
            'id' => 'taste_one_job_per_pane',
            'summary' => '一屏一主职责；次要块默认点击展开卡片，默认收起（feature_ui_keep_simple_top_tabs）。',
        ],
        [
            'id' => 'taste_hierarchy_before_decoration',
            'summary' => '先层级后装饰；装饰不承载信息——删掉装饰后信息仍完整才算合格。',
        ],
        [
            'id' => 'taste_copy_is_interface',
            'summary' => '文案是界面的一部分：用户语言、动作明确、有出口；禁止功能说明书腔。',
        ],
        [
            'id' => 'taste_evidence_required',
            'summary' => '无截图 + Token 清单 + 6 问结果的"审美通过"无效（acceptance_phase_requires_shentu）。',
        ],
        [
            'id' => 'taste_not_content_ops',
            'summary' => '内容运营（产品图/详情/文章/翻译）不拉本技能，走各自技能。',
        ],
    ],
    'template_surface_rules' => [
        'required' => [
            'get_skill(design_taste|taste) 后再做布局/审美判断',
            '6 问自检全 pass 才交 UI/主题席落地',
            '记录采用 Token 清单与改前改后截图',
            '与 prototype、frontend_design、weline-theme-development 同回合联动',
        ],
        'forbidden' => [
            '发明私有色板或间距刻度',
            '只点评不改（critique-only）',
            '用"高级感/大气"替代可验证判据',
            '无截图、无 Token 清单就标 pass',
            '绕过 Theme Token 直接写 px/hex',
        ],
    ],
    'verification_commands' => [
        'test -f "app/code/Weline/Theme/doc/设计品味.md"',
        'test -f app/code/Weline/Theme/doc/theme-storefront-token-consumption.md',
        'test -f dev/ai-command/theme/审图.md',
        'rg -n "design_taste|设计品味" app/code/Weline/Ai/Mcp/src/McpSkillCatalog.php',
    ],
];
