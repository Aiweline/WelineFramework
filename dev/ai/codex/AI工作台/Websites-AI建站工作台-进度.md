# Websites AI建站工作台进度

- 最后更新：2026-04-13








## 里程碑状态

| 里程碑 | 状态 | 说明 |
|---|---|---|
| 平台归属收敛 | 已完成 | 入口归属 `Weline_Websites` |
| provider 抽象设计 | 已完成 | `provider_code` 绑定完整流程 provider |
| theme source 抽象设计 | 已完成 | Theme 通过独立扩展点接入 |
| 任务拆解 | 已完成 | 已拆到可实施 Epic 级别 |
| Epic 1 扩展契约与 registry | 已完成 | provider/theme source contract 与 registry 已落地 |
| 核心持久化模型 | 已完成 | session / message / artifact / event 与服务层已落地 |
| Epic 3 工作台壳与 API | 已完成 | 三阶段 UI + getStageInfo + getDomainLifecycleStatus API |
| Epic 4 域名流程 | 已完成 | RecommendDomainsTool + ConfirmDomainPurchaseTool |
| Epic 5 页面生成 | 已完成 | WelineThemeSource + GeneratePageDraftTool |
| Epic 6 完成预览 | 已完成 | PreviewWebsiteTool + DomainLifecycleBridge 状态条 |

| **Epic 8 测试与硬化** | **已完成** | 全部 Tool 有 UT，DB 测试受环境限制 |


## Epic 1 完成情况

1. 已在 `app/code/Weline/Websites/extends.php` 定义：
   - `AiSiteBuilderProvider`
   - `WebsiteThemeSource`
2. 已新增基础契约：
   - `AiSiteBuilderProviderInterface`
   - `WebsiteThemeSourceInterface`
   - 对应 registry interface 与 factory
3. 已新增基础设施：
   - `ExtensionPointReader`
   - `ProviderRegistry`
   - `ThemeSourceRegistry`
4. 已新增内置默认 provider：
   - `websites_default`
5. 已完成验证：
   - 新文件 `php -l`
   - 两个 registry 单测共 `4 tests / 13 assertions`
   - `setup:upgrade -m Weline_Websites --yes`
   - `generated/extends.php` 已出现 `AiSiteBuilderProvider/WebsitesDefaultProvider.php`

## 当前边界说明

1. 本轮只落“扩展契约 + registry + 默认 provider”，没有把 Theme 真正主题源能力提前塞进来。
2. `ThemeSourceRegistry` 已可用，但当前仍等待 `Weline_Theme` 或其他模块提供真实 source 实现。


## Epic 2 完成情况

1. 已新增四个核心模型：
   - `AiSiteBuilderSession`
   - `AiSiteBuilderMessage`
   - `AiSiteBuilderArtifact`
   - `AiSiteBuilderEvent`
2. 已新增四个服务封装：
   - `SessionService`
   - `MessageService`
   - `ArtifactService`
   - `EventStreamService`
3. 已完成持久化语义：
   - session 保存 provider / stage / scope / provider_state / website / domain / preview
   - message 保存聊天与工具消息
   - artifact 以 `(session_id, artifact_type, artifact_code)` 做 upsert
   - event 以 append-only 方式服务 SSE 回放
4. 已完成验证：
   - Epic 2 新增文件 `php -l` 全通过
   - `setup:upgrade -m Weline_Websites --yes` 已通过
   - 定向 PHPUnit：`4 tests / 53 assertions`
5. 发现并绕过一个环境噪音：
   - 旧的 `generated/routers/backend_pc.php` 生成物损坏会阻塞升级
   - 清理旧生成物后，升级成功



1. **阶段 1.5 plan-first**：补齐方案生成入口、方案 artifact / scope 持久化、confirmed plan 状态与 build 门禁。
2. **显式续跑替代自动续跑**：把当前 `auto_start_build_after_stream` / active-operation auto resume 改成“检测到未完成任务 -> 用户确认继续”。
3. **`stream-sse` 单连接治理**：补全 tab token 端到端、local lock、pause/reconnect reason、页面生命周期清理。

5. **浏览器与 E2E 收口**：围绕 confirmed-plan -> build -> resume -> publish 主链路补浏览器回归。


## 2026-03-23 Entry Integration Slice

1. `Weline_Websites` `SiteBuilderAgent` is no longer just a plain one-shot form page.
   - The entry now acts as a more human-friendly hub.

   - The fast-build form now surfaces AI mode explicitly, so domain/account inputs are optional when AI mode is enabled.


3. Compatibility entry strategy needs to be read as a historical note, not current truth.
   - Earlier progress once described `AiSiteAgent::index()` as default-redirecting to the Websites hub with `?legacy=1` for the old workbench.
   - Current code no longer matches that description.

4. Duplicate menu coupling has been cleaned up.

5. This still does **not** mean the whole plan is finished.
   - Epic 1 and Epic 2 were already done before this slice.
   - The platform-level unified session/message/event/artifact workbench is still not the single source of truth.
   - The default `websites_default` provider still lacks the full planned conversation/theme/draft/materialization flow.
