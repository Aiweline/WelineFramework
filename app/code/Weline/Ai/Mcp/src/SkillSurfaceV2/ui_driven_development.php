<?php

declare(strict_types=1);

/**
 * skill-v2 surface: ui_driven_development（按 UI 开发：切片实现 -> 截图对稿 -> 调整）
 *
 * 可执行程序：钉参考 UI -> 读技能 -> 实现切片 -> 禁缓存截图 -> Read 对稿 -> 修到对齐 -> 下一片。
 * 与「审图」互补：用户附图仍走审图硬触发；对稿 fail 同回合转审图联合流水线。
 * 标准：app/code/Weline/Ai/doc/AI技能编写标准.md
 */
return [
    'id' => 'ui_driven_development',
    'spec' => 'skill-v2',
    'label' => '按 UI 开发（切片实现 → 截图对稿 → 调整）',
    'description' => '按参考 UI / 设计稿开发：每完成一个功能切片必须用禁缓存 Browser 截图并 Read，对照参考 UI 列差异再调整，对齐后才进入下一切片。与「审图」（用户附图）互补；对稿 fail 同回合转入审图联合流水线。无参考稿禁止假装对稿。',
    'triggers' => [
        '按UI开发', '按 UI 开发', 'UI驱动开发', 'UI 驱动开发',
        '对照UI开发', '对照 UI 开发', '对着设计稿开发',
        'design to code', 'ui-driven', 'ui driven development',
        'ui_driven_development', 'weline-ui-driven-dev',
    ],
    'authoritative_skill' => 'weline-ui-driven-dev',
    'authoritative_doc' => 'dev/ai-command/theme/按UI开发.md',
    'authoritative_docs' => [
        'dev/ai-command/theme/按UI开发.md',
        'dev/ai-command/theme/审图.md',
        'app/code/Weline/Ai/doc/ai/skills/ui-driven-dev/SKILL.md',
        'app/code/Weline/Ai/doc/规则/20-前端与主题.md',
        'app/code/Weline/Theme/doc/设计品味.md',
        'app/code/Weline/Theme/doc/theme-css-variables-only.md',
    ],
    'when' => [
        '用户给设计稿/竞品截图要求边做边对稿',
        '既有页加功能：先拍当前页再落位',
        '对稿 fail 需按差异修到对齐',
        '排除：用户只附图未提按 UI 开发（走审图硬触发）',
    ],
    'inputs' => [
        '参考 UI：设计稿 / Figma 导出 / 竞品截图 / 线稿',
        '验收 URL 与断点（375 / 768 / 1024）',
        '本切片范围：一个区块 / 交互态 / 断点视图',
    ],
    'procedure' => [
        '钉参考 UI 并记入切片记录；无参考稿先向用户索取，禁凭感觉假装对稿。',
        '开工读技能：get_skill(weline-theme-development)+frontend-design+prototype+local-browser-urls。',
        '实现本切片：只改本切片模板/CSS/部件，走 Token 与 w-*/Taglib。',
        '探活后禁缓存打开验收 URL（省略 position），截图盖住本切片改动面。',
        'Read 截图文件，对照参考 UI 列差异：结构/间距/控件语义/文案层次/主题契合。',
        '同回合改码并重截直到对齐；未对齐禁止扩大施工面。',
        '按 汇报模板 写切片小节（参考 UI/验收 URL/对稿表/已调/状态），整需求仍走 webui_browser_closeout。',
    ],
    'checklist' => [
        '每个切片都有禁缓存截图 + Read 记录',
        '对稿表每项写明 对齐 或 差异',
        '切片对齐前未开下一片',
        '视觉 fail 已同回合转入审图联合流水线',
        '整需求收口有「交付地址」且关 Browser',
    ],
    'anti_patterns' => [
        '整页做完才第一次截图',
        '只 snapshot / DOM 文本，不 Read 截图',
        '无参考稿却写「已对稿」',
        '用 getBoundingClientRect 数字冒充视觉 PASS',
        '把原型 throwaway hex 粘进生产',
        '未对齐就扩大施工面',
    ],
    'evidence' => [
        '切片对稿表：参考 UI / 实机截图 / 结论',
        '截图文件路径（doc/evidence/ 或切片记录附图）',
        '视觉 fail 的审图联合流水线纪要',
    ],
    'norms' => [
        ['id' => 'ui_driven_slice_then_shot', 'summary' => '每功能切片实现后必须截图并对稿，禁止整页做完才第一次截图。'],
        ['id' => 'ui_driven_read_screenshot', 'summary' => '截图后必须 Read 图像文件做视觉对稿，禁止只 snapshot/metrics。'],
        ['id' => 'ui_driven_needs_reference', 'summary' => '无参考 UI 须先向用户索取，禁止假装已对稿。'],
        ['id' => 'ui_driven_fix_before_next', 'summary' => '本切片未对齐禁止扩大到下一功能切片。'],
    ],
    'norm_ids' => [
        'acceptance_phase_requires_shentu',
        'browser_operator_self_test',
        'requirement_acceptance_always',
        'user_image_attachment_triggers_shentu',
    ],
    'template_surface_rules' => [
        'required' => [
            'Lock reference UI before coding',
            'After each slice: cache-off Browser → screenshot → Read → diff vs reference → fix',
            'On visual fails run 审图 joint pipeline same turn',
        ],
        'forbidden' => [
            'Claim UI alignment without a reference mock/screenshot',
            'Skip Read of browser screenshots in ui-driven work',
            'Move to next slice while current still mismatches reference',
        ],
    ],
    'verification_commands' => [
        'test -f dev/ai-command/theme/按UI开发.md',
        'test -f app/code/Weline/Ai/doc/ai/skills/ui-driven-dev/SKILL.md',
    ],
];
