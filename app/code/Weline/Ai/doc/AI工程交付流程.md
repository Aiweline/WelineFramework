# Weline AI 工程交付流程

> 本文是 Weline 框架仓 AI 客户端执行开发任务的**强制工作流**入口。与 [AI开发治理](./AI开发治理.md) 配套：治理定义权威与门禁，本文定义**按什么顺序做**。

## 适用对象

- Codex、Cursor 及其他通过 `weline_project_intelligence` MCP 接入的客户端。
- 问答、概念解释可跳过写码门禁；**任何改代码、修 bug、补测试、验收结论**必须遵循本文。

## 核心原则（Vibe Coding 工程化）

1. **先定义问题，再写代码**——目标、非目标、成功标准不明时不执行。
2. **先计划，再执行**——`plan.md` / `task.md` 或 TaskContract 先于 `apply_compact_edit`。
3. **每步可验证**——「看起来对」不算完成；要有测试、命令或 WebUI 证据。
4. **AI 不能自证正确**——只信可复现命令、测试输出、diff 与 Browser 结果。
5. **规范代码化**——能写成 lint/test/schema/CI 的，不只用自然语言提醒。

## 强制阶段

```text
0 引导与 ready     ensure-project-guidance → prepare_project
1 定位与需求确认   需求.md / 用户确认 / set_session_directives（临时）
2 扩展点选型       扩展点选型.md → 文档索引 / doc/event / Query / Hook
3 计划拆解         plan.md + task.md（或 TaskContract）
4 实现             get_edit_bundle → apply_compact_edit（授权范围内）
5 三维复审         架构 / 缺陷 / 安全
6 分层测试         单测 → 运行时 → WebUI（按变更表面）
7 收口             文档对齐（README/需求/开发日志）+ 门禁表 + 交付证据
```

### 0. 引导与 ready

- 运行 `php app/code/Weline/Ai/Mcp/scripts/ensure-project-guidance.php`。
- 调用 `prepare_project`；仅 `status=ready` 且在 `dev` 分支继续。
- 若当前会话没有 Weline MCP 工具或持续返回 `Transport closed`，先完成引导脚本的自动修复并至少重试一次；仍不可调用时记录 `HOST_MCP_NOT_ATTACHED` 与自检、修复、重试证据，允许进入受限原生回退。若 MCP 已附加，但经过有界上下文批次仍明确无法物化本次精确目标，则记录 `MCP_TARGET_UNAVAILABLE` 后允许同样回退。普通业务错误、一次性超时、可重试校验失败以及任何 `blocked` 状态均不属于回退条件。
- 受限原生回退只允许精确已知路径读取或 `rg`、`apply_patch` 编辑，以及与改动表面直接相关的定向验证；禁止仓库级索引替代品、宽泛递归扫描、修改 `generated/`、跳过文档对齐、跳过 Web/UI 多断点证据或用静态证据冒充真实运行验收。MCP 恢复后立即回到 readiness 与密封编辑流程；回退不扩大部署、生产数据、破坏性操作、提交或推送权限。
- **立即阅读**返回的 `agent_guidance.session_startup_notices`（以及后续 `workflow_contract.v1.session_startup_notices`）：
  1. **文档对齐**：每个功能做完后打开归属模块 `doc/`（README / 需求 / 开发日志 / 专题）对照实现，有差异就改文档或代码；禁止功能交付而文档未跟上。
  2. **交付地址清单**：每个功能开发完成后，在交付汇报中列出本功能涉及的全部**前台**与**后台**可访问地址。主验收 URL **必须是可直接打开的 https Markdown 链接**，格式：

     `[页面名称](https://实例Host:端口/路径)`

     **禁止**：`command:simpleBrowser.api.open` 伪协议（在聊天里常显示为不可点标签）、只写加粗/变色的「打开」文字、无 `[]()` 语法的伪链接、或对整段 URL 做 `encodeURIComponent`。可另附一行反引号 URL 供复制，须与链接目标完全一致。清单同步写入 `doc/开发日志.md`（文档内可用纯文本 URL）。
  3. **响应式**：Web/UI 设计阶段就要纳入平板（≈768）与 PC（≥1024），并兼顾 375；验收收集多断点证据，禁止只做桌面再补丁。
- 后续工具携带 `readiness_id` + `client_session_id`。

### 1. 定位与需求确认

- 对照归属模块 `doc/需求.md`（REQ-ID、范围、验收、待确认项）。
- 用户已确认的需求优先于文档推断；临时决定用 `set_session_directives`，**不自动写入** `需求.md`。
- 用 `resolve_task_context` 取有界证据；禁止凭通用框架经验发明需求或事件名。

### 2. 扩展点选型（写代码前硬关）

在改任何业务代码前，必须完成机制选型并记录证据路径：

| 意图 | 优先机制 | 索引入口 |
|------|----------|----------|
| 通知 / 副作用 | Event / Observer | `Framework/doc/event/README.md` |
| 读数据 | Interface / QueryProvider / `w_query()` | 模块 doc / BinQuery 文档 |
| 写 / 命令 | 归属 Interface / Hook / Queue | 模块 doc |
| UI 控件 | Taglib / Hook | 模块 Taglib 文档 |
| 跨模块直调对方 Service/Model | **禁止** | — |

无现成扩展点时：在 TaskContract 中声明「将新建」并补文档，**禁止静默发明事件名**。

详见 [扩展点选型](../Framework/doc/3-开发/扩展点选型.md)（路径：`app/code/Weline/Framework/doc/3-开发/扩展点选型.md`）。

### 3. 计划拆解

- 模块级：`doc/开发/plan.md`（阶段、范围、完成标准）+ `doc/开发/task.md`（可勾选任务）。
- MCP 写码：`get_edit_bundle` 携带完整 **TaskContract**（goal、requirements、known_paths、known_symbols）。
- 原子任务：单次变更宜 2–4 小时可验收；过大则拆 child_requests。

### 4. 实现

- 一次 `get_edit_bundle` → 一次 `apply_compact_edit`（`ready_for_edit=true` 时）。
- 只改任务授权范围；保留用户无关工作区改动。

### 5. 三维复审

- 架构：模块边界、扩展点是否正确。
- 缺陷：边界条件、错误路径。
- 安全：凭据、ACL、输入校验、跨站边界。

### 6. 分层测试与验收

| 变更表面 | 最低证据 |
|----------|----------|
| 纯函数 / Service 局部 | 聚焦单测 |
| 命令 / API / 持久化 | 真实命令或 API 结果 + 必要单测 |
| 页面 / 交互 / SSE | 真实 WLS + 内置 Browser 操作员路径（**WB-OP**）；**须截图 + 对照模块 `doc/原型设计.md` 视觉清单（WB-VIS）**；多断点 375 / ≈768 / ≥1024 |
| 文档 / 规则 | Diff、链接、渲染检查；**与实现对照无漂移** |

**分章计划**：每章 Done 须 **UT → RT → WB → DL** 四段全 pass 才开下一章。含 Web 的章：**WB = WB-OP + WB-VIS**；截图存 `doc/evidence/ch{N}/`；禁止 curl/单测/纯文字替代 Browser 视觉证据。

未完成对应层级时，只能报告「代码已改，测试未完成」或「WebUI 验收未完成」。

### 前端开发规范（MCP 写死表面 `frontend_development`）

编辑 `*.phtml`、Theme 部件、布局、partial 时，`resolve_task_context` / `get_edit_bundle` 的 `workflow_contract.v1` 会附带 **`frontend_development`（前端开发规范）**表面。这是一套 Theme/前端规范，**不是**名为 `weline-code` 的独立技能；section 身份属性只是其中一条硬约束。

权威总览：`app/code/Weline/Theme/doc/开发/Theme开发总指南.md`。机器可读摘要见 `workflow_contract.v1.frontend_development`（`template_surface_rules` 仅为兼容别名）。

强制要点：

1. 先判定改动层：layout / partial / component / widget；禁止直接改 `generated/`、`view/tpl`。
2. **禁止**在 `w:*` / Taglib **标签属性**里写 `<?=`、`<?php`；动态文案用 `@lang`、Hook，或在 PHP 块赋值后再写到 **HTML 元素**属性（须 `htmlspecialchars`）。
3. **禁止**在会经 `data-wslot` 注入的 **部件模板**里写含 `<?=` 的内联 `<script>`；脚本放 `view/statics/js/widgets/{code}.js`，模板用 `@static(...)` + `defer` + `data-no-extract="true"`。
4. **禁止**在布局 slot 的 `<else/>` 写业务/demo 占位 UI；空 slot + 部件 `default_injections` 负责开箱内容。
5. **硬规则（布局内嵌归属）**：Theme `layouts/` / `partials/` 仅允许归属 `Weline_Theme` 的 `<w:widget>` / `fetch(...Weline_Theme::.../widgets/...)`；其他模块必须空 slot + `default_injections`。改后跑 `php bin/w frontend:check-theme-layout-widgets`。
6. **必须**为前台字面 `<section>` 与 `w:slot wrapper="section"` 配置非空语义 section 身份（属性名 `weline-code`；部件根节点用 `WidgetUiScope`）；改模板后跑 `php bin/w frontend:check-section-code`。
7. 视觉值优先主题 CSS 变量；浏览器业务请求走 `Weline.Api.*`。
8. **响应式**：设计阶段纳入平板（≈768）与 PC（≥1024），兼顾 375；验收收集多断点证据。
9. 专项细则按任务再读：`部件开发指南.md`、`frontend-section-weline-code.md`、`theme-css-variables-only.md`。

### 7. 收口

- **文档对齐（硬门槛）**：打开归属模块 `doc/README.md`、`doc/需求.md`、`doc/开发日志.md` 及本次触及的专题文档，对照刚交付行为；有差异则改文档或回改代码，二者必须一致。
- **交付地址清单（硬门槛）**：在面向用户的交付汇报中列出本功能涉及的全部入口，按表面分组：
  - **前台 / 后台主验收**：每行一条**可直接打开的 https Markdown 链接**，格式 `[名称](https://完整URL)`；链接文字用页面名（如「愿望清单」），**禁止** `command:simpleBrowser.api.open` 与仅写不可点的「打开」变色字。
  - **API / Query**：`w_query` 资源名、路由或 `php bin/w http:request` 可复现示例。
  - **纯逻辑**：对应表面写 `N/A`，并给出 CLI 命令或接口入口。
  - 禁止臆造路由；交付前须探活；探活失败不得交付可点击死链。
- 同步 `doc/开发日志.md`：门禁表、阶段变化、证据路径（含响应式断点证据路径与 URL 清单）。
- 需求变更写入 `需求.md`（需用户确认）。
- commit / push / 部署仅在有明确授权时执行。

## MCP 工具映射

| 阶段 | MCP 工具 |
|------|----------|
| 0 | `prepare_project` |
| 1–2 | `resolve_task_context`、`search_project_knowledge`、`get_indexed_document` |
| 3–4 | `get_edit_bundle`、`apply_compact_edit` |
| 临时决定 | `set_session_directives` |
| 部署计划（只读） | `resolve_deploy_plan` |
| MCP 确认不可用 | 记录 `HOST_MCP_NOT_ATTACHED` / `MCP_TARGET_UNAVAILABLE`，按“0. 引导与 ready”的受限原生回退执行 |

`resolve_task_context` 与 `get_edit_bundle` 返回的 `workflow_contract.v1` 为本流程的**机器可读摘要**；`pinned_fragments` 为固定附带的规范切片。

## 文档索引

快速查找权威文档见 [文档索引](./文档索引.md)。

## 与外部 Vibe Coding 资料的关系

社区 [vibe-coding-cn](https://github.com/tradecatlabs/vibe-coding-cn) 强调：人负责目标与验收，AI 负责执行与证据，机器门禁拦截幻觉。本文将其**收敛为 Weline 仓库的可执行门禁**，不以社区文档替代本仓 `doc/` 权威正文。
