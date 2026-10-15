# MCP 指令：HC-SL 硬规则生命周期

**触发词（任一即加载本指令 + `get_skill(hc_sl_rule_lifecycle)`）**

- `硬规则入库` / `新增硬规则` / `添加硬规则` / `改硬规则` / `删硬规则`
- `HC-SL` / `符号规则` / `逻辑符号规则` / `硬规则生命周期`
- 改 `HardConstraintsCatalog` / `HardConstraintsSymbolDsl` / `hc-sl.language`
- 用户说「全局规则」且会动 MCP hard_constraints（配合 `global_rules_dual_write_mcp_and_host`）

技能 / surface：`hc_sl_rule_lifecycle`（别名 `weline-hc-sl-rule-lifecycle`）。  
语言结构表：`app/code/Weline/Ai/Mcp/schemas/hc-sl.language.v1.json`（机器权威；PHP `HardConstraintsSymbolDsl::languageTable()`）。

---

## 角色（硬）

你在**新增 / 修改 / 删除**框架硬规则时，必须按 **HC-SL** 整波维护——禁止只动散文、留下脏逻辑符号或孤儿映射。

| 做 | 不做 |
|----|------|
| 先 Read 语言表 + 现有 legend/overrides | 只删/改 `summary` 而留下 `formula`/`overrides`/`legend` |
| add/update/delete 按生命周期清单齐改 | 公式用了新原子却不登记 legend |
| 改原子名 → 映射表 + 所有引用公式同波改 | 只改一处引用，映射表不动 |
| 删规则 → 清 overrides + legend 孤儿 + 引用面 | 「删描述就算删规则」 |
| `validateCatalogConsistency()` 绿再宣称完成 | 超 formula/gloss 长度帽硬塞 |

---

## 语言结构（入库前必读）

1. **算子**：`□◇¬∧∨→↔∀∃⊥`（见 language 表 `operators`）  
2. **原子 legend**：`sym → {gloss_zh, rule_ids[]}`  
3. **规则双形态**：  
   - 全文：`Catalog::rules()` `{id,summary,doc}`  
   - 热路径：`{id,formula≤80,gloss_zh≤64,cluster}`（overrides 或 derive）  
4. **展开**：`resolve_task_context.hard_constraint_ids`（禁止 `get_indexed_document`）

公式示例：`Eng∧Write→□Arch|(¬Arch→⊥Write)`

---

## 新增（add）

1. 选稳定 `id`（snake_case）+ `doc` 路径  
2. 写完整 `summary`（强制/否决可执行）入 `rules()` 或 `mcpOperationalRules()`  
3. 手写 `overrides()[id]`（脊柱）或确认 `derive()` 可生成合规 formula/gloss  
4. 公式中每个新谓词 → `legend()` 登记，`rule_ids` 含本 id  
5. 更新 `AI硬规则索引.md` / 对应 `规则/*.md`  
6. 全局规则 → dual-write `~/.cursor/rules`  
7. 跑：`php app/code/Weline/Ai/Mcp/tests/hard-constraints-symbol-dsl.php` + 相关契约  

**禁止**：只加 summary；公式引用未登记原子；超帽。

---

## 修改（update）

1. **同波**改：`summary` ∧ `formula` ∧ `gloss_zh`（及 doc 若变）  
2. 若改原子名/语义：  
   - 更新 `legend()`  
   - Grep 全仓公式/overrides/mcpInstructions/索引/host mdc 中旧原子  
   - 全部改为新原子或删引用  
3. 若改 `id`：视为 delete 旧 + add 新（或显式迁移所有引用）  
4. 全局规则 → dual-write 宿主镜  

**禁止**：只改 summary 留下旧公式（脏改）；只改公式不改映射表/引用面。

---

## 删除（delete）

1. 从 `rules()` / `mcpOperational` 删除  
2. 从 `overrides()` 删除  
3. **legend**：  
   - `rule_ids` 仅含已删 id → **删除该原子**  
   - 共享原子 → 从 `rule_ids` 去掉已删 id  
4. 清 `mcpInstructions`、索引、契约测、host mdc 引用  
5. `validateCatalogConsistency()` 无孤儿  

**禁止**：只删文档/summary，留下 overrides 或 legend 孤儿。

---

## 检查清单（改完必勾）

- [ ] 已 Read `hc-sl.language.v1.json` / `languageTable()`  
- [ ] summary 与 formula/gloss 同波且语义一致  
- [ ] 无未登记原子；无孤儿 legend；无孤儿 overrides  
- [ ] 引用面（instructions/索引/契约/host）已同步  
- [ ] `hard-constraints-symbol-dsl` 测绿；token 预算未破  

任一项否 → **不得**宣称硬规则已入库/已改完。
