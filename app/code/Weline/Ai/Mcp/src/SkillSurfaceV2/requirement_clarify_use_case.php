<?php

declare(strict_types=1);

/**
 * skill-v2 surface: requirement_clarify_use_case（需求澄清与用例规格）
 *
 * 把用户一句话变成可测试规格：澄清 → 用户故事 + EARS → UC-* 映射 acceptance e2e/Browser → ready-for-plan。
 * 政策边界在 hard_constraints（requirement_clarify_use_case_spec / requirement_use_case_closed_loop_acceptance），
 * 本文件只给可执行步骤与判据。
 * 标准：app/code/Weline/Ai/doc/AI技能编写标准.md
 */
return [
    'id' => 'requirement_clarify_use_case',
    'spec' => 'skill-v2',
    'label' => '需求澄清与用例规格',
    'description' => '编码前强制 Spec Kit/Kiro 式澄清：一轮 ≤5 题追问，落盘模块 doc/开发/spec/{slug}.md；每用户故事 ≥2 条 EARS（WHEN/IF…SHALL）；用例含主成功与异常、映射 acceptance id 且可直译 Playwright/WB-OP；ready-for-plan 前禁架构与写码。用于一句话需求或改业务行为。',
    'triggers' => [
        '需求澄清', '澄清需求', '用例规格', '用例设计', '写用例', 'EARS',
        'clarify', 'use case', 'use-case', 'speckit', 'kiro', '规格澄清',
        '用户故事', '验收标准', '非目标', '主成功路径', 'UC-', 'covers_use_cases',
    ],
    'authoritative_skill' => 'weline-req-clarify',
    'authoritative_doc' => 'dev/ai-command/ai/需求澄清与用例规格.md',
    'authoritative_docs' => [
        'dev/ai-command/ai/需求澄清与用例规格.md',
        'app/code/Weline/Ai/doc/AI工程交付流程.md',
        'app/code/Weline/Ai/doc/AI硬规则索引.md',
        'dev/ai-command/ai/templates/requirement-session.md',
    ],
    'when' => [
        '可执行编码/工程需求（work_kind=feature），写码或架构落笔前',
        '用户一句话要做功能，但范围/角色/主路径仍模糊',
        '要写 EARS 验收标准、用户故事或非目标',
        '要把 UC 映射成 e2e / Browser 操作员路径',
        '要判定能否升 ready-for-plan、进宿主 Plan Mode',
        '排除：non_feature（纯文档/门禁/基建）可 skipped+≥24 字理由',
    ],
    'inputs' => [
        '用户原话与已确认边界：目标/非目标/角色/权限',
        '归属 Vendor_Module 与 feature-slug（kebab-case，可含 ticket）',
        'work_kind=feature|non_feature；fe_be_scope（前台/后台/API）',
        '模块 doc/需求.md、既有 spec 草稿（有则续写，禁平行再开）',
        '现有计划/验收项 id（写 UC 的「映射 acceptance」用）',
    ],
    'procedure' => [
        '草拟目标/非目标/角色/成功标准，写入 doc/开发/spec/{slug}.md，status=draft。',
        '扫模糊点，一轮最多 5 题：范围、权限、主路径 vs 边界、数据/schema、可见面。',
        '把 Q&A 写进「澄清记录」节并改写回正文；关键问题未答禁开码。',
        '每用户故事写 ≥2 条 EARS：WHEN/IF/WHILE … SHALL …（可观察响应）。',
        '写 ≥1 主成功 UC-*（前置/步骤/期望），异常或权限失败 ≥1；Web 步骤须能直译 Playwright/WB-OP。',
        '每条 UC 填「映射 acceptance」id；计划 acceptance[].covers_use_cases 列全 UC-*。',
        '末尾写就绪检查并置 status=ready-for-plan，再自行启用宿主 Plan Mode 做架构与计划。',
    ],
    'checklist' => [
        'status=ready-for-plan 且 work_kind/fe_be_scope 已填？',
        '≥1 用户故事，每故事 ≥2 条 EARS？',
        '≥1 主成功 UC，异常/权限失败已写或写「无」？',
        '每条 UC-* 已填映射 acceptance id？',
        '非目标已写明，正文未混入类名/补丁步骤？',
        '同 slug 的 dev/session/{slug}.md 已建（wave=clarifying）？',
        '收口前全部 UC-* 在 covers_use_cases 中且 passed+真机证据？',
    ],
    'anti_patterns' => [
        '用户说「加个批发价」→ 直接改 PHP，无 spec 文件',
        '验收只写「应正确显示价格」，无 WHEN/IF…SHALL',
        '用例只有「打开页面看看」，无可断言步骤',
        '规格只存在聊天里，未落 doc/开发/spec/{slug}.md',
        'feature 标 clarify_status=skipped 而理由不足 24 字',
        '计划 e2e 全绿但 UC 未被 covers_use_cases 覆盖仍报完成',
        'UC 未映射 acceptance id 就升 ready-for-plan 并写码',
        '把 how（类名/补丁步骤）写进规格正文',
    ],
    'evidence' => [
        'doc/开发/spec/{slug}.md：澄清记录 + EARS + UC-* + 映射 acceptance',
        'dev/session/{slug}.md：wave=clarifying、计划项与未完成清单',
        'acceptance[].covers_use_cases 覆盖全部 UC-*，收口时 passed+证据',
    ],
    'norms' => [
        ['id' => 'clarify_before_code', 'summary' => '写码/架构落笔前完成澄清；一轮最多 5 题，答案写回「澄清记录」并改写正文。'],
        ['id' => 'persist_spec_md', 'summary' => 'feature 必须落盘归属模块 doc/开发/spec/{feature-slug}.md；有草稿续写，禁平行再开新文件。'],
        ['id' => 'ears_acceptance', 'summary' => '每用户故事 ≥2 条 EARS（WHEN/IF…SHALL）可观察验收句；散文验收不算。'],
        ['id' => 'use_cases_feed_e2e', 'summary' => '≥1 主成功用例；每条 UC 映射 acceptance id；收口前全 UC 闭环 passed（requirement_use_case_closed_loop_acceptance），否则只报「用例闭环未完成」。'],
        ['id' => 'ready_for_plan_gate', 'summary' => 'status 升 ready-for-plan 后才允许 architecture_design 与业务码（requirement_clarify_use_case_spec）。'],
        ['id' => 'host_plan_mode_next', 'summary' => 'ready-for-plan 后 Agent 自行启用宿主 Plan Mode（host_plan_mode_for_planning）；写完自审通过（plan_complete_self_review_then_implement）再切 agent 开发，禁停等用户点 Build。'],
        ['id' => 'non_feature_skip_rationale', 'summary' => 'non_feature 可 clarify_status=skipped，理由 ≥24 字，并留缺口清单。'],
    ],
    'template_surface_rules' => [
        'required' => [],
        'forbidden' => [],
    ],
    'verification_commands' => [
        'test -f app/code/<Vendor>/<Module>/doc/开发/spec/<slug>.md',
        'rg -n "WHEN |IF |SHALL|UC-" app/code/<Vendor>/<Module>/doc/开发/spec',
        'rg -n "covers_use_cases" dev/session/<slug>.md',
    ],
];
