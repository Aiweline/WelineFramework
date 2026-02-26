# PHP Agent Orchestrator - 智能体配置中心

## 概述

此目录是 PHP Agent Orchestrator 的核心配置中心，包含智能体系统配置、任务池、协议定义。

## 目录结构

```
dev/ai/agents/
├── config.json         # 系统配置
├── tasks.json          # 任务池（唯一事实来源）
├── protocol.md         # Agent 间接口协议
├── README.md           # 本文档
└── {AgentID}/          # Agent 工作目录（运行时创建）
    ├── mission.json    # 决策包
    └── status.log      # 状态日志
```

## 快速开始

### 1. 创建计划

在 `dev/ai/plans/` 下创建计划文件：

```bash
# 创建新计划
touch dev/ai/plans/my-feature.plan.md
```

计划文件格式：

```markdown
# 我的功能

## 元信息
- **ID**: my_feature_001
- **优先级**: high

## 需求描述

实现用户登录功能...

## 任务分解

- [ ] 创建用户模型 @Agent:DB @File:Model/User.php [P1]
- [ ] 实现认证服务 @Agent:Logic @File:Service/AuthService.php @Dep:Agent_DB_001 [P2]
```

### 2. 执行计划

```bash
# 执行指定计划
php bin/w cursor:plan:execute my-feature

# 带测试执行
php bin/w cursor:plan:execute my-feature --test

# 查看状态
php bin/w cursor:orchestrator:status
```

### 3. 监控执行

```bash
# 查看任务状态
php bin/w cursor:orchestrator:task list

# 查看日志
tail -f var/log/watchdog.log
```

## 配置说明

### config.json

| 配置项 | 说明 |
|--------|------|
| `master_brain.default_model` | AI 模型（deepseek/claude） |
| `driver.max_parallel_agents` | 最大并行 Agent 数 |
| `watchdog.run_unit_tests` | 是否运行单元测试 |
| `testing.auto_test_on_complete` | 完成后自动测试 |

### tasks.json

全局任务看板，记录所有任务的状态：

```json
{
    "current_plan": "my-feature",
    "agents": {
        "Agent_DB_001": {
            "status": "running",
            "file": "Model/User.php"
        }
    }
}
```

## 工作流程

```
1. 创建计划文件 (dev/ai/plans/*.plan.md)
2. 执行 cursor:plan:execute {plan_name}
3. Master Brain 解析计划，拆解任务
4. 任务写入 tasks.json
5. Driver 派发任务给 Cursor
6. Watchdog 监控执行，运行测试
7. 任务完成，更新状态
8. 所有任务完成，计划结束
```

## 相关命令

```bash
# 计划管理
php bin/w cursor:plan:list              # 列出所有计划
php bin/w cursor:plan:execute {name}    # 执行计划
php bin/w cursor:plan:status {name}     # 查看计划状态

# 编排器
php bin/w cursor:orchestrator:run       # 启动编排器
php bin/w cursor:orchestrator:status    # 查看状态
php bin/w cursor:orchestrator:task      # 任务管理
```

## 日志文件

| 文件 | 说明 |
|------|------|
| `var/log/master-brain.log` | Master Brain 日志 |
| `var/log/watchdog.log` | Watchdog 监控日志 |
| `var/log/cursor-driver.log` | Cursor 驱动日志 |

---

*PHP Agent Orchestrator v3.0*
