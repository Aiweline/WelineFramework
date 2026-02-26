# Agent 间接口协议

## 概述

本文档定义了 Agent 之间的通信协议和接口规范。所有 Agent 必须遵循此协议进行协作。

## 目录结构

```
dev/ai/
├── agents/
│   ├── config.json             # 智能体系统配置
│   ├── tasks.json              # 全局任务看板（唯一事实来源）
│   ├── protocol.md             # 本文档
│   ├── Agent_DB_001/           # Agent 工作目录
│   │   ├── mission.json        # 决策包
│   │   └── status.log          # 状态日志
│   └── Agent_DB_001.log        # 请求/错误日志
├── code_backup/                # 代码备份目录（按 app 结构）
│   ├── app/
│   │   └── code/
│   │       └── Module/
│   │           └── Service/
│   │               └── Test.php.20260226_120000.bak
│   └── backup.log              # 备份记录
├── plans/
│   ├── payment-system.plan.md  # 计划文件
│   └── user-auth.plan.md
├── skills/                     # AI 技能库
└── rules/                      # AI 规则库
```

## 计划状态与工作流

### 状态说明

| 状态 | 图标 | 说明 | Watchdog 处理 |
|------|-----|------|--------------|
| `pending` | 🟡 | 计划已创建，等待启动 | 否（忽略） |
| `ready` | 🟢 | 计划已准备好，可以开始 | 否（忽略） |
| `running` | 🔵 | **计划进行中** | **是（执行）** |
| `paused` | ⏸️ | 计划暂停中 | 否（忽略） |
| `done` | ✅ | 计划已完成 | 否（忽略） |
| `cancelled` | ❌ | 计划已取消 | 否（忽略） |

### 工作流程

```
用户写需求 (plan.md)
       │
       ▼ 状态: pending
┌──────────────────┐
│  在 plan.md 中   │
│  写需求和任务    │
└────────┬─────────┘
         │
         ▼ 用户将状态改为 running 或执行 cursor:plan:start
┌──────────────────┐
│  状态: running   │◄─────── Watchdog 开始监控
│  Watchdog 执行   │
└────────┬─────────┘
         │
         ▼ 所有任务完成
┌──────────────────┐
│  状态: done      │
│  计划完成        │
└──────────────────┘
```

### 启动计划

1. **手动编辑**：将 `**状态**: pending` 改为 `**状态**: running`
2. **命令启动**：`php bin/w cursor:plan:start {name}`

### 待处理提醒

- Watchdog 启动后会持续显示待处理计划提醒
- `cursor:plan:list` 命令会在顶部显示警告框
- 只有 `running` 状态的计划才会被处理

## 计划文件格式 (*.plan.md)

```markdown
# 计划名称

## 元信息
- **ID**: plan_001
- **优先级**: high
- **状态**: pending
- **创建时间**: 2026-02-26
- **开始时间**: -
- **完成时间**: -

<!--
状态说明：
  - pending: 计划已创建，等待启动（Watchdog 忽略）
  - ready: 计划已准备好，可以开始（Watchdog 忽略）
  - running: 计划进行中（Watchdog 监控并执行）⭐ 设为此状态后开始工作
  - paused: 计划暂停中（Watchdog 忽略）
  - done: 计划已完成
  - cancelled: 计划已取消
-->

## 需求描述

详细描述要实现的功能...

## 任务分解

- [ ] 任务1 @Agent:DB @File:Model/User.php [P1]
- [ ] 任务2 @Agent:Logic @File:Service/Auth.php @Dep:任务1 [P2]
- [ ] 任务3 @Agent:API @File:Controller/Login.php @Dep:任务2 [P2]

## 测试要求

- [ ] 单元测试: Test/AuthTest.php
- [ ] HTTP 测试: 验证登录接口

## 验收标准

1. 功能可正常使用
2. 所有测试通过
3. 代码符合规范
```

## 任务状态定义

| 状态 | 说明 | 转换条件 |
|------|------|---------|
| `todo` | 待执行 | 初始状态或依赖满足后 |
| `blocked` | 阻塞中 | 等待依赖任务完成 |
| `running` | 执行中 | 已派发给 Cursor |
| `done` | 已完成 | 代码完成且测试通过 |
| `failed` | 已失败 | 语法错误或测试失败 |
| `retry` | 重试中 | Master Brain 修复后重新派发 |

## 测试集成

### 自动测试流程

```
任务完成 → 语法检查 → 单元测试 → HTTP 测试（可选）→ 标记完成/失败
```

### 测试命令

```bash
# 单元测试
php bin/w phpunit:run Test/ModuleTest.php

# HTTP 测试
php bin/w http:req -b /path/to/test

# 全量测试
php bin/w phpunit:run --all
```

## Agent ID 命名规范

格式：`Agent_{Type}_{Sequence}`

| Type | 职责 | 测试要求 |
|------|------|---------|
| DB | 数据库、模型 | 必须 |
| Logic | 业务逻辑、服务 | 必须 |
| API | 控制器、接口 | 必须 |
| UI | 视图、前端 | 可选 |
| Test | 测试 | 不需要 |
| General | 通用 | 视情况 |

## 依赖关系

### 声明依赖

在计划文件中通过 `@Dep:任务ID` 声明：

```markdown
- [ ] 创建用户服务 @Agent:Logic @Dep:Agent_DB_001
```

### 依赖解除

当依赖任务状态变为 `done` 时，被阻塞的任务自动从 `blocked` 变为 `todo`。

## 完成确认协议

### 代码标记

```php
// @Status: Done by {AgentID} [{timestamp}]
```

### 测试验证

```bash
# Watchdog 自动执行
php -l file.php                    # 语法检查
php bin/w phpunit:run TestFile.php # 单元测试
```

## 备份与恢复

### 自动备份

在 Watchdog 监控过程中，修改的文件会自动备份到 `dev/ai/code_backup` 目录，保持与 `app` 相同的目录结构。

**备份路径格式**:
```
dev/ai/code_backup/{relative_path}.{YYYYMMDD_HHMMSS}.bak
```

**示例**:
```
app/code/Module/Service/Test.php
→ dev/ai/code_backup/app/code/Module/Service/Test.php.20260226_120000.bak
```

### 恢复命令

```bash
# 查看文件备份
php bin/w cursor:backup:list app/code/Module/Service/Test.php

# 恢复最新备份
php bin/w cursor:backup:list app/code/Module/Service/Test.php --restore

# 清理旧备份
php bin/w cursor:backup:list --cleanup
```

## 规则合规性

### 自动检查

系统自动分析 `.cursor/rules` 和 `.cursor/skills` 下的规则，检测代码变更是否合规。

### 检查类型

| 类型 | 检查内容 | 严重性 |
|------|---------|--------|
| `css_hardcoded_color` | CSS 硬编码颜色（应使用 var(--backend-color-*)) | error |
| `css_generic_class` | 通用 CSS 类名（可能污染全局） | warning |
| `js_global_pollution` | JS 全局变量/函数（应使用 IIFE） | error |
| `js_native_dialog` | 原生 alert/confirm/prompt | error |
| `phtml_hardcoded_text` | 硬编码文案（应使用 <lang>） | warning |
| `phtml_global_function` | 模板全局函数（应使用闭包） | error |
| `php_error_log` | 使用 error_log（应使用 Env::log_error） | error |
| `php_missing_fetch` | Model 查询缺少 fetch() | error |

### 检查命令

```bash
# 检查全部代码
php bin/w cursor:compliance:check

# 检查并生成修复任务
php bin/w cursor:compliance:check app/code/Module -g

# 查看规则列表
php bin/w cursor:compliance:check --rules
```

---

*版本: 3.1*
*最后更新: 2026-02-26*
