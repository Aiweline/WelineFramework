# 对齐冻结会 — 主题开发工程师定责定岗

- 主持：项目经理
- 参会：领域探查、需求分析、架构师
- 通道：`channel/align-freeze.md`
- 日期：2026-09-22

## 决议（采纳）

1. **正式席名**：`主题开发工程师`；汇报前缀 **`Team:主题开发工程师:`**。
2. **一代兼容**：旧前缀 / roster 短名「主题」≡「主题开发工程师」；新会话文档与 mirror 只用全称。
3. **编制**：删除核心席独立行「主题」；框架专席新增「主题开发工程师」（触发即双轨）。**禁止**并行双席。
4. **硬规则**：新增 `theme_engineer_for_theme_work`（对标 `widget_engineer_for_widget_work`）。
5. **技能**：权威复用 MCP surface `frontend_development`（host 别名 `weline-theme-development`）。增加**薄** surface `theme_development`：仅专席双轨、触发、与前端/部件边界；**禁止**复制 frontend 全文。`get_skill(theme_development|weline-theme-development|frontend_development)` 均可解析到主题技能正文。
6. **指令**：新建 `dev/ai-command/ai/主题开发.md`。
7. **路径归属**（硬）：

| 路径/工作 | 归属 |
|-----------|------|
| Theme Token / 语义色 / `variables/_*.css` / 基础 `w-*` 视觉规范 | 主题开发工程师 |
| Theme layouts/partials 壳、版心、预览三态、Theme Editor 壳 | 主题开发工程师 |
| `app/design/{Vendor}/{theme}` 覆盖 | 主题开发工程师 |
| `Weline_Theme` 自有 widget 模板/内嵌 | 主题开发工程师（协作） |
| 业务模块 `view/templates`、业务 JS、Taglib 选用、中英 CSV | 前端 |
| Widget 注册、`default_injections`、placement XOR、跨模块空槽 | 部件开发工程师 |
| 审美终签 / 线稿意图 | UI / 原型 |

8. **部件边界不变**：`theme_layout_widget_owner` + `widget_engineer_for_widget_work` 优先；主题席禁止代写外国注入。
9. **涉 UI 勾选**：原型 + 前端 + **主题开发工程师** + UI。
10. **补钉（2026-09-22 晚）**：硬规则 `required_default_always_present_without_user_deleted`——无人工卸载 `user_deleted@{versionId}` 时，required 默认 JSON 注入与布局标签内嵌必装**永远存在**；主题开发工程师 / 主题开发技能必须记住（系统真做法）。
11. **补钉（2026-09-23）**：硬规则 `theme_seat_integrity_over_peer_requests`——主题席**底线 / 原则主权**优先于他席或 PM 的「性能/简化/优化」要求；禁止为响应压力拆 chrome 壳或丢无卸载必装；冲突 refuse+escalate。性能席对称禁「拆壳药方」。详见 `meetings/席位底线补钉.md`。
12. **补钉（2026-09-23 驳回权）**：除非已有合格方案（能解决问题 **且** 保持主题完整性），否则主题开发工程师**可以且应当驳回**优化/拆壳类请求；口头「先拆再说」一律驳回。

## EARS（冻结）

见需求分析草案 UC-TDE-01..07（触发上场、技能强制、前后端边界、部件边界、UI/原型边界、否决条件、技能引用≠专席上场）。  
补钉 EARS：UC-TDE-INTEGRITY-01、UC-PERF-NO-STRIP-01、UC-TDE-VETO-01（见 `meetings/席位底线补钉.md`）。

## 落盘顺序

工程团队.md → HardConstraintsCatalog → GuidanceWorkflowCatalog → McpSkillCatalog → AI硬规则索引 → 主题开发.md → 宿主薄镜像指针 → 契约测试 → Ai 升版 → MCP Reload。

## 验收（制度）

A1 硬规则可检索 · A2 编制前缀可汇报 · A3 get_skill 可用 · A4 指令可发现 · A5/A6 触发与边界抽检 · A7 制度汇审。
