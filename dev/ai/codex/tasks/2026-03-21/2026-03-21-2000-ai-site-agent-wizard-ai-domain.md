# AI Site Agent：向导内 AI 简报 + 域名状态

- 计划：`codex-pagebuilder-ai-site-agent` todo 5
- 时间：2026-03-21

## 完成

- `AiSiteAgent::postQueryDomainStatus` → `QuickBuildAggregator::getDomainLifecycleStatus`，事件 `domain_status`。
- `AiSiteAgent::postAiGenerateBrief` → `AiService::generate`（场景 `pagebuilder_component_generation`）+ `AiResponseJsonParser`，合并 scope，事件 `ai_brief`。
- `workspace` assign：`ai_module_available`、`query_domain_status_url`、`ai_generate_brief_url`。
- `workspace.phtml`：简报步 AI 按钮；域名/等域名步查询与双 `<pre>` 同步；JS `runQueryDomain` / `setDomainStatusPre`。
- i18n；`register.php` `1.0.29`。

## 验证

- `setup:upgrade -m GuoLaiRen_PageBuilder` 注册新 action。
- 后台：填域名 → 查询状态；填需求 → AI 生成（需 Weline_Ai + 模型）。
