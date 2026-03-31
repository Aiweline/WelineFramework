# Websites AI建站工作台进度

- 最后更新：2026-03-28 15:45
- 当前状态：epic_8_completed
- 当前阶段：Epic 8 完成，全部 Epic 已收尾

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
| PageBuilder provider 接入 | 已完成 | PageBuilderProvider + PageBuilderProviderTest |
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
3. `Weline_Websites` 核心仍未感知 `PageBuilder` 私有字段，边界保持干净。

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

## 下一步建议

1. 主线进入 Epic 3：后台控制器、工作台 JSON API、SSE 流接口。
2. 让 Epic 3 只依赖当前服务层，不跨层直操作模型。
3. 主题选择能力仍建议在 Epic 6 通过 `WebsiteThemeSource` 注入，不提前耦合到 Websites 核心。
## 2026-03-23 Entry Integration Slice

1. `Weline_Websites` `SiteBuilderAgent` is no longer just a plain one-shot form page.
   - The entry now acts as a more human-friendly hub.
   - It exposes provider cards and makes `PageBuilder` visible as an extension path.
   - The fast-build form now surfaces AI mode explicitly, so domain/account inputs are optional when AI mode is enabled.
2. `GuoLaiRen_PageBuilder` now registers `pagebuilder` under `AiSiteBuilderProvider`.
   - `generated/extends.php` includes `AiSiteBuilderProvider/PageBuilderProvider.php`.
3. Compatibility entry strategy is now partially implemented.
   - `GuoLaiRen_PageBuilder\Controller\Backend\AiSiteAgent::index()` redirects to the Websites hub by default.
   - `?legacy=1` still opens the old PageBuilder session workbench.
   - The legacy PageBuilder workbench index now includes a back-link to the Websites hub.
4. Duplicate menu coupling has been cleaned up.
   - `Weline_Websites::site_builder_agent_pagebuilder` has been removed.
5. This still does **not** mean the whole plan is finished.
   - Epic 1 and Epic 2 were already done before this slice.
   - The platform-level unified session/message/event/artifact workbench is still not the single source of truth.
   - The default `websites_default` provider still lacks the full planned conversation/theme/draft/materialization flow.
