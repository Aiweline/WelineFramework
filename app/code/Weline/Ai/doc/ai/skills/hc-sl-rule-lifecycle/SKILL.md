---
name: hc-sl-rule-lifecycle
description: >-
  HARD gate when adding/updating/deleting Weline MCP hard constraints under HC-SL
  (logical symbols). Loads language table + lifecycle checklist so Agent never
  orphan formulas/legend atoms or edit summary-only. Triggers: 硬规则入库, 新增硬规则,
  改硬规则, 删硬规则, HC-SL, HardConstraintsCatalog, HardConstraintsSymbolDsl,
  hc-sl.language, 符号规则, 逻辑符号规则.
aliases:
  - weline-hc-sl-rule-lifecycle
  - hc_sl_rule_lifecycle
  - hard-constraints-hc-sl
---

# hc-sl-rule-lifecycle · 硬规则 HC-SL 生命周期

## 权威

| 层 | 路径 |
|----|------|
| 语言结构表 | `app/code/Weline/Ai/Mcp/schemas/hc-sl.language.v1.json` |
| PHP | `HardConstraintsSymbolDsl::languageTable()` / `validateCatalogConsistency()` |
| 指令 | `dev/ai-command/ai/HC-SL硬规则生命周期.md` |
| 硬规则 | `hc_sl_rule_lifecycle_gate` |

## 强制

触及硬规则增删改前：**必须**本技能 + Read 语言表 + 指令。  
**禁止**只改/删散文留下逻辑符号或 legend 孤儿（脏改）。

## 三操作（摘要）

- **add**：summary + formula/gloss +（新原子→legend）+ 索引 +（全局→dual-write）+ 测  
- **update**：summary∧formula∧gloss 同波；原子变→映射表+全部引用同波  
- **delete**：rules + overrides + legend 孤儿清理 + 引用面 + 测  

细节与禁止项见指令全文。

## 验收

`php app/code/Weline/Ai/Mcp/tests/hard-constraints-symbol-dsl.php`  
`HardConstraintsSymbolDsl::validateCatalogConsistency()` 无 findings。
