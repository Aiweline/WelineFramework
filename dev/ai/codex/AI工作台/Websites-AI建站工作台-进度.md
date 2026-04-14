# Websites AI建站工作台进度

- 最后更新：2026-04-13
- 当前状态：epic_8_completed（平台契约范围）；PageBuilder 原生工作区已从 P0～P2 全阻塞收敛到“部分打通 + plan-first 待落地”
- **补充（2026-04-11）**：`GuoLaiRen_PageBuilder` 侧 **AI 虚拟主题轨**与既有**样式模板/模板管理**体系尚未形成可验收闭环；**模块 PHPUnit** 应以 `exclude-group=integration` 跑单元为主，集成/仿真（含真实 AI、`pagebuilder_phpsim`）单独环境执行。详见 [`app/code/GuoLaiRen/PageBuilder/doc/计划-AI建站中台-PageBuilder拓展流程与进展.md`](../../../app/code/GuoLaiRen/PageBuilder/doc/计划-AI建站中台-PageBuilder拓展流程与进展.md) §2.2.1、§3.1、§3.3-6。
- **产品流程（2026-04-11）**：信息收集后须 **先展示建站方案并支持微调、用户确认后** 再进入主题/页面生成；模板管理可视化对齐 PB 可视化；轻量 HTML 走 **部件生成**（`html_blocks`）。见 [`app/code/GuoLaiRen/PageBuilder/doc/计划-AI建站中台-PageBuilder拓展流程与进展.md`](../../../app/code/GuoLaiRen/PageBuilder/doc/计划-AI建站中台-PageBuilder拓展流程与进展.md) §2.2.2～§2.2.3 与 [`app/code/GuoLaiRen/PageBuilder/doc/计划-新AI建站工作台-页面区块与智能体.md`](../../../app/code/GuoLaiRen/PageBuilder/doc/计划-新AI建站工作台-页面区块与智能体.md) 决策 12～14、阶段 1.5。
- **补充（2026-04-13）**：PageBuilder 原生工作区已经补上 **task-level checkpoint / `build_task_summary`**、duplicate `operation-sse` observer、unfinished build auto-resume、block confirm-before-apply。当前最主要的未完成项已收敛为：**阶段 1.5 方案生成/确认、自动续跑改显式继续、`stream-sse` 单连接治理、默认轨与入口口径统一、浏览器回归**。
- 当前阶段：Websites 侧 Epic 已收尾；**PageBuilder provider 全链路验收以模块内收敛文档为准，不得将「Epic 8 完成」等同为 PB 端到端无阻塞**

**纠偏（2026-04-10）**：`epic_8_completed` 指本仓库已规划的 **扩展契约、registry、持久化、工作台壳层、域名与预览相关 Tool、单测/硬化** 等范围内事项。**`GuoLaiRen_PageBuilder` 原生 AI 建站工作区** 仍可能存在 **`stream-sse` 重复订阅、operation 阶段推进、默认轨口径** 等问题；权威拆解、阻塞分级与修复路线见 [`app/code/GuoLaiRen/PageBuilder/doc/计划-AI建站中台-PageBuilder拓展流程与进展.md`](../../../app/code/GuoLaiRen/PageBuilder/doc/计划-AI建站中台-PageBuilder拓展流程与进展.md) 第 3～4 节。

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
| **PageBuilder 端到端工作区验收** | **进行中 / 已拆小任务** | task checkpoint / observer / pending-resume / block confirm 已落地；剩余聚焦阶段 1.5、显式续跑、默认轨、入口口径与浏览器回归 |

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

## 下一步建议（与 PageBuilder 收敛计划对齐）

1. **阶段 1.5 plan-first**：补齐方案生成入口、方案 artifact / scope 持久化、confirmed plan 状态与 build 门禁。
2. **显式续跑替代自动续跑**：把当前 `auto_start_build_after_stream` / active-operation auto resume 改成“检测到未完成任务 -> 用户确认继续”。
3. **`stream-sse` 单连接治理**：补全 tab token 端到端、local lock、pause/reconnect reason、页面生命周期清理。
4. **默认轨与入口口径统一**：明确 `html_blocks` vs `virtual_theme` 默认值，以及 Websites hub / PageBuilder 原生首页 / 菜单入口的最终策略。
5. **浏览器与 E2E 收口**：围绕 confirmed-plan -> build -> resume -> publish 主链路补浏览器回归。

历史说明：Epic 3～8 对应能力已按里程碑表落地；上列为 **PageBuilder 原生工作区与中台衔接** 的后续工程重点，不重复旧 Epic 序号叙事。
## 2026-03-23 Entry Integration Slice

1. `Weline_Websites` `SiteBuilderAgent` is no longer just a plain one-shot form page.
   - The entry now acts as a more human-friendly hub.
   - It exposes provider cards and makes `PageBuilder` visible as an extension path.
   - The fast-build form now surfaces AI mode explicitly, so domain/account inputs are optional when AI mode is enabled.
2. `GuoLaiRen_PageBuilder` now registers `pagebuilder` under `AiSiteBuilderProvider`.
   - `generated/extends.php` includes `AiSiteBuilderProvider/PageBuilderProvider.php`.
3. Compatibility entry strategy needs to be read as a historical note, not current truth.
   - Earlier progress once described `AiSiteAgent::index()` as default-redirecting to the Websites hub with `?legacy=1` for the old workbench.
   - Current code no longer matches that description.
   - `GuoLaiRen_PageBuilder\Controller\Backend\AiSiteAgent::index()` now renders the native PageBuilder home and exposes a back-link to the Websites hub; the final menu/entry strategy still needs explicit product confirmation.
4. Duplicate menu coupling has been cleaned up.
   - `Weline_Websites::site_builder_agent_pagebuilder` has been removed.
5. This still does **not** mean the whole plan is finished.
   - Epic 1 and Epic 2 were already done before this slice.
   - The platform-level unified session/message/event/artifact workbench is still not the single source of truth.
   - The default `websites_default` provider still lacks the full planned conversation/theme/draft/materialization flow.
