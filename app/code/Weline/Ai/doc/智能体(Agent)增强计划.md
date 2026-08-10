# Weline_Ai 模块 - 智能体（Agent）增强计划

## 一、概述

AI 模块增强智能体收集与管理能力，通过 `extends` 规约自动发现、注册各模块实现的智能体。

## 二、架构设计

### 扩展点收集模式

```
extends/module/Weline_Ai/Agent/
├── ModuleA/
│   └── SomeAgent.php          # implements AgentInterface
├── ModuleB/
│   └── AnotherAgent.php       # implements AgentInterface
└── ...
```

- 各模块在 `extends/module/Weline_Ai/Agent/` 下放置智能体类
- `AgentScanner` 自动扫描、实例化并注册到 `ai_agent` 数据库表
- 每个智能体声明支持的场景码（scenarios），前端按场景筛选

### 核心文件（已完成）

| 文件 | 作用 |
|------|------|
| `Interface/AgentInterface.php` | 智能体标准接口 |
| `Interface/ToolInterface.php` | 工具标准接口 |
| `Agent/AgentResult.php` | 执行结果 DTO |
| `Service/AgentScanner.php` | 智能体扫描器 |
| `Model/AiAgent.php` | 数据库模型（ai_agent 表） |
| `Model/AiAgentTool.php` | 智能体工具快照与覆盖 |
| `Service/Agent/AgentCatalogService.php` | 目录、覆盖、启停 |
| `Service/Agent/AgentGovernance.php` | 运行时工具过滤与描述覆盖 |
| `Controller/Backend/Agent.php` | 后台智能体 Tab |
| `extends.php` | Agent 扩展点定义 |
| `Service/AiService.php` | 集成 executeAgent / getAgentsForScenario 等方法 |

### Provider 层 Function Calling（已完成）

- `OpenAiProvider`：支持 tools + tool_choice 参数，解析 tool_calls
- `AnthropicProvider`：支持 tool_use 格式，消息历史转换

## 三、后台治理（已完成）

- 后台 Tab / 菜单：`ai/backend/agent`
- 扫描已接入 `SetupUpgradeAfter`、`ModuleUpgradeAdapterScanObserver` 与 `ai:agent:scan`
- 扫描保留人工覆盖与启停；禁用工具不进入 `AiService::executeAgent` 的 function calling
- 直接 `$agent->execute()` 旁路不受治理

## 四、已知限制

- 不能在后台新建自定义智能体，不能改工具 JSON Schema / system prompt / `max_iterations`
- `AiSiteBlogContentSeedService` 等直接调用 `$agent->execute()` 的路径不读取描述覆盖与工具启停

## 五、接口说明

### AgentInterface 方法

| 方法 | 返回值 | 说明 |
|------|--------|------|
| `getCode()` | `string` | 唯一标识码 |
| `getName()` | `string` | 显示名称 |
| `getDescription()` | `string` | 描述 |
| `getVersion()` | `string` | 版本 |
| `getScenarios()` | `array` | 支持的场景码列表 |
| `getTools()` | `ToolInterface[]` | 工具列表 |
| `getSystemPrompt(array $context)` | `string` | 系统提示词 |
| `execute(string $prompt, AiModel $model, array $params, ?callable $streamCallback)` | `AgentResult` | 执行任务 |
| `supportsModel(string $modelCode)` | `bool` | 是否支持指定模型 |
| `getMaxIterations()` | `int` | 最大工具调用轮次 |

### ToolInterface 方法

| 方法 | 返回值 | 说明 |
|------|--------|------|
| `getName()` | `string` | 工具名称（snake_case） |
| `getDescription()` | `string` | 描述 |
| `getParameters()` | `array` | JSON Schema 参数定义 |
| `execute(array $args)` | `mixed` | 执行工具 |
| `isEnabled()` | `bool` | 是否启用 |

### AiService Agent 方法

| 方法 | 说明 |
|------|------|
| `executeAgent($agentCode, $prompt, $modelCode, $params, $streamCallback)` | 执行智能体 |
| `getAgentsForScenario($scenarioCode)` | 获取场景可用智能体列表 |
| `getAgentInfo($agentCode)` | 获取智能体详情 |
| `getAllActiveAgents()` | 获取所有活跃智能体 |
