<?php

declare(strict_types=1);

/**
 * skill-v2 surface: hc_sl_rule_lifecycle（HC-SL 硬规则增/改/删 + 语言表）
 *
 * 先 get_skill + 语言结构表；summary/formula/gloss/legend 同波；删除清孤儿。
 * 标准：app/code/Weline/Ai/doc/AI技能编写标准.md
 */
return [
    'id' => 'hc_sl_rule_lifecycle',
    'spec' => 'skill-v2',
    'label' => 'HC-SL 硬规则生命周期（增/改/删 + 语言表）',
    'description' => '新增/修改/删除 MCP 硬规则必须按 HC-SL 整波维护：先 get_skill(hc_sl_rule_lifecycle) 并 Read 语言结构表 hc-sl.language.v1.json + 指令；summary 与 formula/gloss/legend 同波；新原子必须登记 legend，原子变则映射表与全部引用同波；删除须清 Catalog+overrides+legend 孤儿+引用面。改后 validateCatalogConsistency 绿 + 硬规则符号测。',
    'triggers' => [
        '硬规则入库', '新增硬规则', '添加硬规则', '改硬规则', '删硬规则', '删除硬规则',
        'HC-SL', '符号规则', '逻辑符号规则', '硬规则生命周期',
        'HardConstraintsCatalog', 'HardConstraintsSymbolDsl', 'hc-sl.language',
        'hc_sl_rule_lifecycle', 'weline-hc-sl-rule-lifecycle',
    ],
    'authoritative_skill' => 'weline-hc-sl-rule-lifecycle',
    'authoritative_doc' => 'dev/ai-command/ai/HC-SL硬规则生命周期.md',
    'authoritative_docs' => [
        'dev/ai-command/ai/HC-SL硬规则生命周期.md',
        'app/code/Weline/Ai/Mcp/schemas/hc-sl.language.v1.json',
        'app/code/Weline/Ai/doc/ai/skills/hc-sl-rule-lifecycle/SKILL.md',
        'app/code/Weline/Ai/doc/AI硬规则索引.md',
        'app/code/Weline/Ai/doc/规则/00-MCP调用与会话.md',
    ],
    'when' => [
        '新增/修改/删除 MCP 硬规则（HardConstraintsCatalog/SymbolDsl）',
        '改 summary/formula/gloss_zh/legend/overrides',
        '用户说「全局规则」且会动 MCP hard_constraints',
        '硬规则符号测/一致性校验失败需修复',
        '排除：只读查询规则或纯文档措辞不改规则体',
    ],
    'inputs' => [
        '目标规则 id 与增/改/删意图',
        '语言结构表 hc-sl.language.v1.json（或 HardConstraintsSymbolDsl::languageTable()）',
        '现有 rules()/mcpOperationalRules()/overrides()/legend() 与引用面清单',
    ],
    'procedure' => [
        '先 get_skill(hc_sl_rule_lifecycle)，Read hc-sl.language.v1.json + HC-SL硬规则生命周期.md。',
        'add：选稳定 snake_case id + doc 路径，写可执行 summary 入 rules()/mcpOperationalRules()。',
        'add：手写 overrides()[id] 或确认 derive() 可生成 formula≤80+gloss_zh≤64；新谓词登记 legend（含 rule_ids）。',
        'update：summary∧formula∧gloss_zh 同波；原子变则 legend+全部引用 formula/overrides 同波 Grep 改。',
        'delete：rules()+overrides 删除；legend 仅绑该 id 的原子删/改绑，共享原子去 rule_ids；清引用面。',
        '更新 AI硬规则索引.md/规则/*.md；全局规则 dual-write 宿主镜（global_rules_dual_write_mcp_and_host）。',
        '跑 validateCatalogConsistency()+hard-constraints-symbol-dsl 测，确认无孤儿、token 预算未破。',
    ],
    'checklist' => [
        '已 get_skill 并 Read 语言表 + HC-SL 指令？',
        'summary 与 formula/gloss 同波且语义一致？',
        '无未登记原子、无孤儿 legend/overrides？',
        '原子改名已同步映射表与全部引用面？',
        '引用面（instructions/索引/契约/host）已同步？',
        'validateCatalogConsistency + hard-constraints-symbol-dsl 测绿？',
    ],
    'anti_patterns' => [
        '只改/删 summary 留下旧 formula（脏改）',
        '公式用新原子却不登记 legend',
        '删规则留下 overrides 或 legend 孤儿',
        '只改一处引用，映射表不动',
        '超 formula 80/gloss 64 帽硬塞',
        '没跑一致性校验就宣称硬规则已入库',
    ],
    'evidence' => [
        '变更的 rule id + summary/formula/gloss/legend diff',
        'validateCatalogConsistency 输出',
        'hard-constraints-symbol-dsl 测通过输出',
    ],
    'norms' => [
        ['id' => 'hc_sl_must_load_skill', 'summary' => '硬规则增删改前必须 get_skill(hc_sl_rule_lifecycle) 并 Read 语言表+指令。'],
        ['id' => 'hc_sl_add_full_row', 'summary' => '新增须 summary+formula+gloss；新原子登记 legend。'],
        ['id' => 'hc_sl_update_sync', 'summary' => '修改须 summary∧formula∧gloss 同波；原子变则映射表+全部引用同波。'],
        ['id' => 'hc_sl_delete_no_orphan', 'summary' => '删除须清 Catalog+overrides+legend 孤儿+引用面。'],
        ['id' => 'hc_sl_forbid_prose_only_dirty', 'summary' => '禁止只改/删散文留下逻辑符号或孤儿映射（脏改）。'],
        ['id' => 'hc_sl_validate_after', 'summary' => '改后 validateCatalogConsistency 绿 + hard-constraints-symbol-dsl 测。'],
    ],
    'template_surface_rules' => ['required' => [], 'forbidden' => []],
    'verification_commands' => [
        'test -f app/code/Weline/Ai/Mcp/schemas/hc-sl.language.v1.json',
        'php app/code/Weline/Ai/Mcp/tests/hard-constraints-symbol-dsl.php',
        'rg -n "hc_sl_rule_lifecycle_gate" app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php',
    ],
];
