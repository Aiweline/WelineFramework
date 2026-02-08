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
| `extends.php` | Agent 扩展点定义 |
| `Service/AiService.php` | 集成 executeAgent / getAgentsForScenario 等方法 |

### Provider 层 Function Calling（已完成）

- `OpenAiProvider`：支持 tools + tool_choice 参数，解析 tool_calls
- `AnthropicProvider`：支持 tool_use 格式，消息历史转换

## 三、待实现任务

### 任务 1：触发智能体扫描（关键缺失）

**问题**：`AgentScanner.scanAllAgents()` 已实现但从未被调用，智能体不会注册到数据库。

**修复**：
1. 在 `SetupUpgradeAfter` Observer 中加入 `scanAllAgents()` 调用
2. 在 `ModuleUpgradeAdapterScanObserver` 中也加入扫描

### 任务 2：创建 CLI 命令 `ai:agent:scan`

仿照 `ai:adapter:scan` 创建独立命令，支持手动触发扫描。

### 任务 3：i18n 翻译补充

为新增的 CLI 命令和扫描日志添加中英文翻译。

## 四、接口说明

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
