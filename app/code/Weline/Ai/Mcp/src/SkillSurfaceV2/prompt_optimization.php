<?php

declare(strict_types=1);

/**
 * skill-v2 surface: prompt_optimization（工程团队「提示词优化工程师」专席）
 *
 * 仅重复才改：先出 ≥2 处同义展开证据；引用改指针；一处权威；压缩不丢义、不乱加。
 * 政策边界在 hard_constraints（prompt_engineer_for_skill_prompt_work），
 * 结构预算见 app/code/Weline/Ai/doc/AI技能编写标准.md。
 */
return [
    'id' => 'prompt_optimization',
    'spec' => 'skill-v2',
    'label' => '提示词优化（技能引用 + 重复压缩 + 题词瘦身）',
    'description' => '工程团队「提示词优化工程师」专席：仅在识别到 ≥2 处同义展开后压缩。技能引用写 get_skill/路径指针，禁粘贴其它技能正文；重复硬规则/清单只留一处权威；压缩不得丢强制上场、禁止项、席位边界、产物路径、硬规则 id；不得新增原文没有的规矩。改技能/提示词/席位镜/指令文案或用户明示提示词优化时必须上场。',
    'triggers' => [
        '提示词优化', '提示词优化工程师', 'Team:提示词优化工程师', '优化题词', '优化提示词',
        '技能引用优化', '技能压缩', '重复描述压缩', '技能镜瘦身', 'prompt optimization',
        'prompt engineer', 'prompt_increment', 'seat_skill_mirrors', 'prompt_optimization',
        'weline-prompt-optimization',
    ],
    'authoritative_skill' => 'weline-prompt-optimization',
    'authoritative_doc' => 'dev/ai-command/ai/提示词优化.md',
    'authoritative_docs' => [
        'dev/ai-command/ai/提示词优化.md',
        'dev/ai-command/ai/工程团队.md',
        'app/code/Weline/Ai/doc/AI技能编写标准.md',
        'app/code/Weline/Ai/doc/AI硬规则索引.md',
        'app/code/Weline/Ai/Mcp/src/SkillSurfaceV2.php',
        'app/code/Weline/Ai/Mcp/src/McpSkillCatalog.php',
        'app/code/Weline/Ai/Mcp/src/GuidanceWorkflowCatalog.php',
    ],
    'when' => [
        '用户明示提示词优化 / 技能压缩 / 技能引用优化 / 优化题词',
        '改 dev/ai-command/ai/** 指令或子智能体提示词骨架',
        '改席位镜 prompt_increment（seat_skill_mirrors）或 MCP surface 文案',
        '同一硬规则/清单在骨架、席位镜、指令间出现 ≥2 处同义展开',
        '要判定压缩是否丢义（语义复审）',
        '排除：纯业务 PHP/phtml 与内容运营文案（prompt_not_content_ops）',
    ],
    'inputs' => [
        '待改文件与目标段落（骨架 / 席位镜 / 指令 / surface description）',
        '重复证据：≥2 处同义展开的路径 + 摘句',
        '权威落点：hard_constraints 规则 id、指令路径、技能 id',
        '原义清单：强制上场/禁止项/席位名/双轨产物路径/硬规则 id',
        '受影响技能的 get_skill id 与宿主薄镜像清单',
    ],
    'procedure' => [
        '先出重复证据清单（路径 + 摘句，≥2 处同义展开）；无证据只出审计清单，禁动刀。',
        '定位权威落点：同一语义只留 hard_constraints 或指令一处展开，其它处写「见 id/路径」。',
        '重复引用改指针：写 get_skill(skill_id|alias) + 必读路径；禁粘贴被引技能正文。',
        '压缩题词/席位镜/surface description：删口号与同义三写，保留全部强制项与门槛。',
        '写 meetings/提示词优化-design.md，含重复证据清单、改指针方案与 diff。',
        '语义复审：对照原义清单抽检 + 跑 MCP 相关测试，写 meetings/提示词优化-review.md 给 pass|fail。',
        '语义冲突或会削弱硬规则：escalate `@项目经理：请立刻组队解决`；业务码交归属席。',
    ],
    'checklist' => [
        '重复证据 ≥2 处已落地（路径 + 摘句）？',
        '权威正文仍完整，只删副本？',
        '引用都是指针（get_skill/路径），无粘贴正文？',
        '强制上场/禁止项/`Team:{席位}:` 名/产物路径/硬规则 id 全保留？',
        'diff 未引入原文没有的规矩、流程或席位义务？',
        'mcp-skills-catalog 与 guidance-workflow-contract 测试绿？',
        '薄镜像仍只指路（非第二权威）？',
        '两份纪要都有 pass|fail 与返工项？',
    ],
    'anti_patterns' => [
        '未见任何重复证据就整段改写',
        '把其它技能全文粘进席位镜当「引用」',
        '为省字删掉强制上场条件或禁止项',
        '借优化新增「顺手」规矩或席位义务',
        '删硬规则语义后标 review pass',
        '把内容运营产品文案当本席工作',
        '只改薄镜像却宣称 MCP 已对齐',
    ],
    'evidence' => [
        'dev/team/{slug}/meetings/提示词优化-design.md：重复证据清单 + 指针改法 + diff',
        'dev/team/{slug}/meetings/提示词优化-review.md：pass|fail + 原义抽检 + 丢义/乱加结论',
        '测试输出：php app/code/Weline/Ai/Mcp/tests/mcp-skills-catalog.php 与 guidance-workflow-contract.php 全 PASS',
        'get_skill 抽检：任抽 1 条原「禁止/强制」在改后仍可执行',
    ],
    'norms' => [
        ['id' => 'prompt_must_optimize', 'summary' => '本职必须做引用指针化 + 重复压缩 + 题词瘦身；禁旁听式签字或只加字不删。'],
        ['id' => 'prompt_only_on_duplication', 'summary' => '仅重复才改：先标 ≥2 处同义展开证据；无证据禁动刀。'],
        ['id' => 'prompt_no_omit_meaning', 'summary' => '禁丢义：强制上场、禁止项、席位边界、产物路径、硬规则 id 压缩后仍可执行。'],
        ['id' => 'prompt_no_invent_rules', 'summary' => '禁乱加：不得新增原文没有的规矩/流程/席位义务；指针只指向已有权威。'],
        ['id' => 'prompt_refs_are_pointers', 'summary' => '引用 = get_skill id/alias + 必读路径；禁粘贴被引技能正文。'],
        ['id' => 'prompt_one_authority_for_duplicates', 'summary' => '同一硬规则/清单只一处展开正文，其它处指针；改指针 ≠ 删唯一正文。'],
        ['id' => 'prompt_no_semantic_regression', 'summary' => '压缩后不得语义回退：`Team:{席位}:` 名、双轨产物路径、可执行门槛仍保留。'],
        ['id' => 'prompt_mandatory_on_skill_prompt_work', 'summary' => '触及技能/提示词/席位镜/MCP skill·surface 文案或用户明示时必须 Team:提示词优化工程师: 上场（prompt_engineer_for_skill_prompt_work）。'],
        ['id' => 'prompt_dual_track', 'summary' => '优化施工轨 meetings/提示词优化-design.md + 语义复审轨 meetings/提示词优化-review.md；fail 阻断宣称完成。'],
        ['id' => 'prompt_wake_pm_on_conflict', 'summary' => '语义回退/压缩冲突本席无法闭环 → escalate 拉起项目经理组队（findings_wake_pm）。'],
        ['id' => 'prompt_not_content_ops', 'summary' => '产品优化/详情/主图/翻译等 content_ops 不拉本席。'],
        ['id' => 'prompt_not_business_code', 'summary' => '默认可改指令/席位镜/surface 文案；业务功能码交归属席。'],
    ],
    'template_surface_rules' => [
        'required' => [],
        'forbidden' => [],
    ],
    'verification_commands' => [
        'test -f dev/ai-command/ai/提示词优化.md',
        'php app/code/Weline/Ai/Mcp/tests/mcp-skills-catalog.php',
        'php app/code/Weline/Ai/Mcp/tests/skill-surface-standard.php',
    ],
];
