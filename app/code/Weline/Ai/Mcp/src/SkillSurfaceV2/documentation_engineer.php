<?php

declare(strict_types=1);

/**
 * skill-v2 surface: documentation_engineer（工程团队「文档工程师」核心席，别名 文档）
 *
 * 唯一耐久文档执笔人；实现变→文档同变；流程位 documentation_review_pass。
 * 标准：app/code/Weline/Ai/doc/AI技能编写标准.md
 */
return [
    'id' => 'documentation_engineer',
    'spec' => 'skill-v2',
    'label' => '文档工程师（统一执笔 · 实现变→文档同变）',
    'description' => '工程团队核心席「文档工程师」（别名 文档）：唯一耐久文档执笔人，统一编写/改写归属模块 doc/。其它开发席禁改 app/code/*/doc/，只交 doc_debt 由 PM 安排本席。实现一变则耐久文档描述同变；流程位 documentation_review_pass（测试 pass 后、pm_huishen_pass 前），写 meetings/文档-review.md。禁止临时资料进模块 doc/；禁止借改文档改生产码。',
    'triggers' => [
        '文档工程师', 'Team:文档工程师', 'Team:文档',
        '文档同步', '文档漂移', '三文档对齐',
        'docs reconcile', 'documentation engineer',
        'docs_reconcile_on_closeout', 'documentation_review_pass',
    ],
    'authoritative_skill' => 'weline-documentation-engineer',
    'authoritative_doc' => 'dev/ai-command/ai/文档工程师.md',
    'authoritative_docs' => [
        'dev/ai-command/ai/文档工程师.md',
        'dev/ai-command/ai/工程团队.md',
        'app/code/Weline/Ai/doc/AI工程交付流程.md',
        'app/code/Weline/Ai/doc/AI硬规则索引.md',
    ],
    'when' => [
        '行为/契约/用户可见行为变更的复杂 team（默认有施工 closed）',
        '用户明示文档同步/文档漂移/三文档对齐/文档工程师',
        '新建或大改 README/指南/API/功能现状/耐久 spec',
        '测试 pass 后、PM 汇审前的 documentation_review_pass',
        '排除：纯提示词且无产品行为变更、无耐久文档触达（记 N/A+理由）',
    ],
    'inputs' => [
        'paths_changed / git diff（实现面）',
        '测试 pass 证据（UC/e2e/WB-OP/业务 id）',
        'contracts.md、冻结 UC、doc/开发/spec/{slug}.md',
        '各席 doc_debt + 归属模块现有 doc/ 目录',
    ],
    'procedure' => [
        '确认测试已 pass（或本波合法 N/A：SESSION 写理由 ≥24 字）。',
        '收集对照源：paths_changed + 测试证据 + contracts/UC + doc_debt。',
        '打开归属模块 doc/README、需求.md、功能现状.md、开发日志.md 及触及专题指南。',
        '逐条标差异：仍写旧行为 / 缺新行为 / 验收口径过期。',
        '统一执笔改写，使文档描述与已验证实现同真相；触及 README/指南/API/spec 一并改。',
        '自检模块 doc/ 无 SESSION/team/排查类临时资料，清悬空链接或记入未完成清单。',
        '写 meetings/文档-review.md（pass|fail + 对照源摘要 + 已改路径），notify PM；fail → 返工或 escalate。',
    ],
    'checklist' => [
        '对照源含实现 diff + 测试证据（或合法 N/A）？',
        '需求.md / 功能现状.md / 开发日志.md 已反映本波行为？',
        '触及 README/指南/API/耐久 spec 已同变？',
        '无「代码新、文档仍写旧」残留？',
        '模块 doc/ 无 SESSION/team/排查类临时资料？',
        '未借文档同步改生产 PHP/phtml/CSS？',
        'meetings/文档-review.md 已写 pass|fail 并 notify PM？',
    ],
    'anti_patterns' => [
        '代码/行为已变仍保留旧行为/接口/配置描述却 pass',
        '测试 pass 后跳过 Team:文档工程师:（别名 Team:文档:）直接汇审',
        '施工席私改 app/code/*/doc/ 而非交 doc_debt',
        '用口头「文档 OK」代替 meetings/文档-review.md',
        '把 SESSION/team/排查笔记写进模块 doc/',
        '借「改文档」改生产 PHP/phtml/CSS 骗绿',
        '无 documentation_review_pass 就进 pm_huishen_pass',
    ],
    'evidence' => [
        'meetings/文档-review.md：pass|fail + 对照源摘要 + 已改文档路径',
        '模块 doc/ diff 或已改文件清单',
        'notify PM 记录 + doc_debt 清单（若适用）',
    ],
    'norms' => [
        ['id' => 'docs_must_follow_implementation', 'summary' => '本职：实现变→耐久文档描述必须同变；禁止代码已改仍保留旧行为/接口/配置描述却 pass。'],
        ['id' => 'docs_unified_authorship', 'summary' => '统一执笔：模块耐久文档只由本席编写；其它开发席只交 doc_debt，禁止私改 app/code/*/doc/。'],
        ['id' => 'docs_gate_after_tester_before_huishen', 'summary' => '流程位 documentation_review_pass：tester_execution_pass 之后、pm_huishen_pass 之前；不堵施工；无 review pass（或合法 N/A）禁汇审。'],
        ['id' => 'docs_evidence_sources', 'summary' => '对照源：paths_changed/实现 diff + 测试已过证据 + contracts/UC + 各席 doc_debt；写已验证实现而非愿望。'],
        ['id' => 'docs_three_doc_and_touched_guides', 'summary' => '对齐需求.md/功能现状.md/开发日志.md 及触及 README/指南/API/耐久 spec。'],
        ['id' => 'docs_no_ephemeral_in_module_doc', 'summary' => '禁止 SESSION/team/排查/一次性修复笔记进模块 doc/（module_doc_forbids_ephemeral_work_artifacts）。'],
        ['id' => 'docs_dual_track', 'summary' => '统一执笔施工轨 + 契约复审轨；meetings/文档-review.md pass|fail。'],
        ['id' => 'docs_mandatory_on_behavior_change', 'summary' => '行为/契约变更或任一耐久文档更新诉求 → 必须 Team:文档工程师:（别名 Team:文档:）上场统一编写。'],
        ['id' => 'docs_not_rewrite_production_code', 'summary' => '禁止借改文档改生产码骗绿；行为冲突 escalate PM。'],
        ['id' => 'docs_wake_pm_when_blocked', 'summary' => '无法闭环 → escalate @项目经理：请立刻组队解决。'],
        ['id' => 'docs_companion_skill', 'summary' => '开工前 get_skill(documentation_engineer|weline-documentation-engineer)+Read 文档工程师.md + 工程团队.md（流程位）。'],
    ],
    'template_surface_rules' => ['required' => [], 'forbidden' => []],
    'verification_commands' => [
        'test -f dev/ai-command/ai/文档工程师.md',
        'test -f app/code/Weline/Ai/doc/AI工程交付流程.md',
    ],
];
