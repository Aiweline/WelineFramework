# PageBuilder Site Builder 计划推进

- 计划：`dev/ai/plans/codex-pagebuilder-site-builder.plan.md`
- 时间：2026-03-21

## 本次完成

- 对照仓库复核：持久化层 `AiSiteAgentSession` / `AiSiteAgentSessionEvent` 与 `AiSiteAgentSessionService` 已存在（与 `codex-pagebuilder-ai-site-agent` 计划一致）。
- 补齐 PageBuilder 侧**后台入口**：`Controller/Backend/AiSiteAgent.php`（index / workspace / postCreateSession）。
- `AiSiteAgentSessionService::listRecentSessionsForAdmin()`：入口页「最近会话」列表。
- 模板：`view/templates/Backend/AiSiteAgent/index.phtml`、`workspace.phtml`、`workspace-error.phtml`。
- 菜单：`etc/backend/menu.xml`「快速建站」下新增「AI 建站工作台」→ `pagebuilder/backend/aiSiteAgent/index`。
- i18n：`i18n/zh_Hans_CN.csv`、`en_US.csv` 增补相关词条。
- 本地执行 `php bin/w setup:upgrade -m GuoLaiRen_PageBuilder --yes` 以刷新路由（若进程未完成需人工确认）。

## 后续建议

- 将简报/域名/SSE/虚拟主题生成接到 `mergeScope` / `appendEvent` / 阶段字段上。
- 与 `Weline_Websites` 的「建站智能体」分工：购买与基础设施走 Websites；PageBuilder 工作台走本会话。
- `php bin/w http:request admin -b pagebuilder/backend/aiSiteAgent/index` 需在已登录后台 Session 下验证。

## 变更文件（摘要）

- `app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php`（新）
- `app/code/GuoLaiRen/PageBuilder/Service/AiSiteAgentSessionService.php`
- `app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/*.phtml`（新）
- `app/code/GuoLaiRen/PageBuilder/etc/backend/menu.xml`
- `app/code/GuoLaiRen/PageBuilder/i18n/zh_Hans_CN.csv`、`en_US.csv`
- `dev/ai/plans/codex-pagebuilder-site-builder.plan.md`
