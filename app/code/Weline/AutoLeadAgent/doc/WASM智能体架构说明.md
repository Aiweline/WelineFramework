# WASM 智能体架构说明

## 概述

AutoLeadAgent 采用 **WASM 主导决策** 的智能体架构：

- **WASM（C++）**：作为智能体大脑，负责 ReAct 决策循环、状态管理、MCP 调用编码
- **JS（浏览器）**：作为 I/O 层，负责任务注入、工具执行、状态心跳、UI 更新

## 架构图

```
┌─────────────────────────────────────────────────────────────────┐
│                        后端任务配置                               │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    JS 层 (task-runner.js)                        │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │  WasmTaskExecutor.startTask(taskConfig)                  │    │
│  │  - 任务配置注入                                           │    │
│  │  - 启动决策循环                                           │    │
│  │  - UI 状态更新                                            │    │
│  └─────────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                   WasmBridge (wasm-bridge.js)                    │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │  startTaskInWasm(taskJson)     → wasm_start_task()       │    │
│  │  runWasmLoopStep()             → wasm_next_decision()    │    │
│  │  applyToolResult(result)       → wasm_apply_tool_result()│    │
│  │  getWasmStatus()               → wasm_get_status()       │    │
│  └─────────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│              WASM 智能体大脑 (agent_brain.cpp)                   │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │  AgentState 状态机：                                      │    │
│  │  IDLE → INITIAL → NAVIGATING → TAKING_SNAPSHOT           │    │
│  │       → EXTRACTING → ANALYZING → VISITING → COMPLETE     │    │
│  │                                                          │    │
│  │  ReAct 循环：Think → Act → Observe                        │    │
│  │  - 生成 ToolCall JSON                                     │    │
│  │  - 解析工具结果                                            │    │
│  │  - 更新内部状态                                            │    │
│  └─────────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                  MCP 协议层 (mcp_protocol.cpp)                   │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │  encodeToolCallWithMeta() - 编码工具调用                   │    │
│  │  parseToolResult()        - 解析工具结果                   │    │
│  └─────────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                Browser MCP 扩展 (background.js)                  │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │  WASM_EXECUTE_TOOL → mcpTools[name](args)                │    │
│  │  - browser_navigate                                       │    │
│  │  - browser_snapshot                                       │    │
│  │  - browser_extract                                        │    │
│  │  - browser_click / browser_type                           │    │
│  └─────────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────────┘
```

## 核心接口

### WASM 导出接口

```c
// 启动任务
void wasm_start_task(const char* taskJson);

// 获取下一步决策
const char* wasm_next_decision();

// 应用工具执行结果
void wasm_apply_tool_result(const char* resultJson);

// 获取当前状态
const char* wasm_get_status();

// 停止任务
void wasm_stop_task();
```

### JS 调用方式

```javascript
// 初始化
WasmTaskExecutor.init({
    wasmPath: 'agent-core.wasm',
    onStatusChange: (status) => console.log('状态:', status),
    onLog: (log) => console.log(log.level, log.message),
    onComplete: (result) => console.log('完成:', result),
    onError: (error) => console.log('错误:', error)
});

// 启动任务
await WasmTaskExecutor.startTask({
    taskId: 'task_001',
    profile: { name: '科技行业', keywords: ['人工智能', '机器学习'] },
    selectedSearchEngines: ['Google'],
    maxIterations: 20
});

// 停止任务
WasmTaskExecutor.stopTask();
```

## 决策循环流程

1. **JS 注入任务** → `wasm_start_task(taskJson)`
2. **WASM 生成决策** → `wasm_next_decision()` 返回 ToolCall JSON
3. **JS 执行工具** → `MCPClient.executeToolForWasm(name, args)`
4. **JS 回写结果** → `wasm_apply_tool_result(resultJson)`
5. **循环 2-4** 直到 WASM 返回 `type: "complete"`

## 状态机阶段

| 阶段 | 说明 |
|------|------|
| IDLE | 空闲，等待任务 |
| INITIAL | 初始化，准备开始搜索 |
| NAVIGATING | 正在导航到目标页面 |
| TAKING_SNAPSHOT | 正在获取页面快照 |
| EXTRACTING | 正在提取联系信息 |
| ANALYZING | 分析搜索结果，选择下一个目标 |
| VISITING | 访问候选页面 |
| COMPLETE | 任务完成 |
| ERROR | 出错 |

## 工具调用格式

### 请求格式（WASM → JS）

```json
{
    "type": "tool_call",
    "id": "tc_task001_5",
    "name": "browser_navigate",
    "arguments": {
        "url": "https://www.google.com/search?q=AI+机器学习"
    },
    "meta": {
        "taskId": "task_001",
        "iteration": 5,
        "origin": "wasm_agent"
    }
}
```

### 结果格式（JS → WASM）

```json
{
    "id": "tc_task001_5",
    "name": "browser_navigate",
    "success": true,
    "result": {
        "url": "https://www.google.com/search?q=AI+机器学习",
        "title": "AI 机器学习 - Google 搜索",
        "tabId": 123
    }
}
```

## 编译 WASM

```bash
# 使用框架命令编译
php bin/m wasm:compile

# 查看编译环境
php bin/m wasm:compile --env

# 强制重新编译
php bin/m wasm:compile --force
```

## 文件结构

```
app/code/Weline/AutoLeadAgent/
├── wasm/
│   ├── src/
│   │   ├── agent_brain.cpp      # 智能体大脑（ReAct 状态机）
│   │   ├── mcp_protocol.cpp     # MCP 协议编解码
│   │   ├── agent_core.cpp       # 工具函数（已废弃）
│   │   └── CMakeLists.txt       # 编译配置
│   └── output/
│       └── agent-core.wasm      # 编译输出
├── browser-extension/
│   ├── wasm-bridge.js           # WASM 桥接层
│   ├── mcp-tools.js             # MCP 工具实现
│   └── background.js            # 扩展后台服务
└── view/statics/js/
    ├── wasm-task-executor.js    # WASM 任务执行器
    ├── mcp-client.js            # MCP 客户端
    └── task-runner.js           # 任务运行器
```

## 兼容性

当前实现保留了与 JS ReAct 路径的兼容性：

- 如果 WASM 不可用，自动回退到 JS 决策
- 旧接口 `decideNextAction()` 仍然可用
- 通过配置开关可以选择使用 WASM 或 JS 路径

## 后续计划

1. **阶段 2**：接入 ModelInference，由 WASM 主导调用时机
2. **阶段 3**：优化性能，增加限流、超时控制
3. **阶段 4**：完整迁移 ReAct 决策逻辑到 WASM

