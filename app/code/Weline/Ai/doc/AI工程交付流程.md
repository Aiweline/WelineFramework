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
7 收口             开发日志.md 门禁表 + 交付证据
```

### 0. 引导与 ready

- 运行 `php app/code/Weline/Ai/Mcp/scripts/ensure-project-guidance.php`。
- 调用 `prepare_project`；仅 `status=ready` 且在 `dev` 分支继续。
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
| 页面 / 交互 / SSE | 真实 WLS + 内置 Browser 操作员路径 |
| 文档 / 规则 | Diff、链接、渲染检查 |

未完成对应层级时，只能报告「代码已改，测试未完成」或「WebUI 验收未完成」。

### Theme / phtml 模板硬规则（MCP 写死）

编辑 `*.phtml`、Theme 部件、布局 partial 时，`resolve_task_context` / `get_edit_bundle` 的 `workflow_contract.v1` 会附带 `template_surface_rules`，并强制：

1. **禁止**在 `w:*` / Taglib **标签属性**里写 `<?=`、`<?php`；动态文案用 `@lang`、Hook，或在 PHP 块赋值后再写到 **HTML 元素**属性（须 `htmlspecialchars`）。
2. **禁止**在会经 `data-wslot` 注入的 **部件模板**里写含 `<?=` 的内联 `<script>`；脚本放 `view/statics/js/widgets/{code}.js`，模板用 `@static(...)` + `defer` + `data-no-extract="true"`。
3. **禁止**在布局 slot 的 `<else/>` 写业务/demo 占位 UI；空 slot + 部件 `default_injections` 负责开箱内容。
4. 权威细则：`app/code/Weline/Theme/doc/部件开发指南.md`。

### 7. 收口

- 同步 `doc/开发日志.md`：门禁表、阶段变化、证据路径。
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

`resolve_task_context` 与 `get_edit_bundle` 返回的 `workflow_contract.v1` 为本流程的**机器可读摘要**；`pinned_fragments` 为固定附带的规范切片。

## 文档索引

快速查找权威文档见 [文档索引](./文档索引.md)。

## 与外部 Vibe Coding 资料的关系

社区 [vibe-coding-cn](https://github.com/tradecatlabs/vibe-coding-cn) 强调：人负责目标与验收，AI 负责执行与证据，机器门禁拦截幻觉。本文将其**收敛为 Weline 仓库的可执行门禁**，不以社区文档替代本仓 `doc/` 权威正文。
