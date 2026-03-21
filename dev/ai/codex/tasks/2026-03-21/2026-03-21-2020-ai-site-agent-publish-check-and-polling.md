# AI Site Agent：发布前检查 + 自动轮询

- 计划：`dev/ai/plans/codex-pagebuilder-ai-site-agent.plan.md`（todo 5）
- 时间：2026-03-21

## 完成

- `AiSiteAgent::postPublishChecklist`：汇总会话发布前检查项（`website_id`、`weline_theme_id`、`preview_page_id`、`target_domain`、域名状态），并写入 `publish_check` 事件。
- `workspace.phtml`：
  - 域名步骤回补「查询域名状态」按钮与结果面板。
  - 等域名步骤新增「自动轮询（10秒）」开关。
  - 发布步骤新增「执行发布前检查」按钮与 JSON 输出面板。
- i18n 增补检查与轮询文案（zh/en）。
- 已执行 `php bin/w setup:upgrade -m GuoLaiRen_PageBuilder --yes` 刷新路由。

## 验证

- 控制器语法检查通过：`php -l app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php`
- 升级命令完成并路由更新成功（存在历史 ACL 同步告警，与本任务无直接关联）。
