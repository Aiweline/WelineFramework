# 提示词优化 · review（电商顾问运营扩权 · 合规复审轨）

- seat: Team:提示词优化工程师:
- wave: ecommerce-advisor-ops-planner / 合规复审轨
- date: 2026-09-22
- verdict: **pass**
- paths_changed: 本席仅新增本文件与 `提示词优化-design.md`；**未改** `电商顾问.md` / `McpSkillCatalog.php`

## 对照范围

| 层 | 路径 |
|----|------|
| 指令权威 | `dev/ai-command/ai/电商顾问.md` |
| 本席指令 | `dev/ai-command/ai/提示词优化.md` |
| 席位镜 | `McpSkillCatalog::engineeringTeamSeatSkillMirrors` → `电商顾问` / `项目经理` / `需求分析` 的 `prompt_increment` |
| 硬规则 | `HardConstraintsCatalog` `ecommerce_advisor_for_commerce` + `findings_wake_pm` |
| 编制 | `dev/ai-command/ai/工程团队.md`（findings_wake_pm 电商增量、分配硬规则） |

## 硬规则语义抽检（原义对照）

| 硬门槛 | 指令 | 席位镜 | hard_constraints | 结果 |
|--------|------|--------|------------------|------|
| 运营策划（合并原合规） | ✓ | ✓ | ✓ | 未回退 |
| 禁写码（paths_changed=无） | ✓ | ✓ | ✓ | 未回退 |
| 领域决策「要开发什么」→ escalate 通知 PM | ✓ | ✓（顾问+PM 增量） | ✓ | 未回退 |
| 先解析 supported_countries 再分国联网 | ✓ | ✓ | ✓ | 未回退 |
| 每次讨论/冻结/方案/复审前必查 | ✓ | ✓ | ✓ | 未回退 |
| 日常运营 WebSearch | ✓ | ✓ | ✓ | 未回退 |
| 内容运营执行不代跑 | ✓ | ✓ | ✓ | 未回退 |
| 立项波∥需求分析；冻结须 stance+supported_countries+顾问约束 | ✓ | ✓（顾问+需求分析） | ✓ | 未回退 |
| 未发明新席位 | — | 仍为「电商顾问」 | — | pass |

## 重复压缩判定

见同目录 `提示词优化-design.md`：识别到指令↔席位镜同义摘要，**判定为设计态可执行粘贴，本波不施工压缩**（再压有丢义风险）。未引入第三份全文复述。

## findings

1. **无语义回退**：运营扩权后指令、席位镜、硬规则三层对关键门槛一致。
2. **无乱加**：未发现新增席位/新义务夹带。
3. **无必须动刀的膨胀**：席位镜已是 HARD 摘要 + `get_skill`/Read 指针；`工程团队.md` 已不再全文展开各席增量。
4. **跨席对齐正常**：`项目经理.prompt_increment` 含顾问 `dev_ask` 同回合组队；`需求分析.prompt_increment` 含并行拉起与「顾问约束」并入。

## 返工项

无（verdict=pass）。
