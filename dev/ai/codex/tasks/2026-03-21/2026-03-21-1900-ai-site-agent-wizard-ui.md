# AI Site Agent：建站向导 UI

- 计划：`dev/ai/plans/codex-pagebuilder-ai-site-agent.plan.md`（todo 5）
- 时间：2026-03-21

## 完成

- `AiSiteAgent::workspace` 注入 `scope` 数组与 `wizard_links`（快速建站、域名、网站列表、页面、建站智能体）。
- `workspace.phtml`：八阶段向导 + `postMergeScope` 保存；阶段 Tab 切换；「并设为当前阶段」走 `postSetStage`；JSON 编辑收入 `<details>` 高级区。
- i18n 增补；`register.php` → `1.0.28`。

## 后续

- 向导内触发 AI 生成、域名 SaaS 状态展示；发布动作与 todo 6 验收。
