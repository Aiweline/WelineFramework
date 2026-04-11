# AI 建站中台 · PageBuilder 拓展流程 — 计划与进展（模块内收敛）

> 模块：`GuoLaiRen_PageBuilder`
> 最近整理：2026-04-11
> 当前状态：🟡 `in_progress_blocked`
> 本文定位：从 PageBuilder 视角收敛 `Weline_Websites` AI 建站中台与 `pagebuilder` provider 的接口边界、真实进展、阻塞问题和后续修复计划。总规划、接口草图与跨模块任务拆解仍以文末「权威来源」为准。

---

## 1. 角色与边界

| 层级 | 模块 | 职责摘要 | 当前口径 |
|------|------|----------|----------|
| 平台壳 | `Weline_Websites` | 工作台入口、`provider_code` 注册与选择、统一 session / message / event / artifact、默认 `websites_default` 流程 | 作为中台入口与镜像会话协调者 |
| 拓展流程 | `GuoLaiRen_PageBuilder` | 注册 `provider_code = pagebuilder`，持有自有 AI 工具、虚拟主题 / HTML 区块轨、物化 / 可视化编辑 | PageBuilder 原生工作区仍是生成与编辑主事实源 |
| 域名与发布门禁 | `Weline_Websites` + `GuoLaiRen_PageBuilder` | Websites 负责域名购买/生命周期，PageBuilder 消费 `site_ready` 等 scope 字段做 `can_publish` 判断 | 需继续补齐契约测试与端到端验证 |

**核心约束**

- `Weline_Websites` 不直接依赖 PageBuilder 私有实现细节，例如 `weline_theme_id`、`preview_page_id`、虚拟主题内部结构、HTML 区块结构。
- `provider_code` 绑定的是完整流程提供者，不是单纯工具列表；`pagebuilder` 可以有自己的阶段机、SSE、物化与预览逻辑。
- PageBuilder 私有状态通过 `provider_state`、`scope`、artifact payload 由 provider 自行解释；中台只负责透传与通用展示。
- 域名购买等高风险外部动作必须保留显式确认门槛；本地 / E2E 使用 fake provider 或本地供应商，不触发真实购买。
- WLS / hosts / 证书属于运行时基建计划，不在本文展开；本文只记录与 PageBuilder 工作台链路直接相关的依赖与验收入口。

---

## 2. 当前架构快照

### 2.1 工作台入口链路

```text
Websites Hub
  -> provider=pagebuilder
  -> PageBuilderProvider::getWorkbenchConfig()
  -> Websites pagebuilder-handoff / PageBuilder native workspace
  -> PageBuilder AiSiteAgent workspace
```

当前实现中需要特别区分两类会话：

| 会话 | 主职责 | 关键字段 |
|------|--------|----------|
| Websites 镜像会话 | 中台入口、域名流程、handoff 状态、provider 展示 | `provider_code=pagebuilder`、`pagebuilder_workspace_public_id`、`pagebuilder_workspace_url`、`site_ready`、`workspace_track` |
| PageBuilder 原生会话 | AI 建站实际生成、虚拟主题 / HTML 区块状态、预览与发布 | `draft_website_id`、`virtual_theme_id`、`virtual_pages_by_type`、`pagebuilder_pages_by_type`、`active_operation` |

**当前决策**：在 `pagebuilder` provider 下，PageBuilder 原生会话是生成与编辑主事实源，Websites 会话是中台镜像与跨模块 handoff 层。后续如果要把平台 session/event/artifact 变成唯一事实源，需要单独设计迁移方案，不能在修复 SSE 时顺手混改。

### 2.2 PageBuilder 双轨

| 轨道 | 目标 | 现状 |
|------|------|------|
| `virtual_theme` | 兼容旧虚拟主题编排与可视化编辑 | 当前 `PageBuilderProvider` 和 `AiSiteScopeCompatibilityService` 默认仍偏向此轨 |
| `html_blocks` | 新默认方向：页面级 `blocks[]` + `ai_html` 渲染 + 发布前消毒 | 文档已定义方向，代码已存在 `runHtmlBlocksBuildOperation()`，但默认轨口径仍需统一 |

**待统一口径**：`计划-新AI建站工作台-页面区块与智能体.md` 声明“新会话默认 HTML 区块轨”，但当前代码默认值仍是 `virtual_theme`。这不是纯文档问题，后续需由产品/技术确认后同步 provider、兼容服务、UI 文案和测试。

### 2.2.1 虚拟主题轨 vs 既有「模板管理」（样式模板体系）

PageBuilder 既有的**模板管理**指人工维护的样式资产：`view/templates/style/` 下各 style 目录、`template.json` / 组件元数据、Style 扫描、`doc/规范/TEMPLATE_SPEC.md` 与 `COMPONENT_SPEC.md` 约定的目录结构与校验。  
**AI 虚拟主题轨**要把智能体生成的页面/组件产物落到 `VirtualTheme` 与可视化编辑链路，并与上述模板体系在组件代码、区域、预览上对齐。

**当前计划口径（2026-04-11）**：**AI 生成虚拟主题整条链路尚未跑通**（生成质量/语法校验、占位与真实 AI 切换、物化与预览、与工作台 SSE/operation 推进等问题仍并存）。在未闭环前，不得将「虚拟主题轨」与「后台样式模板管理」视为同一套已交付能力；验收以模块内集成测试 + 工作区手动路径为准，并与 §3.3 SSE/operation 阻塞项一并跟踪。

### 2.2.2 模板管理可视化 vs AI 工作区：能力对齐与双轨生成

| 能力域 | 计划口径 |
|--------|----------|
| **模板管理（样式模板 / 人工资产）** | 后台「模板管理」下的可视化编辑、组件目录与预览，**交互与能力模型对齐 PageBuilder 既有可视化编辑**（左配置右预览、区块级操作、组件生成/再生成等），避免另起一套 UX 与术语；实现上仍以 `view/templates/style/`、`template.json`、规范文档为准。 |
| **轻量 HTML / 页面区块轨** | 信息收集与方案确定后，若站点走 **`html_blocks`（轻量 HTML 部件）**，则生成与后续编辑走 **部件生成**（`blocks[]` + `ai_html` / 按块再生成），不强行套入完整 VirtualTheme + style 管线。 |
| **虚拟主题轨（高级）** | 用户或方案明确选择 **高级 / 虚拟主题** 时，再走 VirtualTheme + 可视化物化链路；与上栏「轻量轨」**站点级二选一**，与 `计划-新AI建站工作台-页面区块与智能体.md` 决策 11 一致。 |

### 2.2.3 方案确认门禁（产品流程，优先于「直接生成」）

**统一要求**：在**最开始信息收集完成**后，系统须先产出**可读的建站方案**（页面类型、风格与结构要点、技术轨 `workspace_track`、域名/站点约束等），**以弹窗或独立步骤展示给用户确认**；用户可在此阶段**微调方案**（改页面集合、表述、轨向等）。**仅当用户明确确认方案后**，才进入下一步——按已确认方案执行 **主题/页面生成**（含 SSE build、物化等）。  

- **未确认前**：不得默认触发长耗时生成；与域名等高风险动作一样，属于**显式确认门槛**。  
- **与实现映射**：方案快照进入 `scope` / artifact（如 `site_profile`、页面清单、`workspace_track` 锁定版本）；确认动作写入会话阶段或专用字段，供 `post-start-build` / operation 校验。  
- **文档同步**：详见 `计划-新AI建站工作台-页面区块与智能体.md` 中「方案确认」与阶段划分。

### 2.3 SSE 与操作流

PageBuilder 工作台目前至少存在两类流：

| 流 | 作用 | 计划中的正确边界 |
|----|------|------------------|
| `stream-sse` | 工作区事件回放 / 心跳 / 日志显示 | 只负责展示已持久化事件，不直接执行构建 |
| `operation-sse` | 执行 build / regenerate / publish 等长操作 | 由 `active_operation.execution_token` 单次认领并执行，完成后回写状态和事件 |

**当前阻塞反馈**：同一 `public_id` 出现重复 `stream-sse` 请求，且页面长期停在心跳未推进到虚拟主题生成。计划优先修正这条边界，避免两个 SSE 流相互重连、重复消费或抢占执行状态。

---

## 3. 进展状态

**进度权威文件**：`dev/ai/codex/AI工作台/Websites-AI建站工作台-进度.md`
**进度文件最后更新**：以该文件文首「最后更新」为准（2026-04-10 已纠偏：`epic_8_completed` 不等同 PageBuilder 端到端无阻塞）
**历史文档声明状态**：`epic_8_completed`
**本文修正后的当前执行状态**：🟡 `in_progress_blocked`

### 3.1 里程碑核对

| 里程碑 | 代码 / 文档现状 | 状态 |
|--------|------------------|------|
| 平台归属收敛到 `Weline_Websites` | 总规划已确认，Websites hub 已承担中台入口 | 🟢 已完成 |
| provider / theme source 抽象与 registry | `PageBuilderProvider` 已存在并可返回 workbench config | 🟢 已完成 |
| 核心持久化 session / message / artifact / event | Websites 侧服务已落地，PageBuilder 原生会话仍并存 | 🟡 部分完成 |
| PageBuilder provider 接入 | `provider=pagebuilder` 能配置 PageBuilder 入口与工具卡 | 🟢 已接入 |
| PageBuilder 菜单入口策略 | 当前 PageBuilder 菜单仍指向 `pagebuilder/backend/ai-site-agent/index?legacy=1`，控制器 `index()` 已不是“默认重定向到 Websites” | 🟡 需重新确认 |
| 默认轨策略 | 文档期望新会话默认 `html_blocks`，代码默认仍为 `virtual_theme` | 🟡 需统一 |
| 工作区 SSE 稳定性 | 反馈存在重复订阅、只心跳不推进 | 🔴 阻塞 |
| 端到端验收 | 有相关单测 / 集成 / E2E 文件，但阻塞问题未关闭前不能宣称全链路完成 | 🔴 阻塞 |
| **PHPUnit（模块）** | **不含 `@group integration` 的单元用例应能通过**；带 `integration` / `pagebuilder_phpsim` 的用例依赖 DB、WLS/环境与**真实 AI**，默认不作为「一键全绿」门槛 | 🟡 **单元与集成分离跑** |

### 3.2 已落地的关键点

- `GuoLaiRen_PageBuilder` 已通过扩展目录注册 `pagebuilder` provider。
- `PageBuilderProvider` 能返回 PageBuilder 原生工作区链接、域名/站点/页面管理工具入口，以及 `site_ready`、`workspace_track` 等 scope 说明。
- PageBuilder 原生工作区已存在阶段 2 双轨 UI、`site_ready` 发布门禁提示、构建/发布操作 SSE、工作区日志终端、恢复已有 active operation 的入口。
- `AiSiteAgent` 已有 `operation-sse` 处理 build / regenerate / publish，`runBuildOperation()` 可按 `workspace_track` 分发到 HTML 区块轨或虚拟主题轨。
- `stream-sse` 已有 last event cursor 和去重显示逻辑，具备进一步收敛为“只展示不执行”的基础。
- 嵌入预览：`buildEmbeddedPreviewPayload` / `resolvePayloadComponent` 已从 `.component-actions` 回退读取 `data-component`、`data-page-type` 等，避免点击 **AI Rebuild**（`regenerate-block`）时误报「当前区块暂时无法发起 AI 微调」。

### 3.3 新增阻塞问题（2026-04-08）

1. **SSE 重复启动（前端重复订阅）**
   - 现象：同一会话触发两个同路径同参数的 SSE 请求。
   - 示例：`/pagebuilder/backend/ai-site-agent/stream-sse?public_id=fdc58052be10726bc1b3f42a38a34784&last_event_id=1271`
   - 影响：事件显示、last cursor、终端重连、连接续约和后台 active operation 可能互相覆盖，增加 WLS 长连接压力。

2. **流程卡在心跳，未进入虚拟主题 / HTML 区块生成阶段**
   - 现象：页面长期停留在“正在准备可视化编辑环境，请稍候…”，`stream-sse` 只持续心跳，未看到 build 相关事件推进。
   - **补充（2026-04-10）**：工作区模板中 `showBuildGuard()` 已改为不展示该遮罩（产品侧减轻「卡在准备弹窗」的观感）。**这不改变根因**：若仍存在「只心跳、未进入 `operation-sse` / 构建事件不推进」，须仍以 Network 与 SSE 事件为准排查，验收不可仅凭弹窗是否出现。
   - 可能方向：操作 SSE 未启动、`active_operation` 未正确创建 / 认领、工作区 stream 被暂停后未恢复、前端 startFromResponse 分支未拿到 `execution_token` / `stream_url`。

3. **文档和代码入口策略不一致**
   - 历史进度曾写“PageBuilder `AiSiteAgent::index()` 默认重定向到 Websites hub，`?legacy=1` 打开旧工作台”。
   - 当前代码核对结果：`AiSiteAgent::index()` 渲染 PageBuilder 原生入口，未见 `legacy` 分支；PageBuilder 菜单仍显式带 `legacy=1`。
   - 计划口径：先标记为待确认，不再把“默认重定向”作为已完成事实。

4. **默认轨口径不一致**
   - PageBuilder 新计划希望默认 HTML 区块轨。
   - 当前 `PageBuilderProvider` 与 `AiSiteScopeCompatibilityService::normalizeWorkspaceTrack()` 在无显式 scope 时默认 `virtual_theme`。
   - 计划口径：在阻塞修复后单独做“默认轨切换”小版本，并补回归测试。

5. **HTML 区块构建路径存在可疑重复生成**
   - 代码抽样发现 `runHtmlBlocksBuildOperation()` 中 `generateSharedComponents()` / `shared_layout_generated` 逻辑出现两段相近调用。
   - 计划口径：作为性能与重复 AI 调用风险列入 P2，不在本计划文档阶段直接改代码。

6. **虚拟主题 AI 生成与「模板管理」未对齐（2026-04-11）**
   - 现象：样式模板体系（扫描、组件目录、`template.json`）在 PageBuilder 内已长期存在；**AI 建站虚拟主题轨**从生成、落库到可视化预览仍**未形成与模板管理一致的可验收闭环**。
   - 计划口径：在 P2 操作推进与虚拟主题生成逻辑修通之前，产品描述上区分「人工模板资产」与「AI 虚拟主题草稿」；技术侧将「AI 虚拟主题 ↔ 模板规范」对齐列为虚拟主题轨专项，与 `html_blocks` 轨验收分列。

---

## 4. 修复与完善计划

### P0. 建立基线与可观测性（🟡 进行中）

目标：先证明问题发生在哪个层级，避免盲目改 UI 或服务端。

- 给 PageBuilder 工作区 SSE 增加可追踪字段：`public_id`、`last_event_id`、`stream_kind`、`tab_token`、`lease_token`、`active_operation.execution_token`、连接开始/关闭原因。
- 浏览器侧记录每次 `startWorkspaceStream()`、`pauseWorkspaceStream()`、`resumeWorkspaceStream()`、`startOperationStream()` 的调用来源，至少在 DEV 下输出到工作区日志或 console。
- 服务端 `stream-sse` 日志区分 snapshot、heartbeat、done、fast-fail、client-abort、lease-expired。
- 对 `default` 实例做一次复现记录：打开 PageBuilder 工作区、触发构建、保存 Network 中 `stream-sse` / `operation-sse` 数量与时序。

完成标准：

- 能用一次复现日志回答“重复连接是谁启动的”和“为什么只心跳不进入 operation-sse”。
- 如果没有复现，也要记录单连接正常链路作为回归基线。

### P1. SSE 单连接治理（🔴 阻塞优先）

目标：同一浏览器标签页、同一 `public_id` 在任意时刻只保留一个工作区 `stream-sse` 展示连接；操作流和展示流互不抢占。

- 前端抽出工作区流控制器，集中管理 `terminal.start()`，避免多个脚本块或恢复逻辑重复调用。
- `startWorkspaceStream()` 增加本地状态锁：已 running / starting 时直接返回；重连 timer 必须先清理旧 timer。
- `pauseWorkspaceStream()` 必须同时停止 terminal、清理重连 timer，并记录 pause reason；`resumeWorkspaceStream()` 必须检查 active operation 是否仍在运行，避免 operation-sse 正在执行时提前恢复展示流。
- 引入稳定的 `tab_token`，并通过 `stream-sse` 参数透传；后端可记录每个 `public_id` 的最近活跃连接，DEV 下发现同一 tab 重复连接直接告警。
- 补齐 `pagehide` / `beforeunload` / `visibilitychange` 下的关闭策略，避免隐藏标签页后台无限重连。

完成标准：

- Network 面板中同一工作区生命周期最多一个活动 `stream-sse`。
- 触发 build 时最多一个 `operation-sse`，展示流按设计暂停，operation 完成后恢复一次。
- 后端日志不再出现同一 `public_id + tab_token` 的并发 `stream-sse`。

### P2. 操作执行与阶段推进（🔴 阻塞优先）

目标：用户点击生成后必须从心跳推进到明确的操作事件，并在第一批页面就绪时让 UI 进入可编辑状态。

- `post-start-build` 只负责创建 / 更新 `active_operation`，返回 `execution_token` 和 `operation-sse` URL；不得直接执行长任务。
- `operation-sse` 必须原子认领 `active_operation`：`queued -> running -> done/error/cancelled`，同一 `execution_token` 第二次连接只能观察或拒绝，不能重复执行。
- `operation-sse` 启动后立即持久化 `operation_started`，每个阶段持久化 `operation_progress` / `page_generated` / `environment_ready` / `operation_completed`。
- `runBuildOperation()` 明确双轨阶段事件：`virtual_theme` 至少输出“准备资料、生成虚拟主题骨架、生成页面、物化、环境就绪”；`html_blocks` 至少输出“准备资料、生成共享组件、生成 HTML 区块、物化、环境就绪”。
- 修复 HTML 区块轨中共享组件重复生成风险，避免重复 AI 调用、重复日志和额外等待。
- 失败路径必须回写 `active_operation.status=error`，并恢复按钮 / guard / stream，不允许 UI 卡在“准备中”。

完成标准：

- 正常输入后，SSE 不再只有 heartbeat，至少能看到 `operation_started` 和第一条 `operation_progress`。
- 第一张页面 materialize 成功后发出 `environment_ready`，工作区不硬刷新即可进入可预览 / 可编辑状态。
- 构建失败时用户能在原页修改输入并重试。

### P3. Handoff 与默认轨统一（🟡 待确认）

目标：把 Websites 镜像会话与 PageBuilder 原生会话之间的职责写清楚，并让默认轨与产品口径一致。

- 重新确认 PageBuilder 入口策略：保留原生入口、默认跳 Websites hub、还是按菜单分离“中台入口 / 原生调试入口”。
- 如果确认“新会话默认 HTML 区块轨”，同步修改 `PageBuilderProvider`、`AiSiteScopeCompatibilityService`、工作区 UI 默认文案和测试断言。
- `pagebuilder-handoff` 必须保证以下字段双向同步：`pagebuilder_workspace_public_id`、`pagebuilder_workspace_url`、`target_domain` / `selected_domain`、`site_ready`、`workspace_track`、`fake_mode`。
- 明确 Websites 镜像会话不写 PageBuilder 私有结构；PageBuilder 原生会话只消费标准 handoff 字段。

完成标准：

- 新建 `provider=pagebuilder` 会话后，入口、默认轨、域名状态和 PageBuilder 工作区展示一致。
- `site_ready=0` 时发布被拦截但草稿生成可继续；`site_ready=1` 时发布检查可通过其他内容条件继续。

### P4. 测试与回归矩阵（🟡 待补）

| 目标 | 建议覆盖 |
|------|----------|
| Provider handoff | `PageBuilderProviderTest` 补默认轨、native URL、scope 字段 |
| Scope 兼容 | `AiSiteScopeCompatibilityServiceTest` / `AiSiteScopeHtmlTrackTest` 补默认轨与 `site_ready` |
| 操作状态机 | `AiSiteWorkbenchSuccessIntegrationTest` 补 `post-start-build -> operation-sse -> environment_ready` |
| SSE 去重 | 新增前端可测 helper 或 E2E 断言：同一 `public_id` 只存在一个工作区 stream |
| HTML 区块轨 | `AiSiteHtmlBlocksBuildServiceTest` / 集成测试补共享组件只生成一次 |
| Handoff 域名门禁 | 集成测试覆盖 Websites scope 写入 `site_ready=0/1` 后 PageBuilder 发布检查 |
| E2E 主路径 | `tests/e2e/specs/backend/pagebuilder-ai-site-workbench.spec.js` 覆盖打开工作区、开始生成、出现可编辑预览、发布前门禁 |

建议验证命令（按实际测试粒度裁剪）：

```bash
# 模块全量（推荐直接用 vendor/phpunit，以便传 --exclude-group 等 PHPUnit 原生参数）
php vendor/phpunit/phpunit/phpunit --configuration dev/phpunit/config.xml --testsuite GuoLaiRen_PageBuilder

# 真实 AI + 写库全链路仿真（默认跳过）：设置环境变量后再跑对应用例
# Windows PowerShell: $env:PAGE_BUILDER_RUN_PHPSIM='1'
# bash: export PAGE_BUILDER_RUN_PHPSIM=1
php vendor/phpunit/phpunit/phpunit --configuration dev/phpunit/config.xml app/code/GuoLaiRen/PageBuilder/test/Integration/AiSiteWorkbenchPhpunitSimulationIntegrationTest.php

php bin/w phpunit:run --module=GuoLaiRen_PageBuilder --filter=PageBuilderProviderTest
php bin/w phpunit:run --module=GuoLaiRen_PageBuilder --filter=AiSiteScopeHtmlTrackTest
php bin/w phpunit:run --module=GuoLaiRen_PageBuilder --filter=AiSiteWorkbenchSuccessIntegrationTest
php bin/w phpunit:run --module=GuoLaiRen_PageBuilder --filter=AiSiteAgentWorkflowTest
```

### P5. 文档收口（🟡 待补）

- 阻塞修复后同步本文、`计划-新AI建站工作台-页面区块与智能体.md`、`阶段2-预置工作区-双轨-IA.md`。
- 如果入口策略发生变化，同步 `app/code/GuoLaiRen/PageBuilder/doc/README.md` 与菜单说明。
- `dev/ai/codex/AI工作台/Websites-AI建站工作台-进度.md` 文首与里程碑表已追加 2026-04-10 纠偏说明，区分 Epic 8 范围与 PageBuilder 端到端验收。

---

## 5. 关键实现位置

| 用途 | 路径 |
|------|------|
| Provider 实现 | `app/code/GuoLaiRen/PageBuilder/extends/module/Weline_Websites/AiSiteBuilderProvider/PageBuilderProvider.php` |
| Provider 单元测试 | `app/code/GuoLaiRen/PageBuilder/test/Unit/Extends/Module/Weline_Websites/AiSiteBuilderProvider/PageBuilderProviderTest.php` |
| PageBuilder 原生入口 / 工作区控制器 | `app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php` |
| PageBuilder 工作区模板 / SSE 前端 | `app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace.phtml` |
| Scope 兼容与双轨默认值 | `app/code/GuoLaiRen/PageBuilder/Service/AiSiteScopeCompatibilityService.php` |
| HTML 区块构建服务 | `app/code/GuoLaiRen/PageBuilder/Service/AiSiteHtmlBlocksBuildService.php` |
| PageBuilder 物化服务 | `app/code/GuoLaiRen/PageBuilder/Service/AiSiteMaterializationService.php` |
| PageBuilder 发布服务 | `app/code/GuoLaiRen/PageBuilder/Service/AiSitePublishService.php` |
| PageBuilder 菜单 | `app/code/GuoLaiRen/PageBuilder/etc/backend/menu.xml` |
| Websites 中台控制器 / handoff | `app/code/Weline/Websites/Controller/Backend/SiteBuilderAgent.php` |
| Websites 工作区模板 | `app/code/Weline/Websites/view/templates/Backend/SiteBuilderAgent/workspace.phtml` |
| PageBuilder E2E 示例 | `tests/e2e/specs/backend/pagebuilder-ai-site-workbench.spec.js` |

---

## 6. 决策自审

| 问题 | 当前回答 |
|------|----------|
| 是否把 Websites 与 PageBuilder 私有字段耦合起来？ | 不允许；只通过 provider scope / artifact / URL handoff。 |
| 是否把 SSE 重复连接当成单纯前端小 bug？ | 不当成小 bug；它影响 WLS 长连接、operation 状态机和用户可见流程推进。 |
| 是否要同时重构 session 单一事实源？ | 暂不做；当前先修阻塞，单一事实源迁移另起计划。 |
| 默认轨是否已经确定为 HTML 区块？ | 文档倾向是，但代码未统一；需确认后单独改默认值与测试。 |
| 是否可宣称 Epic 8 全部完成？ | 不能；PageBuilder provider 接入已完成，但端到端工作流仍被 SSE / 阶段推进问题阻塞。 |
| 是否需要真实域名购买验证？ | 不需要；必须 fake / 本地供应商验证，真实购买保留人工确认门槛。 |
| WLS 性能问题是否纳入本文？ | 只记录作为运行依赖；WLS 架构与日志修复在 Server/WLS 任务中跟踪。 |

---

## 7. 权威来源与延伸阅读

| 文档 | 相对仓库根路径 |
|------|------------------|
| 总规划 | `dev/ai/codex/AI工作台/Websites-AI建站工作台-总规划.plan.md` |
| 接口草图 | `dev/ai/codex/AI工作台/Websites-AI建站工作台-接口草图.md` |
| 任务拆解（Epic 0～11） | `dev/ai/codex/AI工作台/Websites-AI建站工作台-任务拆解.task.md` |
| 执行进度 | `dev/ai/codex/AI工作台/Websites-AI建站工作台-进度.md` |
| AI 工作台目录说明 | `dev/ai/codex/AI工作台/README.md` |
| Websites 侧专文 | `app/code/Weline/Websites/doc/计划-AI建站工作台-Websites侧.md` |
| PageBuilder 页面侧专文 | `app/code/GuoLaiRen/PageBuilder/doc/计划-新AI建站工作台-页面区块与智能体.md` |
| PageBuilder 双轨 IA | `app/code/GuoLaiRen/PageBuilder/doc/阶段2-预置工作区-双轨-IA.md` |

---

## 8. 维护说明

- 2026-04-10：已与 `dev/ai/codex/AI工作台/Websites-AI建站工作台-进度.md` 做 Epic 8 / 端到端纠偏同步；后续仍以 Codex 文首时间戳为进度锚点。
- 更新进展时，优先同步 `dev/ai/codex/AI工作台/Websites-AI建站工作台-进度.md`，再修订本文「3. 进展状态」与「4. 修复与完善计划」，避免双写漂移。
- `任务拆解.task.md` 内部分 `[ ]` 可能与「里程碑已完成」表述不一致时，以代码、测试和最新进度补记为准。
- 本文列出的问题关闭后，必须把状态从 🟡 / 🔴 改为 🔵 测试中或 🟢 已完成，并补充验证命令与结果。
