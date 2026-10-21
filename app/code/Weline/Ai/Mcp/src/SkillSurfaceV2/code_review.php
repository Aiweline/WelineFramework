<?php

declare(strict_types=1);

/**
 * skill-v2 surface: code_review（工程团队操作壳专席「代码复审」）
 *
 * 只读 PR/diff 复审：四阶段 + severity（过程改编 awesome-skills/code-review-skill），
 * 技术判据只听 Weline hard_constraints；每轮必跑模块边界污染清单。
 * 标准：app/code/Weline/Ai/doc/AI技能编写标准.md
 */
return [
    'id' => 'code_review',
    'spec' => 'skill-v2',
    'label' => '代码复审（结构化 diff 闸 + 模块边界污染禁令）',
    'description' => '工程团队操作壳专席「代码复审」：只读 PR/diff 复审，四阶段 + severity（过程改编 awesome-skills/code-review-skill），技术判据只听 Weline hard_constraints。HARD 每轮必跑模块边界污染清单：网站/设计主题/品牌开站概念写进 app/code 模块一律 fail（website_concept_seed_not_in_modules）。写 meetings/代码复审-review.md（必含 module_boundary）；fail → escalate 项目经理。',
    'triggers' => [
        '代码复审', '代码审查', 'Team:代码复审', 'code review', 'PR review',
        'diff 复审', '审查代码', '模块串融', '网站概念侵入', 'website_concept_seed',
        '硬编码网站', 'CatalogSeeder 进模块', '品牌种子进模块', 'module_boundary',
        'code_review', 'weline-code-review',
    ],
    'authoritative_skill' => 'weline-code-review',
    'authoritative_doc' => 'dev/ai-command/ai/代码复审.md',
    'authoritative_docs' => [
        'dev/ai-command/ai/代码复审.md',
        'dev/ai-command/ai/工程团队.md',
        'app/code/Weline/Ai/doc/ai/skills/code-review/SKILL.md',
        'app/code/Weline/Ai/doc/ai/skills/code-review/reference/module-boundary-contamination.md',
        'app/code/Weline/Framework/doc/3-开发/扩展点选型.md',
        'app/code/Weline/Ai/doc/规则/30-后端与数据.md',
        'app/code/Weline/Framework/doc/3-开发/开发标准与验收.md',
        'app/code/Weline/Ai/doc/AI硬规则索引.md',
    ],
    'when' => [
        '用户明示代码复审 / PR / diff 审查',
        '复杂 team P4 需要通用 diff 闸',
        'diff 触及 app/code 且疑似开站种子、站码分支、品牌默认回退、基座首页品牌化',
        '要独立判定模块边界是否被污染（module_boundary）',
        '排除：内容运营执行波，除非明示审 content_ops 旁路进模块',
    ],
    'inputs' => [
        '待审 diff / PR 与 base 范围',
        '受影响模块与正确归属（app/design/{Vendor}/{theme}/、websites/<site>/）',
        '相关 hard_constraints / 扩展点选型判据',
        '既有 SESSION 计划项、UC/contracts（判断是否偷改验收意图）',
    ],
    'procedure' => [
        '锁定只读范围与 diff 基线；复审过程中禁改生产码。',
        '四阶段过 diff：范围 → 高阶结构 → 逐文件 → 结论，每条 finding 标 severity。',
        '每轮跑模块边界污染清单：站码闸、CatalogSeeder/Sample、品牌默认回退、基座首页品牌化。',
        '判据只引 hard_constraints / 扩展点选型 / 开发标准；禁上游语言栈教条否决本仓机制。',
        '写 meetings/代码复审-review.md，含 verdict 与 module_boundary=pass|fail + rework_owner。',
        'blocking/fail：result=escalate + `@项目经理：请立刻组队解决`；禁私自排施工。',
    ],
    'checklist' => [
        '四阶段都过了，findings 带 severity？',
        '模块边界污染清单逐项跑完？',
        'module_boundary 已写 pass|fail？',
        '判据引用的是 Weline 规则而非上游教条？',
        '全程未改生产码（只读）？',
        'fail 已 escalate，并写 rework_owner？',
    ],
    'anti_patterns' => [
        'app/code 里有 CatalogSeeder/Sample/站码闸/品牌默认回退仍判 pass',
        '用 React/Vue/Clean Architecture 偏好否决已文档化扩展点',
        '代施工改 PHP/phtml 骗绿',
        '没跑污染清单就签 module_boundary=pass',
        '发现串融只写长文，不唤醒 PM',
        '把内容运营执行波拉进复审',
    ],
    'evidence' => [
        'dev/team/{slug}/meetings/代码复审-review.md：severity + verdict + module_boundary + rework_owner',
        'app/code/Weline/Ai/doc/ai/skills/code-review/reference/module-boundary-contamination.md：命中的污染条目',
    ],
    'norms' => [
        ['id' => 'code_review_read_only', 'summary' => '只读复审；禁代施工改生产码，禁为过闸改测试或改验收意图。'],
        ['id' => 'code_review_four_phase_process', 'summary' => '四阶段过程改编自 awesome-skills/code-review-skill；severity：blocking/important/nit/suggestion/learning/praise。'],
        ['id' => 'code_review_weline_verdicts_only', 'summary' => '技术判据只听 hard_constraints / 扩展点选型 / 开发标准；禁用上游语言栈教条否决本仓正确机制。'],
        ['id' => 'code_review_module_boundary_hard', 'summary' => 'HARD 每轮跑模块边界污染清单；站/主题/品牌开场写进 app/code → blocking fail（website_concept_seed_not_in_modules）。'],
        ['id' => 'code_review_mandatory_triggers', 'summary' => '用户明示复审、P4 通用 diff 闸、或 diff 含开站种子/站码闸/品牌默认回退嫌疑时必须 Team:代码复审: 上场（code_review_for_module_boundary）。'],
        ['id' => 'code_review_dual_track_artifact', 'summary' => '过程轨笔记可并入 meetings/代码复审-review.md；必须含 module_boundary=pass|fail。'],
        ['id' => 'code_review_wake_pm', 'summary' => 'blocking/fail → escalate `@项目经理：请立刻组队解决`；禁私自排施工（findings_wake_pm）。'],
        ['id' => 'code_review_not_content_ops', 'summary' => '内容运营执行波不拉本席，除非用户明示审模块污染。'],
    ],
    'template_surface_rules' => [
        'required' => [],
        'forbidden' => [],
    ],
    'verification_commands' => [
        'rg -n "module_boundary" dev/team/<slug>/meetings/代码复审-review.md',
        'test -f dev/ai-command/ai/代码复审.md',
    ],
];
