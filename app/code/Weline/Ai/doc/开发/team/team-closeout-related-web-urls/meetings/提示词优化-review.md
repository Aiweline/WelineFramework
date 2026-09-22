# 提示词优化 · review（team-closeout-related-web-urls · 语义复审轨）

- seat: Team:提示词优化工程师:
- wave: team-closeout-related-web-urls / 语义复审轨
- date: 2026-09-22
- verdict: **pass**
- paths_changed: 见本席回报单；本文件为复审产物

## 对照范围

| 层 | 路径 |
|----|------|
| 编制指令 | `dev/ai-command/ai/工程团队.md`（回报单 + 骨架） |
| 席位镜 | `McpSkillCatalog` 项目经理 / 测试 `prompt_increment`；principle `closeout_related_web_urls` |
| surface | `GuidanceWorkflowCatalog::engineeringTeamSurface` norm `seat_closed_reports_related_web_urls` |
| 已有硬规则（指针） | `feature_delivery_urls` / `closeout_delivery_reminder`（**未改** HardConstraintsCatalog） |
| 设计 | 同目录 `提示词优化-design.md` |

## 硬门槛原义对照

| 硬门槛 | 工程团队.md | 席位镜 | surface | feature_delivery_urls | 结果 |
|--------|-------------|--------|---------|------------------------|------|
| closed 必填 related_web_urls | ✓ | 骨架 HARD ✓ | norm ✓ | — | 未回退 |
| PM 完成汇报须「交付地址」汇总 | ✓（汇审段） | 项目经理 HARD ✓ | required/forbidden ✓ | ✓ 指针 | 未回退 |
| 测试 pass closed 填探活地址 | — | 测试 HARD ✓ | — | 同源 | 未回退 |
| 纯逻辑 N/A | ✓ | ✓ | ✓ | ✓ | 未回退 |
| 未削弱 Host/Markdown/关 Browser | — | 指针不复制全文 | 指针 | 正文保留 | pass |
| 用户明示新增（非乱加） | design 已声明 | — | — | — | pass |

## 重复压缩判定

施工为用户明示填缺口；新增处均为短 HARD + 指针到 `feature_delivery_urls`，未粘贴交付地址全文第三份。符合「仅填缺口、禁削弱、禁乱造第二套格式」。

## findings

1. 无语义回退；无削弱 feature_delivery_urls。
2. 乱加否决不适用：design 已记「用户明示新增义务」。
3. MCP 契约测 `guidance-workflow-contract` + `mcp-skills-catalog` 本机绿。

## 返工项

无（verdict=pass）。
