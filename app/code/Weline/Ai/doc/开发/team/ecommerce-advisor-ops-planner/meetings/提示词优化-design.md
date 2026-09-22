# 提示词优化 · design（电商顾问运营扩权 · 合规复审轨）

- seat: Team:提示词优化工程师:
- wave: ecommerce-advisor-ops-planner / 合规复审轨
- date: 2026-09-22
- stance: **同意不施工**（有重复证据，但属席位镜「权威+可执行 HARD 粘贴」设计态，再压有丢义风险）

## 重复证据清单

| # | 同义主题 | 处 A（权威） | 处 B（副本/摘要） | 判定 |
|---|----------|--------------|-------------------|------|
| 1 | 运营策划+禁写码+领域决策→escalate PM | `dev/ai-command/ai/电商顾问.md` §§角色/工作流 | `McpSkillCatalog` `seats.电商顾问.prompt_increment` 工作流段 | 有意摘要；增量已含 `get_skill`+Read 指针 |
| 2 | supported_countries→分国 WebSearch、每次讨论前必查 | 同文件 §政策检索 | 同上 increment 政策风控段 | 有意 HARD 可执行摘要；非第三份全文 |
| 3 | 内容运营不代跑 | 同文件 §角色末段 / §禁止 | 同上 increment 末句 | 短句同义；保留 |
| 4 | findings_wake_pm（含「要开发什么」） | `工程团队.md` §findings_wake_pm + 电商顾问.md §拉起 PM | increment 末段 HARD(findings_wake_pm) | 席位特异触发须留在增量；通用公式在骨架已有 |
| 5 | 立项∥需求分析 | 电商顾问.md §强制上场 | `seats.需求分析.prompt_increment` + 电商顾问 increment | 跨席边界指针，非膨胀 |

## 压缩方案（本波）

**不改写。** 理由：

1. `工程团队.md` 已指针化，不再展开各席 `prompt_increment` 全文（无第三份拷贝）。
2. `prompt_increment` 相对指令正文（~220 行）已是可粘贴 HARD 摘要，符合「席位镜只写本席独有 HARD+边界」。
3. 若再压成纯「见 电商顾问.md」空指针，子智能体在未完整 Read 时可能丢：禁写码、`supported_countries`、每次必查、内容运营不代跑、`@项目经理：请立刻组队解决` 等可执行门槛 → 违反「禁止丢义」。

## 施工 diff

无。

## 禁乱加确认

未提议新增席位、新流程或新硬规则 id。
