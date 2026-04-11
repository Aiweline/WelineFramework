# Websites AI建站工作台进度

- 最后更新：2026-04-11
- 当前状态：epic_8_completed（契约与硬化范围）；PageBuilder 原生工作区端到端仍见阻塞项（见下方纠偏）
- **补充（2026-04-11）**：`GuoLaiRen_PageBuilder` 侧 **AI 虚拟主题轨**与既有**样式模板/模板管理**体系尚未形成可验收闭环；**模块 PHPUnit** 应以 `exclude-group=integration` 跑单元为主，集成/仿真（含真实 AI、`pagebuilder_phpsim`）单独环境执行。详见 [`app/code/GuoLaiRen/PageBuilder/doc/计划-AI建站中台-PageBuilder拓展流程与进展.md`](../../../app/code/GuoLaiRen/PageBuilder/doc/计划-AI建站中台-PageBuilder拓展流程与进展.md) §2.2.1、§3.1、§3.3-6。
- **产品流程（2026-04-11）**：信息收集后须 **先展示建站方案并支持微调、用户确认后** 再进入主题/页面生成；模板管理可视化对齐 PB 可视化；轻量 HTML 走 **部件生成**（`html_blocks`）。见 [`app/code/GuoLaiRen/PageBuilder/doc/计划-AI建站中台-PageBuilder拓展流程与进展.md`](../../../app/code/GuoLaiRen/PageBuilder/doc/计划-AI建站中台-PageBuilder拓展流程与进展.md) §2.2.2～§2.2.3 与 [`app/code/GuoLaiRen/PageBuilder/doc/计划-新AI建站工作台-页面区块与智能体.md`](../../../app/code/GuoLaiRen/PageBuilder/doc/计划-新AI建站工作台-页面区块与智能体.md) 决策 12～14、阶段 1.5。
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
| **PageBuilder 端到端工作区验收** | **进行中 / 有阻塞** | `stream-sse` / `operation-sse`、阶段推进、默认轨与入口策略等；见上文 PB 收敛文档 §3～§4（P0～P2） |

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

1. **P0 可观测性**：为工作区 `stream-sse` / `operation-sse` 建立复现基线与日志字段（`public_id`、`last_event_id`、`tab_token`、`execution_token` 等），区分「重复连接来源」与「只心跳不进入 operation」。
2. **P1 SSE 单连接治理**：同一标签页、同一 `public_id` 仅保留一条展示用 `stream-sse`；构建时 `operation-sse` 与展示流按设计暂停/恢复，避免抢占。
3. **P2 操作执行与阶段推进**：`post-start-build` 与 `operation-sse` 认领/事件持久化闭环；失败路径回写 `active_operation`，避免 UI 长期卡在「准备中」类状态。
4. **P3 及以后**：handoff 与默认轨（`html_blocks` vs `virtual_theme`）、入口策略与回归矩阵，仍以 PB 收敛文档 §4～§5 为准。

历史说明：Epic 3～8 对应能力已按里程碑表落地；上列为 **PageBuilder 原生工作区与中台衔接** 的后续工程重点，不重复旧 Epic 序号叙事。
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
