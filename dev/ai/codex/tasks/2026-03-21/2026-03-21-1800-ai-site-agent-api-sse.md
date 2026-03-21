# AI Site Agent：会话 API + SSE + 工作台 UI

- 计划：`dev/ai/plans/codex-pagebuilder-ai-site-agent.plan.md`
- 时间：2026-03-21

## 完成内容

- `AiSiteAgentSessionService`：`replaceScope`、`getLatestEventId`、`listEventsAfterId`；`listRecentEvents` 返回含 `event_id`。
- `AiSiteAgent`：`getStateJson`、`postMergeScope`、`postReplaceScope`、`postSetStage`、`postBindLinks`、`getStreamSse`（SseWriter，约 15 分钟轮询增量事件）。
- `workspace.phtml`：阶段选择、站点/主题绑定、Scope 整体替换与补丁合并、全页预览入口（`preview_page_id` + `weline_theme_id`）、状态 JSON 新窗口、SSE 终端连接。
- i18n：zh_Hans_CN / en_US 增补。
- `register.php`：1.0.25 → 1.0.26。

## 验证

- 登录后台 → AI 建站工作台 → 进入会话 → 连接 SSE、保存阶段/Scope/绑定后观察事件表与终端。
- `setup:upgrade -m GuoLaiRen_PageBuilder` 刷新路由。
