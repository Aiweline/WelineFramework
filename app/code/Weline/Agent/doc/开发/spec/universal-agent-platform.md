---
status: draft
work_kind: feature
feature_slug: universal-agent-platform
module: Weline_Agent
updated: 2026-11-23
---

# 通用智能体平台（角色驱动会话 + 可视化编排 + 插件 + 项目团队）

> 本文是 Weline_Agent 的耐久功能规格，目标是「可直接分派施工」。所有路径、类名、表名均对照 2026-11-23 工作树核实；标注「待施工期确认」处以实现时复核为准。

## 1. 目标 / 非目标

### 目标

- 提供类 DeepSeek/OpenAI 的 Web 聊天前端：会话列表、流式回复、角色/模型/项目切换，前后台双入口。
- 会话与工作流中**以角色为一等公民**：用户只选角色；智能体由角色的 leader 自动绑定/创建，用户不直接管理智能体。
- 提供**可视化角色节点编排**（类 coze.cn 简化版）：新建节点 = 选角色 + 可选系统提示词覆盖（可「从角色导入」）；不做 coze 的输入/输出 schema 配置。
- 提供**插件机制**：插件安装到模块并与角色绑定，新智能体继承角色插件能力。
- 提供**项目（Project）边界**：同项目内智能体互 @ 携带项目标记，禁止跨项目越界。
- 服务端持久化全部会话、消息、流程、运行、任务。

### 非目标（本期不做）

- 不做 coze 式输入/输出参数 schema、插件市场、节点市场。
- 不做流程条件分支/循环（v1 仅拓扑序顺序执行；分支列入后续扩展）。
- 不重写 Weline_Ai 的 Provider/模型目录/任务型 Agent 执行器。
- 不在本期合并 Weline_Ops 的队列/审批/运营台实现（只声明收敛路径）。
- 不引入 Vue/React 等前端框架或外部画布库（自研轻量 SVG/DOM 画布）。

## 2. 术语表

| 术语 | 定义 | 载体 |
|------|------|------|
| Role（角色） | 提示词 + 技能集 + 权限 + 默认模型 的能力画像，用户-facing 选择单元 | `weline_agent_role` |
| Agent（智能体） | 角色派生的执行者；用户不直接选，leader 自动绑定 | `role.agent_code` / `weline_agent` 目录引用 |
| Plugin（插件） | 可安装能力包：skills + prompt 片段 + 可选任务智能体引用 + 权限声明 | `weline_agent_plugin` + `extends/module/Weline_Agent/Plugin` |
| Project（项目） | 团队边界容器；会话/流程/任务必须归属其一 | `weline_agent_project` |
| Session/Turn | 会话与单回合；回合记录本回合使用的角色/智能体/模型 | `weline_agent_chat_session` / `weline_agent_chat_message` |
| Flow/Node | 可视化编排的流程与角色节点 | `weline_agent_flow`（graph_json 内嵌节点） |
| FlowRun/NodeRun | 一次流程运行及逐节点执行记录 | `weline_agent_flow_run` / `weline_agent_flow_node_run` |
| Task | 智能体间 @派单 / 审批任务 | `weline_agent_task` |

## 3. 现状盘点与架构隐患

### 3.1 已有能力（已验证）

| 能力 | 位置 |
|------|------|
| 模型/供应商/流式 | `app/code/Weline/Ai/Service/Provider/*`、`Service/AiService.php`（`generateStream`、`generateStructured`） |
| 任务型智能体目录 | `Weline_Ai` `AgentInterface` + `AgentScanner`（扫 `extends/module/Weline_Ai/Agent`）+ `ai_agent`/`ai_agent_tool` + `AgentCatalogService`/`AgentGovernance`；执行入口 `AiService::executeAgent`（`AiService.php:2469`） |
| 会话运行时 | `Weline_Agent`：`AgentRole`/`AgentSkill`/`AgentChatSession`/`AgentChatMessage`/`AgentToolCall`/`AgentMemoryNode`/`AgentSchedule`；`AgentEngine` 工具循环（`Service/AgentEngine.php`，上限 5 轮）；`ChatSessionManager`、`ContextBuilder`、`MemoryService`、`SkillPackageManager`、`PermissionValidator` |
| 后台聊天控制台 | `Controller/Backend/Chat.php` + `view/templates/backend/Chat/index.phtml`（左选角色、BinQuery `sendMessage`/`getChatHistory`） |
| BinQuery Provider | `extends/module/Weline_Framework/Query/AgentQueryProvider.php`（provider=`agent`，已含角色/技能/会话/调度读写） |
| 流式任务基建 | Framework Resumable Task：按模块 `etc/resumable_tasks.php` 声明 handler；参考 `app/code/Weline/Ai/etc/resumable_tasks.php` + `Service/Resumable/ChatGenerationTaskHandler.php`（事件 `chunk/completed/failed/cancelled`、checkpoint、effect ledger） |
| 前台轻量聊天 | `Weline_Ai` `Controller/Frontend/Chat.php` + `ai-chat.js`（单轮、Resumable `ai.chat_generation`、guest 可用） |
| 团队/运营底座 | `Weline_Ops`：`OpsSession/OpsTask/OpsPersona/OpsRunLog`、Lane、`OpsTaskDispatchQueue` + `IdempotentQueueAdmission` + CAS claim + `require_approval` 闸门（`dev/team/ops-async-agent-dispatch/contracts.md`） |

### 3.2 架构隐患（本设计必须正面处理）

1. **两套智能体运行时并存**：`Weline_Ai` `AgentInterface`（无状态任务型）与 `Weline_Agent` Role+Skill（有状态会话型）各自实现 ReAct 循环（`WebsiteBuilderAgent` vs `AgentEngine`）。本设计不强行合并，但规定：**会话/编排一律走 AgentRuntimeService**；任务型 Agent 仅作为「高级执行器」被委托。
2. **`AiService` 上帝服务**（2600+ 行）：`executeAgent` 内联模型选择/账户注入/流包装。本设计通过 `AgentRuntimeService` 收口调用，不再新增直连 `AiService` 的旁路。
3. **`AgentChatSession.role_id` 单绑定**：与「会话中途切换角色/智能体」冲突。必须改数据模型（见 §5），禁止用 metadata 变通。
4. **`AgentEngine::executeStream` 不真正流式**：先完整执行再 yield。P0 需把工具循环改为可逐事件回调（内部支持 streamCallback），由 Resumable Task 承载真实流式。
5. **无节点画布基建**：仓内无 Vue SPA、无画布库（`Weline_Vue` 仅 `{{ }}` taglib）。画布自研，工作量集中在 P2。
6. **历史消息 tool_calls 配对**：切换角色后旧 `tool` 消息可能与新模型的 tool_call 结构不兼容。ContextBuilder 需做「跨角色历史净化」（见 §6.4）。

## 4. 总体架构

```
┌──────────────────────────── Weline_Agent（本平台属主）────────────────────────────┐
│                                                                                    │
│  ┌─ 前端入口 ──────────────┐   ┌─ 后台入口 ──────────────┐                       │
│  │ Frontend/Chat (全屏页)   │   │ Backend/Chat 控制台     │                       │
│  │ Frontend/Flow (运行/查看)│   │ Backend/Flow (画布编辑) │                       │
│  └──────────┬──────────────┘   └──────────┬─────────────┘                       │
│             │  Weline.Api.* / Resumable Task Stream                              │
│  ┌──────────▼──────────────────────────────▼─────────────┐                     │
│  │ AgentRuntimeService（统一执行入口，§6.1）              │                     │
│  │  turn 解析 {role_id, agent_code?, model_code?,        │                     │
│  │           project_id?, prompt_override?}              │                     │
│  └───────┬──────────────────────┬───────────────────────┘                     │
│          │ 会话引擎              │ 高级执行器委托                                    │
│  ┌───────▼────────┐   ┌─────────▼──────────┐   ┌───────────────────────┐      │
│  │ AgentEngine     │   │ AiRuntimeInterface │   │ FlowRunnerService      │      │
│  │ (Role+Skill     │   │ ::executeAgent     │   │ (graph_json 拓扑执行)   │      │
│  │  工具循环,改造后 │   │ (Weline_Ai 任务型   │   │                        │      │
│  │  真流式)        │   │  Agent)            │   │                        │      │
│  └───────┬────────┘   └─────────┬──────────┘   └───────────┬───────────┘      │
│          └──────────┬───────────┴──────────────────────────┘                  │
│                     ▼                                                          │
│  ┌─ 持久化 ──────────────────────────────────────────────────────────────┐    │
│  │ chat_session/message · project · flow/flow_run/node_run · task · plugin│    │
│  └──────────────────────────────────────────────────────────────────────┘    │
│  ┌─ 扩展面 ──────────────────────────────────────────────────────────────┐    │
│  │ Plugin 扩展点（extends/module/Weline_Agent/Plugin）→ 角色绑定           │    │
│  │ Skill 扩展点（已有）· Weline_Ai Agent 扩展点（已有，任务型智能体）        │    │
│  └──────────────────────────────────────────────────────────────────────┘    │
└──────────────────────────────────────────────────────────────────────────────┘
        │ uses                          ▲ contributes via extends
        ▼                               │
   Weline_Ai（模型/供应商/任务型Agent目录，依赖方向 Agent→Ai 单向）
        ▲
        │ 业务模块（Websites/Ops/Catalog…）以 Plugin/Skill/Agent 扩展点贡献能力
```

**边界声明**：

- `Weline_Ai` 不依赖 `Weline_Agent`；本平台所有会话/角色/流程能力在 Agent 侧。
- `Weline_Ops` 是「网站运营」特化：远期可把 `OpsSession.website_id` 映射为 `Project.website_id` 的一个项目类型；本期不合并其队列/审批实现。
- 业务模块贡献能力只有三条路：`Weline_Agent` Plugin（能力包）、`Weline_Agent` Skill（单技能）、`Weline_Ai` Agent（任务型智能体）。禁止业务模块直连 `AgentEngine` 内部。

## 5. 数据模型

### 5.1 新表

#### `weline_agent_plugin`（插件登记）

| 字段 | 类型 | 说明 |
|------|------|------|
| plugin_id | int PK | |
| code | varchar(64) unique | 插件码，`^[a-z][a-z0-9_]*$` |
| name | varchar(191) | |
| version | varchar(32) | |
| module | varchar(64) | 提供模块（`Weline_Ai` 内置为 `Weline_Agent`） |
| class_name | varchar(255) | `AgentPluginInterface` 实现类 |
| manifest_json | mediumtext | 扫描产物：skills[]/prompt_fragments[]/agents[]/permissions[]/ui_panels[] |
| status | varchar(16) | `installed`/`enabled`/`disabled`/`missing` |
| is_builtin | tinyint | 内置插件标记 |
| installed_time / updated_time | int | |

#### `weline_agent_project`（项目/团队边界）

| 字段 | 类型 | 说明 |
|------|------|------|
| project_id | int PK | |
| project_key | varchar(64) unique | 对外标记（uuid），注入 LLM 上下文与 @消息 |
| name | varchar(191) | |
| website_id | int null | 可空；关联 `weline_website`（Ops 收敛路径预留） |
| owner_type | varchar(16) | `admin`/`customer`/`system` |
| owner_id | varchar(64) | 后台 admin user id 或前台 context_id |
| status | varchar(16) | `active`/`archived` |
| config_json | mediumtext | 扩展配置（预算、默认角色、审批开关等） |
| created_time / updated_time | int | |

#### `weline_agent_flow`（可视化流程定义）

| 字段 | 类型 | 说明 |
|------|------|------|
| flow_id | int PK | |
| project_id | int | 必填，流程必须归属项目 |
| name / description | varchar/text | |
| graph_json | mediumtext | 见 §8.3 schema |
| status | varchar(16) | `draft`/`active`/`disabled` |
| version | int | 每次保存 +1，run 记录 flow_version |
| created_by / created_time / updated_time | | |

#### `weline_agent_flow_run`（流程运行）

| 字段 | 类型 | 说明 |
|------|------|------|
| run_id | int PK | |
| flow_id / flow_version | int | |
| project_id | int | 冗余便于检索 |
| task_id | varchar(64) null | 关联 Resumable Task（`agent.flow_run`） |
| status | varchar(16) | `queued`/`running`/`awaiting_approval`/`done`/`failed`/`cancelled` |
| input_text | mediumtext | 用户原始需求 |
| result_json | mediumtext | 汇总结果 + 各节点输出摘要 |
| started_time / finished_time | int null | |

#### `weline_agent_flow_node_run`（节点执行记录）

| 字段 | 类型 | 说明 |
|------|------|------|
| node_run_id | int PK | |
| run_id / flow_id / node_key | | |
| role_id / agent_code / model_code | | 执行快照 |
| prompt_override | mediumtext null | 节点提示词覆盖 |
| input_summary | mediumtext | 上游输出截断拼接（≤8KB） |
| output | mediumtext | 节点最终回复 |
| status | varchar(16) | `pending`/`running`/`done`/`failed`/`skipped` |
| tool_calls_json | mediumtext | 该节点工具调用摘要 |
| started_time / finished_time / duration_ms | | |

#### `weline_agent_task`（智能体间派单/审批任务）

| 字段 | 类型 | 说明 |
|------|------|------|
| task_id | int PK | |
| project_id | int | **必填**，跨项目派单在服务层拒绝 |
| session_id | int null | 来源会话（可空） |
| flow_run_id | int null | 来源流程运行（可空） |
| assignee_role_id / assignee_agent_code | | 至少其一 |
| prompt | mediumtext | |
| require_approval | tinyint | 1 时完成前进入 `awaiting_approval` |
| status | varchar(16) | `queued`/`running`/`awaiting_approval`/`done`/`failed`/`cancelled` |
| result_json / error | | |
| idempotency_key | varchar(128) unique | `agent_task:{project_id}:{uuid}` |
| created_by_type/id、created_time、started_time、finished_time | | |

### 5.2 改表

#### `weline_agent_role` 增列

| 字段 | 说明 |
|------|------|
| `agent_code` varchar(64) null | leader 智能体显式绑定；空则按约定解析（§6.2） |
| `plugins` mediumtext null | JSON 数组 plugin_id/code 列表；有效技能 = `skills ∪ Σ enabled plugins.skills` |

`role_id` 语义不变；`is_default` 现有语义保留，`general` 角色为默认兜底。

#### `weline_agent_chat_session` 增列

| 字段 | 说明 |
|------|------|
| `project_id` int null | 会话归属项目；空 = 个人会话（不参与团队派单） |
| `default_model_code` varchar(128) null | 会话级默认模型，回合可覆盖 |
| `default_role_id` int null | 新增；原 `role_id` 保留为「创建时角色」兼容字段，读取优先 `default_role_id ?? role_id` |

`channel` 现有枚举沿用；前台会话 `channel='web'` + `context_id` 绑定浏览器会话；后台会话 `context_id` 绑定 admin 身份（施工期确认 backend context 口径，见 §10）。

#### `weline_agent_chat_message` 增列

| 字段 | 说明 |
|------|------|
| `role_id` int null | 本条助手消息实际使用的角色（user/tool 消息可空） |
| `agent_code` varchar(64) null | 实际执行智能体 |
| `model_code` varchar(128) null | 实际模型 |
| `project_id` int null | 冗余项目标记 |
| `meta_json` mediumtext null | 扩展：flow_run_id、task_id、node_key、prompt_override_used 等 |

用户消息也写 `project_id`；`role` 沿用 `user/assistant/system/tool`。

### 5.3 迁移方式

- 新表与加列均走模块 `Setup/Upgrade.php` + `#[Col]/#[Index]` 注解 + `php bin/w setup:upgrade`（禁止手改 generated）。
- `weline_agent_role.plugins`、message `meta_json` 等 JSON 字段沿用现有 `setData` JSON 编码惯例（参照 `AgentRole::getSkills()`）。
- 存量 `weline_agent_chat_session.role_id` 不回填 `default_role_id`；读取层做 `??` 兼容，避免数据迁移。

## 6. 运行时设计

### 6.1 `AgentRuntimeService`（新，`app/code/Weline/Agent/Service/AgentRuntimeService.php`）

统一执行入口，所有 turn/flow 节点/@任务最终都经它。签名草案：

```php
final class AgentRuntimeService
{
    /**
     * @param array{role_id?:int, role_code?:string, agent_code?:string,
     *   model_code?:string, project_id?:int, prompt_override?:string,
     *   session?:AgentChatSession, upstream_output?:string} $turn
     * @return \Generator 逐事件产出（供 Resumable Task 转 emit）
     */
    public function runTurn(array $turn, string $prompt, ?callable $onEvent = null): AgentResult;

    public function resolveExecutor(array $turn): ExecutorResolution; // {kind: 'engine'|'task_agent', role, agent_code, model_code}
}
```

**决策表**：

| 条件 | 执行器 | 说明 |
|------|--------|------|
| `agent_code` 命中 Weline_Ai 任务型 Agent 目录且显式指定 | `AiRuntimeInterface::executeAgent` | 高级执行器；params 注入 `role_id/project_id/session_id` 供 Agent `getSystemPrompt($context)` 使用 |
| 其余（默认） | `AgentEngine`（改造后真流式） | 角色 + 有效技能集（含插件）工具循环 |

**事件协议**（Resumable Task `emit` 与 SSE/流通道共用）：

| event | payload | 说明 |
|-------|---------|------|
| `turn_start` | `{role_id, role_code, agent_code, model_code, project_key}` | 回合开始，前端切换头部显示 |
| `thinking` | `{content, streaming:true}` | 模型思考链（provider `on_reasoning`） |
| `chunk` | `{content, streaming:true}` | 正文增量 |
| `tool_call` | `{id, name, arguments}` | 工具调用 |
| `tool_result` | `{id, name, result_preview}` | 工具结果（截断 800 字符） |
| `agent_info` | `{agent_code, resolved_by}` | 智能体解析结果（显式/约定/降级） |
| `turn_done` | `{message_id, usage?}` | 回合落库完成 |
| `error` / `cancelled` | `{reason}` | 终止 |

### 6.2 角色 → leader 智能体解析（§决策 2 落地）

`AgentRuntimeService::resolveLeaderAgent(AgentRole $role): string` 按序：

1. `role.agent_code` 非空且 `AiRuntimeInterface`/AgentScanner 目录存在 → 用之。
2. 约定码 `role_{role.code}` 在 ai_agent 目录存在 → 用之。
3. 否则降级 `'chat'`（Weline_Ai 内置通用对话 Agent，P0 需新建 `app/code/Weline/Ai/Agent/ChatAgent.php`，见 §11-P0）；解析结果写入 `agent_info.resolved_by = explicit|convention|fallback`，后台角色页可见。

**用户侧永远只见「角色」**；agent_code 仅在 turn 记录、`agent_info` 事件与后台治理页出现。

### 6.3 会话/回合语义

- 一个 Session 可混合多个角色：每条 user 消息携带本回合 `{role_id?, agent_code?, model_code?}`；缺省回落 `session.default_role_id ?? session.role_id ?? general角色`、`session.default_model_code ?? 角色.model_id ?? 全局默认模型`。
- 切换角色/模型/智能体**不清历史**；新回合的 system prompt 按新角色重建（`ContextBuilder::build($session,$role,$turnOpts)` 扩展入参）。
- `prompt_override`（节点级）优先级：`turn.prompt_override` > 插件 prompt_fragments 拼接 > `role.system_prompt`；节点覆盖仅作用于该次执行，不回写角色。
- 上下文注入：`ContextBuilder` 在 system 段固定输出 `[PROJECT:{project_key} {name}]`（有 project 时）+ `[ROLE:{code} {name}]` + 技能清单摘要。

### 6.4 跨角色历史净化（ContextBuilder 改造）

- 取历史时按 `project_id` 过滤（session 本身已带 project_id，消息冗余字段用于混合场景校验）。
- `role=tool` 消息仅当相邻 assistant `tool_calls` 仍在窗口内才保留；窗口外的孤儿 tool 消息降级为 `role=system` 摘要文本（标记 `[tool:{name}] {preview}`），避免 provider 报 tool_call_id 不配对。
- 历史上限沿用现有 `getMessageHistory($limit)`，P0 调整为「token 预算」口径（先按条数 50，施工期按实测调）。

### 6.5 流式承载：Resumable Task

- 新增 `app/code/Weline/Agent/etc/resumable_tasks.php`：
  - `agent.chat_turn` → `Service/Resumable/AgentChatTurnTaskHandler`，`areas: ['frontend','backend']`。
  - `agent.flow_run` → `Service/Resumable/AgentFlowRunTaskHandler`，`areas: ['backend']`（v1 流程运行入口放后台；前端流程页 P2 只读进度，运行仍走后台发起——施工期可放宽）。
- Handler 职责（仿 `ChatGenerationTaskHandler`）：owner 校验（frontend 区允许 `frontend:`/`session:` principal，backend 区要求 `backend:`）、input 校验（session_id/turn 参数上限）、`reserveEffect` 幂等、checkpoint、`emit` 上述事件、终态 `TaskResult`。
- 前端/后台 JS 复用 `Weline.Api.resource('runtime_task').start(...)` + `createStream(...)` 模式（参照 `ai-chat.js` 的 `attachChatTask`）。

### 6.6 AgentEngine 流式化改造（P0 必做）

`AgentEngine::execute` 内部循环目前无事件出口。改造：

- `requestAgentResponse`/`submitToolResults` 支持 `streamCallback`：provider 层 `generateStructured` 若底层支持 `on_content/on_reasoning` 则透传（`AiService` 已有 stream 通路，施工期确认 `generateStructured` 是否暴露回调；不支持则先按「整段 chunk」emit，标注降级）。
- 工具循环每步 emit `tool_call`/`tool_result`。
- `executeStream` 改为真正的 generator + 回调双通道，保持对既有调用方（`AgentAdminService::sendMessage`、Schedule）兼容：不传 callback 时行为不变。

## 7. 插件机制

### 7.1 接口与扩展点

- 新接口 `app/code/Weline/Agent/Api/AgentPluginInterface.php`（或 `Interface/`，与 `SkillInterface` 同目录风格，施工期对齐现有 `Interface/` 约定）：

```php
interface AgentPluginInterface
{
    public function getCode(): string;
    public function getName(): string;
    public function getVersion(): string;
    /** @return array{skills?:list<string>, prompt_fragments?:list<string>, agents?:list<string>, permissions?:list<string>, ui_panels?:list<string>} */
    public function getManifest(): array;
}
```

- `extends.php` 增加 `'Plugin'` 扩展点：`path=extends/module/Weline_Agent/Plugin`，`interface=Weline\Agent\Interface\AgentPluginInterface`（命名以施工期与既有 `Interface/` 目录对齐为准），`multiple=true`。
- `PluginScanner`（`Service/PluginScanner.php`）：扫 `Weline_Agent/Plugin/*Plugin.php`（内置）+ 各模块 `extends/module/Weline_Agent/Plugin/`；实例化校验接口；upsert `weline_agent_plugin`（manifest_json 落库）；类消失 → `status=missing`（仿 `AgentScanner::markMissingAgents`）。
- 触发：`Setup/Upgrade.php` 后钩子 + `agent:plugin:scan` CLI（仿 `ai:agent:scan`，施工期确认该命令实际名称）+ 后台「重新扫描」按钮。

### 7.2 安装 / 绑定 / 生命周期

- **安装** = 扫描登记（`installed`→`enabled`）；插件类随模块代码存在，无独立安装向导（v1）。后台 `agent/backend/plugin` 页提供：列表、启停、manifest 查看、「绑定角色」多选写入 `role.plugins`。
- **角色绑定**：角色表单 `plugins` 多选；保存时校验 plugin status=enabled。**有效能力解析**（`SkillPackageManager` 扩展或新 `EffectiveCapabilityResolver`）：

```
effective_skills(role) = role.skills ∪ ⋃_{p ∈ role.plugins, p.status=enabled} p.manifest.skills
```

- **继承语义**：新建角色不自动带插件（显式绑定）；但「角色新建智能体/会话」时 effective_skills 自动生效——即用户感知的「新智能体继承角色插件」。插件 `agents[]` 声明的 Weline_Ai 任务型 Agent 由各自模块经 `Weline_Ai` Agent 扩展点注册，插件只做引用与启停联动（插件 disabled → 其声明的 agent 在本角色上下文不可选）。
- **卸载** = disable（保留行）；模块卸载导致 `missing`；角色上失效插件自动从 effective_skills 剔除（运行时过滤，不改 role.plugins 数据）。

### 7.3 示例插件 `framework_knowledge`（P3 交付）

内置插件 `app/code/Weline/Agent/Plugin/FrameworkKnowledgePlugin.php` + 对应 `SkillInterface` 实现：

| skill_code | 能力 | 数据源 |
|------------|------|--------|
| `search_module_docs` | 按关键词搜 `app/code/*/doc/**/*.md` | 文件系统 + 简单评分（不引入向量库） |
| `get_module_index` | 读模块 `doc/AI-INDEX.md`/`README.md`/`需求.md` 摘要 | 文件系统 |
| `query_code_graph` | 查 `var/code3d/graph.json`（class/method/callers） | code3d 快照（不存在时返回提示先跑 `code3d:analyze`） |
| `read_doc_file` | 按路径读 doc 文件（限定 `*/doc/` 前缀防越权） | 文件系统 |

用途：支撑「查看框架架构 / 重新设计 / 产出施工文档」场景。**不依赖 MCP**（运行时无 MCP）；后续可加 embedding 检索另立项。

`site_report` 类技能不内置实现，仅在文档中声明插件接口可承载（各业务模块按需贡献）。

## 8. 可视化编排（类 coze 简化版）

### 8.1 页面与入口

- 后台：`agent/backend/flow` 列表 + `agent/backend/flow/edit?id=` 画布编辑（新 Controller `Backend/Flow.php` + `view/templates/backend/Flow/{listing,edit}.phtml` + `view/statics/js/agent-flow-editor.js`，模块名 `agentFlowEditor`）。
- 前端：`agent/frontend/flow`（或并入 chat 页「流程」页签）只读画布 + 运行进度（P2 后置，v1 先后台）。
- 菜单：`etc/backend/menu.xml` 在 Agent 分组下加「流程编排」（ACL `Weline_Agent::flow_*`）。

### 8.2 画布交互规格（v1）

- **节点**：矩形卡片 = 角色名 + 角色 code +（有覆盖时）提示词角标；新建节点按钮 / 双击画布空白创建。
- **连线**：从节点右锚点拖到另一节点左锚点；点击连线删除；不做分支条件 UI（v1 单后继语义：一个节点可有多个出边 = 并行分支仅拓扑序无实际并行，施工期如实现并行需节点 run 独立并发——v1 统一串行执行，文档明示）。
- **节点编辑面板**（点节点弹出/侧栏）：
  - 角色选择器（`agent` QueryProvider `getActiveRoles`）。
  - 系统提示词 textarea：placeholder 显示角色默认提示词；「从角色导入」按钮回填 `role.system_prompt`；留空 = 用角色默认；填写 = 覆盖（仅本节点本次及后续运行，不改角色）。
  - 高级折叠区（v1 可只读展示）：解析出的 leader agent_code、角色模型、有效技能数。
- **流程级**：名称、所属项目选择器（必选）、保存（version+1）、运行按钮（弹输入需求 → 启动 `agent.flow_run` task）、运行历史列表（run 状态/耗时/入口）。
- **运行中**：画布节点按 `node_run.status` 着色（pending/running/done/failed），当前节点高亮；事件经 Resumable stream 驱动。
- **实现**：纯 SVG（节点/边）+ HTML 覆盖层（编辑面板）+ 原生 JS；坐标存 `graph_json.nodes[].position`；不引外部库；样式用主题 token（`--weline-*`），画布底色/网格用 `--weline-page-canvas-*` 约定。

### 8.3 `graph_json` schema

```json
{
  "nodes": [
    {
      "node_key": "n1",
      "role_id": 3,
      "role_code": "seo_specialist",
      "agent_code": null,
      "prompt_override": null,
      "position": {"x": 120, "y": 80},
      "note": "可选备注"
    }
  ],
  "edges": [
    {"from_node_key": "n1", "to_node_key": "n2", "label": null}
  ],
  "entry_node_key": "n1"
}
```

校验规则（服务端 `FlowGraphValidator`，P2）：node_key 唯一、role_id 存在且 enabled、边端点存在、无环（拓扑可排）、entry_node_key 为源点或自动取入度0节点、节点数 ≤ 20（v1 上限）。

### 8.4 执行语义（v1）

- `AgentFlowRunTaskHandler`：
  1. 校验 flow.status=active、project 一致、输入文本非空。
  2. `FlowGraphValidator` 拓扑排序 → 串行逐节点执行（v1 不并发）。
  3. 每节点构造 turn：`{role_id, agent_code?, model_code?, project_id, prompt_override}`；prompt = `【流程节点 {i}/{n}：{role_name}】\n{上游汇总}\n\n【原始需求】\n{input_text}\n\n【你的任务】\n{prompt_override || '基于以上上下文完成本环节工作并输出可交接结论'}`。上游汇总 = 上一节点 output 截断 8KB；首节点无上游。
  4. 每节点经 `AgentRuntimeService::runTurn`，emit `node_start/node_chunk/node_tool/node_done/node_error`（在 turn 事件上加 `node_key` 包装）。
  5. 全部节点完成 → `flow_run.result_json` 汇总（各节点 output 拼接 + 元信息），写 `flow_node_run` 逐条记录；任一节点失败 → run=failed（v1 不重试不跳过；重试策略后续）。
- 节点内可用角色技能（含插件）；节点执行产生的 `AgentToolCall` 记录 `session_id` 置空、`meta` 记 `flow_run_id+node_key`（施工期确认 tool_call 表是否加列或复用 meta）。

## 9. 团队与 @派单

### 9.1 项目标记注入

- `ContextBuilder`：凡 turn/project 有 `project_id`，system 段注入 `[PROJECT:{project_key} {name}]`；`AgentRuntimeService` 同时把 `project_key` 写入 `meta_json` 与 `agent_info` 事件。
- **强校验（服务层，非提示词）**：`AgentTaskDispatchService::dispatch` 要求 `task.project_id` 与来源 session/flow_run 的 project_id 一致；@解析出的目标角色/智能体必须属于同一 project（角色本身无 project 归属——项目内「可用角色」= 全部 enabled 角色，v1；角色级项目白名单后续扩展）。跨项目 @ 在 UI 不给候选、服务端拒绝。

### 9.2 @路由

- 前端输入框 `@` 触发补全：候选 = enabled 角色（`@角色名`）与显式 agent（`@agent:{code}`，高级）。
- 消息落库前 `AgentMentionParser` 解析 mentions[]；含 mention 的消息发送后：
  - 同步回合仍由当前角色回复（v1 不抢答）；
  - 每个 mention 生成 `weline_agent_task`（`session_id`=当前会话，`prompt`=「@我的上下文：{原消息}；{mention 之后的指令}」，`assignee_role_id` 或 `assignee_agent_code`）。
  - task 入队 `AgentTaskDispatchQueue`（`app/code/Weline/Agent/Queue/`，类比 `Ops/Queue/OpsTaskDispatchQueue.php`）：`class=AgentTaskDispatchQueue, module=Weline_Agent, idempotency_key=agent_task:{task_id}, biz_key 同, idempotency_scope=agent_task_dispatch`；消费端 CAS `queued→running`。
- Worker 消费 → `AgentRuntimeService::runTurn`（`session` 传 null，独立上下文；prompt 自带项目标记与来源摘要）→ 写 `result_json`；`require_approval=1` 时完成态先 `awaiting_approval`，后台任务管理页批准后 `done`（审批 UI 复用 Ops Desk 版式习惯，P4）。
- 结果回流：task done 后向来源 session 追加一条 `role=system` 消息 `[TASK:{task_id} @role:{code} 已完成] {result_preview}`，前端下次拉历史可见；不强制实时推送（v1）。

### 9.3 与 Weline_Ops 的收敛路径（声明，非本期实施）

- `OpsSession.website_id` ≈ `Project.website_id` 的运营特化；`OpsTask` ≈ `weline_agent_task` 的站点运营类型。
- 远期：Ops 任务可改写为「project=该网站运营项目 + assignee=ops 角色」的 agent_task；Ops Desk 展示层不变。
- 本期红线：不改 `Weline_Ops` 任何文件；两模块通过 `project.website_id` 字段约定对齐，冲突时 Ops 为准（另一会话在施工）。

## 10. 前端规格

### 10.1 聊天页（前台 `agent/frontend/chat`）

- 布局：左侧栏（项目分组的会话列表 + 新建会话/项目入口）｜主区（消息流 + composer）｜顶栏（角色选择器、模型选择器、项目选择器、设置折叠：agent 高级切换/清空）。
- 技术：`Controller/Frontend/Chat.php`（`layoutType='blank'`，参照 `Weline_Ai` Chat）+ `view/templates/frontend/Chat/index.phtml` + `view/statics/js/agent-chat.js`（`data-weline-load="agentChat"`）。样式优先 `w-*` 组件类与 `--weline-*` token，页面级样式走模块 css 文件（不写内联大段 CSS；参照现有 Chat 模板把样式拆到 `view/statics/css/agent-chat.css`）。
- 数据源：
  - 角色/会话/项目：BinQuery `agent` provider 新增 read ops（`getMyProjects/getProjectSessions/getSessionMessages` 等，见 §10.3）——**前台写操作不得走 backend ACL**，QueryProvider 需区分 owner（frontend context_id / backend admin）。
  - 发消息：不再 request/response；`runtime_task.start({type_code:'agent.chat_turn', input:{session_id?, role_id?, model_code?, project_id?, message, request_id}})` + `createStream`（事件见 §6.1）。
- 会话切换角色：顶栏角色选择器改的是「下一回合」角色，历史消息各自保留当时角色徽标（message.role_id 渲染）。
- @补全：composer 内 `@` 弹层（角色列表），选中插入 `@code `。
- guest/未登录：v1 要求登录客户（`context_id` 由服务端会话生成）；guest 轻量聊天仍由 `Weline_Ai /ai/frontend/chat` 承担，本页不做 guest。

### 10.2 后台控制台升级（`agent/backend/chat`）

- 保留 BinQuery 控制台，补：模型选择器（`ai` provider 模型列表读操作）、项目选择器（`getProjects`）、流程运行入口链接。
- `sendMessage` op 扩展参数：`role_id/model_code/project_id/agent_code`；`AgentAdminService::sendMessage` 内部改走 `AgentRuntimeService`（兼容旧 `role_code+message` 入参）。
- 会话管理页（`Backend/Session`）列表增加 project/role 列与筛选。

### 10.3 QueryProvider / REST 清单（施工期落到 `AgentQueryProvider` 或新 `AgentPlatformQueryProvider`）

读：
- `getActiveRoles`（已有）、`getDefaultRole`（已有）、`getRoleById`（已有）
- `listTextModels` → 代理 `w_query('ai', ...)` 或 AiModel 直查（施工期定归属，建议 Ai 侧已存在模型列表 op 则复用）
- `getMyProjects` / `getProject` / `getProjectSessions` / `getSessionMessages(session_id, before_id?, limit)`
- `getFlows` / `getFlow` / `getFlowRuns` / `getFlowRunDetail`
- `getPlugins` / `getPlugin`（后台）
- `getTasks` / `getTask`（后台+项目内）

写（backend ACL；frontend 写仅限本人会话消息）：
- `createProject` / `archiveProject` / `attachSessionToProject`
- `saveFlow` / `deleteFlow` / `setFlowStatus` / `runFlow(flow_id, input_text)`（启动 task，返回 handle）
- `savePluginBinding`（role.plugins）、`setPluginStatus`、`rescanPlugins`
- `approveTask` / `cancelTask`
- `sendChatTurn` 可选：前端直接走 runtime_task，不必再走 BinQuery 写（沿用 ai-chat.js 模式）

ACL 资源：`Weline_Agent::project_*`、`flow_*`、`plugin_*`、`task_*`；聊天沿用 `console_index`（后台）与新增 `Weline_Agent::frontend_chat`（前台）。

### 10.4 权限域

| 域 | owner | session.context_id | 可见范围 |
|----|-------|--------------------|----------|
| backend | admin user | `backend:{user_id}`（施工期与 ResumableTaskOwnerResolver 对齐） | 该 admin 的会话；flow/task 全部（管理员） |
| frontend | customer | `frontend:{session_id}` 或 customer id | 仅本人会话与本人 project |

Resumable Task owner 校验沿用 `ChatGenerationTaskHandler::isChatOwner` 模式扩展到 `agent.chat_turn`（frontend+backend 双 area）。

## 11. 分期施工计划

### P0 地基（数据 + 运行时 + 流式）

文件：
- `Model/`：新增 `AgentPlugin.php`、`AgentProject.php`、`AgentFlow.php`、`AgentFlowRun.php`、`AgentFlowNodeRun.php`、`AgentTask.php`；改 `AgentRole.php`（+agent_code,+plugins）、`AgentChatSession.php`（+project_id,+default_model_code,+default_role_id）、`AgentChatMessage.php`（+role_id,+agent_code,+model_code,+project_id,+meta_json）
- `Setup/Upgrade.php`：加列/建表；`Setup/Install.php`：播种 `general` 角色 + `framework_knowledge` 插件占位（P3 实技能）
- `Service/AgentRuntimeService.php`、`Service/EffectiveCapabilityResolver.php`、`Service/ProjectContextService.php`
- `Service/AgentEngine.php`：流式化改造（§6.6）；`Service/ContextBuilder.php`：项目/角色标记注入 + 历史净化（§6.4）
- `Service/Resumable/AgentChatTurnTaskHandler.php` + `etc/resumable_tasks.php`
- `app/code/Weline/Ai/Agent/ChatAgent.php`：通用对话 Agent（fallback leader；简单 system prompt + 可选工具透传）
- `extends/module/Weline_Framework/Query/AgentQueryProvider.php`：读 ops（角色已有）+ `listTextModels` + 会话消息新字段返回

验收：UT 覆盖 resolveExecutor 决策表、历史净化、project 注入；`agent.chat_turn` task 在 backend console 发消息可流式返回；session 切角色后历史仍完整。

### P1 聊天前端

- `Controller/Frontend/Chat.php`、`view/templates/frontend/Chat/{index.phtml}`、`view/statics/js/agent-chat.js`、`view/statics/css/agent-chat.css`
- 后台 `Backend/Chat.php` getIndex 增加 models/projects assign；控制台 JS 补选择器
- QueryProvider 写 ops：`createProject`/`attachSessionToProject`/`sendChatTurn`（若需要）
- 菜单/ACL：`frontend_chat` 资源

验收：前台登录客户可建项目→建会话→选角色发消息→流式回复→切角色/模型继续→刷新后历史完整；后台控制台同能力；e2e spec（参照 `Test/e2e/backend/Weline_Agent-chat-console.spec.js` 新建 frontend spec）。

### P2 可视化角色编排

- `Model/AgentFlow*.php`（P0 已建表则此处补业务方法）、`Service/FlowGraphValidator.php`、`Service/FlowRunnerService.php`、`Service/Resumable/AgentFlowRunTaskHandler.php`
- `Controller/Backend/Flow.php`（index/edit/runHistory/getRunDetail/saveGraph 等）、`view/templates/backend/Flow/{listing,edit,run_detail}.phtml`、`view/statics/js/agent-flow-editor.js` + `agent-flow-run.js`
- QueryProvider：flow CRUD/run ops
- 菜单 `agent/backend/flow`

验收：建 3 角色链式流程→保存→运行→node_run 逐条记录→画布状态还原；非法图（环/悬空边/禁用角色）被 validator 拒绝；UT 覆盖拓扑排序与 schema 校验。

### P3 插件机制

- `Api/AgentPluginInterface.php`（或 `Interface/`）、`extends.php` Plugin 扩展点、`Service/PluginScanner.php`、`Plugin/FrameworkKnowledgePlugin.php` + 4 个 Skill 实现、`Controller/Backend/Plugin.php` + 模板、角色表单 plugins 多选
- `EffectiveCapabilityResolver` 接入 `SkillPackageManager::getSkillsForRole`/`getToolsForRole`
- QueryProvider plugin ops

验收：扫描登记→角色绑定→会话中生效技能数 = skills∪plugins；禁用插件即时失效；`framework_knowledge` 技能可真实读 doc；UT 覆盖 manifest 解析与 effective_skills 并集。

### P4 团队 @派单与项目收口

- `Model/AgentTask.php`（P0 建表）、`Service/AgentMentionParser.php`、`Service/AgentTaskDispatchService.php`、`Queue/AgentTaskDispatchQueue.php`、`Service/AgentTaskWorker.php`（或 queue 消费 handler）
- `Controller/Backend/Task.php`（任务管理/审批）、审批闸门
- 消息 meta 回流、前端 @补全 UI、flow↔task 打通（节点可选「派单给角色」执行模式，v1 可仅顺序执行）
- 与 Ops 边界核对文档（`Weline_Ops` 不改动声明）

验收：会话内 @角色 → task queued→running→done→来源会话出现系统回执；跨项目 @ 被拒；幂等键重复入队不重复执行；审批任务未批准不 done。

## 12. 风险与开放问题

| 风险 | 说明 | 缓解 |
|------|------|------|
| AgentEngine 流式改造面 | 工具循环+provider 回调透传，触及核心执行路径 | P0 单独 UT；不传 callback 行为不变；降级为整段 emit 也可用 |
| tool_calls 历史配对 | 跨角色/跨模型历史可能触发 provider 400 | §6.4 净化规则 + UT 用例 |
| 前台权限域 | frontend customer 与 backend admin 的 owner/context_id 口径 | 施工期先核对 `ResumableTaskOwnerResolver` 与 customer session 机制，写入 P1 验收 |
| 画布自研工作量 | 无现成库，拖拽/连线/面板均手写 | v1 严格限缩（§8.2）；复用架构 Tab 的 SVG 先例 |
| 插件升级兼容 | manifest 变更、技能类消失 | status=missing + effective_skills 运行时过滤 |
| 与 OPS 会话冲突 | 另一会话施工 `Weline_Ops` | 本平台只读对齐 `project.website_id`，不改 Ops 文件；队列命名 `AgentTaskDispatchQueue` 与 `OpsTaskDispatchQueue` 并存不冲突 |
| AiService 上帝服务 | 本设计新增调用方会加深耦合 | AgentRuntimeService 是唯一入口；后续可把 executeAgent 的模型/账户注入下沉（另立项） |

## 13. 明确不做（not_to_do）

- 不在 `Weline_Ai` 内实现会话/角色/流程（依赖方向不允许，归属已定 Agent）。
- 不在 `Weline_Agent` 复制 Provider/模型目录逻辑。
- 不为画布引入 npm/构建链或外部画布库（遵守仓内「JS 只走声明式加载」与无前端构建约定）。
- 不改 `generated/`、`view/tpl/`、`routes.xml`；字段走 `#[Col]`+setup:upgrade。
- 不在提示词层做项目隔离（必须服务层强校验）。
- 不动 `Weline_Ops` 现有队列/审批/Desk 实现。

## 14. 验收总原则

- 每期结束：相关 UT 全绿（`php bin/w phpunit:run --module=Weline_Agent`）+ 契约测试（QueryProvider descriptor、resumable handler registry）+ 后台/前台 Browser 实测（遵守 local-browser-urls 门禁：`.test.weline.com` host、FPC 穿章、禁缓存、用后关 Browser）。
- 文档先行：每期实现后回本 spec 标记对应小节 `status: done` 并补 `doc/开发日志.md`。
