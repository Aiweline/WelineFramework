# PHP Agent Orchestrator (CursorSupervisor)

## 概述

基于 **分布式任务总线** 模式的智能体编排系统。实现 PHP 监工自我监督、多任务并行、反向驱动 Cursor 的完整架构。

## 框架新特性与卖点（本版本所依托）

以下为 Weline Framework 与本编排系统协同的核心能力，可作为产品/技术卖点。

### Schema-Diff 模式：自动备份与回滚

- **声明式表结构**：Model 使用 `#[Col]`、`#[Table]` 声明字段与表，无需手写 SQL 迁移脚本。
- **自动备份**：执行 DDL 前（尤其是 DROP COLUMN）自动备份列数据到 `weline_database_backups`，支持按迁移 ID 恢复。
- **可回滚**：每条 DDL 记录 `forward_ddl` 与 `rollback_ddl`，迁移记录持久化，便于回滚与审计。
- **统一入口**：`php bin/w setup:upgrade` 触发 SchemaDiff 阶段，解析所有模块 Model、与库表 diff、按优先级执行 DDL。

### 统一查询（w_query / FrameworkQueryService）

- **模块间查询**：跨模块读数据/做操作统一走 **QueryProvider**，禁止为每次查询创建独立事件。
- **前后端一致**：后端 `w_query($provider, $operation, $params, $area)`，前端 `window.w_query(...)`，同一套 API。
- **自省**：`w_query('framework', 'introspect', ['what' => 'providers'])` 可查询已注册的查询器与操作。

### WLS (Weline Server)

- **常驻内存**：Master-Dispatcher-Worker 架构，业务代码常驻内存，减少重复加载。
- **热重载**：绝大多数代码修改只需 `php bin/w server:reload`，无需重启整个服务。
- **运维命令**：`server:start` / `server:stop` / `server:status` / `server:benchmark` 等，支持多实例与 SSL。

## 系统架构

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                     PHP Agent Orchestrator v3.1                             │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│   👤 用户                                                                   │
│    │                                                                        │
│    │ 下达大任务 (plan.md)                                                   │
│    ▼                                                                        │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                    🧠 Master Brain                                   │   │
│  │                    (MasterBrainService)                              │   │
│  │                                                                      │   │
│  │  • 理解用户需求                                                      │   │
│  │  • 调用 AI (DeepSeek/Claude) 进行任务拆解                           │   │
│  │  • 输出原子级 Sub-Tasks                                              │   │
│  └──────────────────────────────┬──────────────────────────────────────┘   │
│                                 │                                           │
│                                 ▼                                           │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                    📋 Task Pool                                      │   │
│  │                    (TaskPoolService)                                 │   │
│  │                    dev/ai/agents/tasks.json                          │   │
│  │                                                                      │   │
│  │  • 全局任务看板（唯一事实来源）                                       │   │
│  │  • 状态管理: todo → running → done                                   │   │
│  │  • 依赖追踪: blocked → todo (依赖满足后)                             │   │
│  └──────────────────────────────┬──────────────────────────────────────┘   │
│                                 │                                           │
│         ┌───────────────────────┴───────────────────────┐                   │
│         │                                               │                   │
│         ▼                                               ▼                   │
│  ┌──────────────────────┐                    ┌──────────────────────┐      │
│  │  🚀 Cursor Driver    │                    │  🐕 Watchdog         │      │
│  │  (CursorDriverService)│                   │  (WatchdogService)   │      │
│  │                       │                   │                       │      │
│  │  • 派发任务给 Cursor  │                   │  • 监控源码变化       │      │
│  │  • 注入信号弹         │                   │  • 运行语法检查       │      │
│  │  • 多实例并行         │                   │  • 执行 PHPUnit 测试  │      │
│  │  • 模拟按键触发       │                   │  • 检测任务完成       │      │
│  └──────────┬───────────┘                    └──────────┬───────────┘      │
│             │                                           │                   │
│             ▼                                           │                   │
│  ┌──────────────────────────────────────────────────────┼──────────────┐   │
│  │                    Cursor AI 实例                     │              │   │
│  │                                                       │              │   │
│  │  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  │              │   │
│  │  │ Instance A  │  │ Instance B  │  │ Instance C  │  │              │   │
│  │  │ (Agent_DB)  │  │ (Agent_API) │  │ (Agent_UI)  │  │              │   │
│  │  └──────┬──────┘  └──────┬──────┘  └──────┬──────┘  │              │   │
│  │         │                │                │         │              │   │
│  │         └────────────────┴────────────────┘         │              │   │
│  │                          │                          │              │   │
│  └──────────────────────────┼──────────────────────────┼──────────────┘   │
│                             │                          │                   │
│                             ▼                          │                   │
│  ┌──────────────────────────────────────────────────────┼──────────────┐   │
│  │                    共享日志 & 源码                    │              │   │
│  │                                                       ◄──────────────   │
│  │  • 完成信号: @Status: Done                                          │   │
│  │  • 错误日志: dev/ai/agents/{ID}.log                                 │   │
│  │  • 状态日志: dev/ai/agents/{ID}/status.log                          │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

## 核心组件

### 1. Master Brain (大脑)

**文件**: `Service/MasterBrainService.php`

**职责**:
- 理解用户原始需求
- 调用 AI 模型（DeepSeek/Claude）进行任务拆解
- 输出符合 `tasks.json` 格式的原子级任务
- 处理任务失败，尝试修复并重试

**示例**:
```php
$masterBrain = ObjectManager::getInstance(MasterBrainService::class);
$masterBrain->setModel('deepseek');
$tasks = $masterBrain->processRequirement('开发用户登录功能');
// 返回: Agent_DB_001, Agent_Logic_001, Agent_API_001
```

### 2. Task Pool (任务池)

**文件**: `Service/TaskPoolService.php`

**数据文件**: `dev/ai/agents/tasks.json`

**职责**:
- 全局任务看板（唯一事实来源）
- 任务状态管理
- 依赖关系追踪
- 优先级排序

**任务状态**:
```
todo     → 待执行
blocked  → 等待依赖
running  → 执行中
done     → 已完成
failed   → 已失败
```

**示例**:
```json
{
    "project": "WelineFramework",
    "agents": {
        "Agent_DB_001": {
            "file": "Model/User.php",
            "description": "创建用户模型",
            "status": "todo",
            "dep": null,
            "priority": "high"
        },
        "Agent_API_001": {
            "file": "Controller/Login.php",
            "description": "创建登录控制器",
            "status": "blocked",
            "dep": "Agent_DB_001",
            "priority": "normal"
        }
    }
}
```

### 3. Cursor Driver (驱动器)

**文件**: `Service/CursorDriverService.php`

**职责**:
- 从任务池获取可执行任务
- 注入 `[SUPERVISOR_TASK]` 信号弹
- 生成 `mission.json` 决策包
- 通过 `cursor --goto` 唤醒 Cursor
- 模拟按键自动触发执行
- 支持多实例并行

**示例**:
```php
$driver = ObjectManager::getInstance(CursorDriverService::class);
$driver->setMaxParallelAgents(3)
       ->setAutoTrigger(true);
$dispatched = $driver->drive(); // 返回派发的任务数
```

### 4. Watchdog (监工)

**文件**: `Service/WatchdogService.php`

**职责**:
- 监控 `plan.md` 文件变化
- 监控源码文件变化
- 运行 `php -l` 语法检查
- 执行 PHPUnit 测试
- 检测任务完成状态
- **自动备份变更文件到 `dev/ai/code_backup`**
- **检测规则合规性，生成修复任务**
- 将错误反馈给 Master Brain

**示例**:
```php
$watchdog = ObjectManager::getInstance(WatchdogService::class);
$watchdog->setCheckInterval(2)
         ->setRunTests(true)
         ->setAutoBackup(true)
         ->setComplianceCheck(true)
         ->setVerbose(true)
         ->start(); // 启动监控循环
```

### 5. Rule Analyzer (规则分析器)

**文件**: `Service/RuleAnalyzerService.php`

**职责**:
- 扫描 `dev/ai/rules/*.mdc` 规则文件
- 扫描 `dev/ai/skills/*/SKILL.md` 技能文件
- 提取规则关键词、触发条件、约束
- 构建规则索引供合规检查使用

**示例**:
```php
$analyzer = ObjectManager::getInstance(RuleAnalyzerService::class);
$rules = $analyzer->getRulesForFile('app/code/Module/view/test.css');
// 返回: theme-development, code-generation-standards 等适用规则
```

### 6. Code Backup (代码备份)

**文件**: `Service/CodeBackupService.php`

**数据目录**: `dev/ai/code_backup/`

**职责**:
- 修改文件前自动备份
- 保持与 `app/` 相同的目录结构
- 支持版本化备份（带时间戳）
- 提供恢复功能

**示例**:
```php
$backup = ObjectManager::getInstance(CodeBackupService::class);
$backup->backupFile('app/code/Module/Service/Test.php');
$backup->restoreFile($backup->getLatestBackup('app/code/Module/Service/Test.php'));
```

### 7. Compliance Checker (合规检查器)

**文件**: `Service/ComplianceCheckerService.php`

**职责**:
- 检测 CSS 硬编码颜色（必须使用主题变量）
- 检测 JS 全局污染（必须使用 IIFE）
- 检测 PHTML 硬编码文案（必须国际化）
- 检测 PHP 禁用函数（error_log 等）
- 识别违规项并生成修复建议

**示例**:
```php
$checker = ObjectManager::getInstance(ComplianceCheckerService::class);
$result = $checker->checkFile('app/code/Module/view/statics/css/style.css');
// 返回: ['compliant' => false, 'violations' => [...]]
```

### 8. Auto Task Generator (自动任务生成器)

**文件**: `Service/AutoTaskGeneratorService.php`

**职责**:
- 实时监控文件变化
- 分析变化是否符合规则
- 自动生成修复任务到任务池
- 调用 CLI 完成任务

**示例**:
```php
$generator = ObjectManager::getInstance(AutoTaskGeneratorService::class);
$generator->setAutoBackup(true)
          ->setAutoFix(true)
          ->processFileChange('app/code/Module/view/statics/css/style.css');
```

## CLI 命令

### Orchestrator 命令组

```bash
# 启动完整编排系统
php bin/w cursor:orchestrator:run

# 详细模式 + 5 个并行
php bin/w cursor:orchestrator:run -v -p 5

# 使用 Claude 模型
php bin/w cursor:orchestrator:run -m claude

# 查看状态
php bin/w cursor:orchestrator:status

# 任务管理
php bin/w cursor:orchestrator:task list
php bin/w cursor:orchestrator:task add --agent=Agent_DB_001 --file=Model/User.php --desc="创建用户模型"
php bin/w cursor:orchestrator:task process --req="开发支付功能"
php bin/w cursor:orchestrator:task dispatch -p 5
php bin/w cursor:orchestrator:task clear
php bin/w cursor:orchestrator:task reset
```

### Plan 命令组（计划驱动）

```bash
# 列出所有计划
php bin/w cursor:plan:list

# 查看计划状态
php bin/w cursor:plan:status my-feature

# 执行计划（持续模式）
php bin/w cursor:plan:execute my-feature

# 单次检查模式
php bin/w cursor:plan:execute my-feature --once

# 详细模式 + 禁用测试
php bin/w cursor:plan:execute my-feature -v --no-test
```

### Compliance 命令组（合规检查）

```bash
# 查看所有规则和技能
php bin/w cursor:compliance:check --rules

# 检查全部代码
php bin/w cursor:compliance:check

# 检查指定文件
php bin/w cursor:compliance:check app/code/Module/Service/Test.php

# 检查并生成修复任务
php bin/w cursor:compliance:check app/code/Module -g
```

### Backup 命令组（代码备份）

```bash
# 查看备份统计
php bin/w cursor:backup:list

# 查看文件备份
php bin/w cursor:backup:list app/code/Module/Service/Test.php

# 恢复最新备份
php bin/w cursor:backup:list app/code/Module/Service/Test.php --restore

# 清理旧备份
php bin/w cursor:backup:list --cleanup
```

### Supervisor 命令组（原有）

```bash
# 启动监督助手
php bin/w cursor:supervisor:start

# 查看状态
php bin/w cursor:supervisor:status

# 停止
php bin/w cursor:supervisor:stop
```

## 工作流程

### 完整流程

```
1. 你在 plan.md 写下需求
      ↓
2. Watchdog 检测到 plan.md 变化
      ↓
3. Master Brain 调用 AI 拆解任务
      ↓
4. 任务写入 tasks.json
      ↓
5. Driver 从任务池获取可执行任务
      ↓
6. Driver 注入信号弹 + 生成 mission.json
      ↓
7. Driver 唤醒 Cursor + 模拟按键
      ↓
8. Cursor 识别 [SUPERVISOR_TASK]，读取 mission.json
      ↓
9. Cursor 执行任务（从属模式）
      ↓
10. Cursor 完成后删除信号弹，写入 @Status: Done
      ↓
11. Watchdog 检测完成，更新 tasks.json
      ↓
12. 如果有依赖任务，解除阻塞，继续派发
      ↓
13. 重复 5-12 直到所有任务完成
```

### 并行执行示例

```
                    tasks.json
                        │
        ┌───────────────┼───────────────┐
        │               │               │
        ▼               ▼               ▼
   Agent_DB_001    Agent_UI_001    Agent_Test_001
   (status:running) (status:running) (status:running)
        │               │               │
        ▼               ▼               ▼
   Cursor A        Cursor B        Cursor C
   (Model/User)    (view/login)    (Test/User)
```

## 目录结构

```
dev/ai/
├── agents/
│   ├── config.json             # 系统配置
│   ├── tasks.json              # 全局任务看板
│   ├── protocol.md             # Agent 间接口协议
│   ├── Agent_DB_001/
│   │   ├── mission.json        # 决策包
│   │   └── status.log          # 状态日志
│   └── Agent_DB_001.log        # 请求/错误日志
├── plans/
│   └── *.plan.md               # 计划文件
├── skills/                     # AI 技能库
└── rules/                      # AI 规则库

app/code/Agent/CursorSupervisor/
├── Console/
│   └── Cursor/
│       ├── Orchestrator/
│       │   ├── Run.php         # 启动编排器
│       │   ├── Status.php      # 查看状态
│       │   └── Task.php        # 任务管理
│       ├── Plan/
│       │   ├── Execute.php     # 执行计划
│       │   ├── Lists.php       # 列出计划
│       │   └── Status.php      # 计划状态
│       └── Supervisor/
│           ├── Start.php
│           ├── Stop.php
│           └── Status.php
├── Service/
│   ├── MasterBrainService.php  # 大脑：任务拆解
│   ├── TaskPoolService.php     # 任务池管理
│   ├── CursorDriverService.php # Cursor 驱动
│   ├── WatchdogService.php     # 监工/守护进程
│   ├── PlanExecutorService.php # 计划执行器
│   ├── AgentDispatcher.php     # 信号弹注入
│   ├── DocumentTaskScanner.php # 文档扫描
│   ├── CodeTaskMatcher.php     # 代码匹配
│   └── TaskCompletionDetector.php # 完成检测
└── doc/
    └── README.md
```

## 配置

### config.json (dev/ai/agents/config.json)

系统配置文件，定义：

- 路径配置（plans, agents, tasks）
- Master Brain 设置（模型、重试次数）
- Driver 设置（并行数、自动触发）
- Watchdog 设置（检查间隔、测试选项）

### .cursorrules

项目根目录的 `.cursorrules` 定义了 Cursor AI 的从属模式协议：

- 识别 `[SUPERVISOR_TASK]` 或 `@AgentID` 标记
- 读取 `dev/ai/agents/tasks.json` 检查依赖
- 读取 `dev/ai/agents/{ID}/mission.json` 获取指令
- 仅修改指定的 `target_file`
- 完成后写入 `@Status: Done`

### 环境要求

- PHP 8.0+
- Cursor IDE (支持 `cursor --goto` 命令)
- AI 服务 (DeepSeek/Claude 等)

## 日志文件

| 文件 | 说明 |
|------|------|
| `var/log/master-brain.log` | Master Brain 日志 |
| `var/log/task-pool.log` | 任务池操作日志 |
| `var/log/cursor-driver.log` | Cursor 驱动日志 |
| `var/log/watchdog.log` | Watchdog 监控日志 |
| `var/log/agent-dispatcher.log` | 信号弹注入日志 |

## 版本历史

- **v3.1** (2026-03): 文档更新：框架卖点与新特性 — Schema-Diff 自动备份/回滚、统一查询（w_query）、WLS 常驻与热重载
- **v3.0** (2026-02-26): 分布式任务总线架构，Master Brain + Task Pool + Driver + Watchdog
- **v2.0**: Headless Agent Control 模式
- **v1.0**: 基础代码监控和 AI 修复
