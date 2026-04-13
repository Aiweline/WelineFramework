# WLS 编排器启动流程优化 (2026-04-13)

## 概述

本文档记录 WLS (Weline Light Server) 在 2026-04-13 重要的架构优化，涉及子进程并发启动、Master IPC 初始化时序、Windows 批量启动等核心模块的改进。

**目标**：确保 Master 启动完等所有关键流程后，立即进入主循环和 IPC 监听，子进程能够成功连接，避免启动卡顿和连接失败。

---

## 历史背景

### 问题回顾（2026-04-07）

#### 症状
1. 所有子进程无法连接 Master IPC（27.0.0.1:26895）
2. Worker 重试 60 次仍失败，最终超时
3. Master 日志显示正常启动完成，但无法响应连接

#### 根本原因链

| 层级 | 问题 | 影响 |
|-------|------|------|
| **Processer 层** | `waitForWindowsBatchCreateHelper()` 阻塞等待所有进程启动完成 | Master 主线程卡住 |
| **Master 层** | Master 无法进入 mainLoop/runLoop | IPC 控制面未初始化 |
| **Worker 层** | 启动时 Master IPC 还没准备好 | 连接拒绝 |

---

## 核心改进方案

### 1. Windows 批量启动非阻塞化

**文件**：`app/code/Weline/Framework/System/Process/Processer.php`  
**方法**：`batchCreateWindows()` (第 2126-2410 行)

#### 问题
批启动一直等待 PowerShell 脚本完成所有进程的启动报告（PID 输出到结果文件），导致 Master 主线程被阻塞。

#### 改进
```php
// 添加配置控制
$waitForResults = \defined('WELINE_BATCH_CREATE_WAIT_RESULTS') && WELINE_BATCH_CREATE_WAIT_RESULTS;

if (!$waitForResults) {
    // 快速路径：PowerShell 脚本启动后 50ms 就返回
    \usleep(50_000);
    // PowerShell 脚本在后台继续运行
} else {
    // 等待路径：块式等待所有进程启动完成（仅在调试时启用）
    self::waitForWindowsBatchCreateHelper($psProcess, $resultPath, \count($batchLaunchItems));
}

// 不读取还未生成的结果文件
if ($waitForResults) {
    $output = self::normalizeWindowsPowerShellPipeOutput(
        (string) (@\file_get_contents((string) $resultPath) ?: '')
    );
} else {
    $output = '';  // 快速返回模式，结果文件稍后生成
}
```

#### 效果
- **非阻塞**：Master 在 batchCreate() 调用后，由 Processer 快速返回（不超过 100ms）
- **后台启动**：PowerShell 脚本在后台继续启动所有子进程
- **PID 获取**：通过 `waitForManagedProcessLaunchBatch()` 轮询进程名称获得 PID（最多 5 秒）

---

### 2. 启动验收完成标志

**文件**：`app/code/Weline/Server/Service/ServiceOrchestrator.php`  
**标志**：`$startupAcceptanceComplete` (第 226 行)

#### 问题
Master 在等待所有子进程上报 READY 时，健康检查和故障拉起逻辑已经开始运行，导致尚在启动中的进程被误判为"宕机"而重复启动。

#### 改进
```php
// 新增标志位
private bool $startupAcceptanceComplete = false;

// 在启动验收成功时设置
protected function waitForStartupAcceptance(array $startupAcceptance, ServiceContext $context): void
{
    // ...
    if ($pending === []) {
        WlsLogger::info_('[Orchestrator] 启动验收通过: 所有关键角色 READY 已达阈值');
        $this->startupAcceptanceComplete = true;  // 设置标志
        return;
    }
}

// 所有周期任务加条件检查
$now = \microtime(true);
if ($this->startupAcceptanceComplete  // ← 只在启动完成后才启动
    && $now - $this->lastHealthCheck >= $this->healthCheckInterval
    && !$this->hasMainLoopTask('periodic:health_checks')
) {
    $this->lastHealthCheck = $now;
    if ($this->scheduleMainLoopTask('periodic:health_checks', ...) {
        // Health check task
    }
}
```

包含以下任务的条件化：
- `performHealthChecks()` - 健康检查
- `reconcileDesiredState()` - HA 协调
- `reconcileWorkerSlotsWithoutHa()` - 非 HA Worker 协调
- `processResurrectQueue()` - 复活队列处理
- `runWorkerLivenessAudit()` - Worker 存活审计
- `cleanupOrphanChildProcesses()` - 孤立进程清理

#### 效果
- **分阶段**：启动阶段 → 验收阶段 → 运行阶段
- **无重复启动**：尚在启动的进程不会被认为是故障

---

### 3. 并发启动逻辑改进

**文件**：`app/code/Weline/Server/Service/ServiceOrchestrator.php`  
**方法**：`startAllChildServicesBody()` (第 1392-1437 行)

#### 问题
并发模式虽然说"一次启动所有进程"，但实际代码还是逐个 Provider 调用启动方法，导致 Provider 之间是串行的（逐个启动）。

#### 改进
```php
if ($concurrentMode) {
    // 合并所有提供程序
    $allProviders = \array_merge($phaseOneProviders, $workerProviders);

    // 一次性通过 startProvidersBatch 启动所有 Provider 的所有实例 - 真正的并发！
    $allInstances = $this->startProvidersBatch($allProviders, $context);

    // ... 结果处理和启动接纳规则配置
}
```

**核心改进**：删除逐个 Provider 循环，直接传入所有 Provider 给 `startProvidersBatch()`，让它一次性构建所有 commands 通过 `Processer::batchCreate()` 并发启动。

#### 执行流程
```
1. 合并所有 Provider (phaseOne + Workers)
2. 调用 startProvidersBatch($allProviders)
3. 内部迭代所有 Provider 和实例数
4. 构建所有 commands 到 $commands[] 数组
5. 调用 Processer::batchCreate($commands) 一次
   ↓ 真正的并发启动！
6. 返回所有启动结果
```

#### 效果
- **真正并发**：所有 Provider 的所有实例通过单次 batchCreate 调用启动
- **启动时间**：显著降低（特别是 Worker 数量多时）
- **架构简洁**：没有隐藏的串行等待

---

## 启动流程时序图

```
Master 启动时序（修复后）
=========================

时刻 0ms       标记："Master 启动开始"
  │
  ├─→ bootstrapControlPlane()
  │    └─ 初始化 IPC 控制面（socket 创建）
  │
  ├─→ cleanupStartupInterferenceProcesses()
  │    └─ 清理旧进程痕迹
  │
  ├─→ startAllChildServices()
  │    ├─ startProvidersBatch(allProviders)
  │    │  ├─ 构建所有 commands
  │    │  └─ Processer::batchCreate(commands)
  │    │      ├─ proc_open(powershell)  [快速返回，50ms]
  │    │      ├─ waitForManagedProcessLaunchBatch()  [轮询 5s]
  │    │      └─ return  [总耗时 < 100ms]
  │    │
  │    ├─ waitForStartupAcceptance()
  │    │  ├─ 等待所有进程上报 READY
  │    │  └─ 设置 startupAcceptanceComplete = true
  │    │
  │    └─ schedulePostStartupHousekeeping()
  │
  ├─→ runLoop()  [主循环开始]
  │    ├─ 初始化 IPC 监听
  │    ├─ mainLoop poll() 循环开始
  │    └─ 周期任务（仅在 startupAcceptanceComplete 时运行）
  │        ├─ performHealthChecks()
  │        ├─ reconcileDesiredState()
  │        └─ ...

Worker 启动时序
================

时刻 T1ms      [Worker 进程启动]
  └─ InstanceInfoGateway::getLatestControlPort()
     ├─ 读取 var/server/instances/master.json
     ├─ 获取最新 control_port
     └─ 连接到 Master 的 controlServer
        └─ SUCCESS! [Master 已在监听]

说明：
- PowerShell 脚本在后台继续启动进程，不阻塞 Master
- Master 立即进入 mainLoop，IPC 控制面已就绪
- Worker 启动时可立即连接，不会被拒绝
```

---

## 配置项

### 1. 并发启动模式

**配置**：`wls.orchestrator.concurrent_startup`  
**类型**：bool  
**默认**：false (向后兼容)  
**说明**：启用后，所有 Provider 的所有实例同时启动

```ini
# weline.env
wls.orchestrator.concurrent_startup=true
```

### 2. Windows 批启动等待

**配置**：`WELINE_BATCH_CREATE_WAIT_RESULTS` (PHP 常量)  
**类型**：bool  
**默认**：false  
**说明**：启用时，batchCreate 会同步等待所有进程启动完成（仅用于调试）

```php
\define('WELINE_BATCH_CREATE_WAIT_RESULTS', true);
```

### 3. 其他相关配置

```ini
# 启动超时 (秒)
wls.orchestrator.startup_timeout_sec=30

# 启动验收最小就绪比例
wls.orchestrator.startup_acceptance_ratio=1.0

# 启动前清理干扰进程
wls.orchestrator.startup_cleanup_interference_processes=true

# 健康检查间隔
wls.orchestrator.health_check_interval_sec=30

# Reconcile 间隔
wls.orchestrator.reconcile_interval_sec=5

# Worker 存活审计间隔
wls.orchestrator.worker_liveness_interval_sec=8
```

---

## 日志输出指标

### 成功启动（新架构）

```
[2026-04-13 11:00:00] [Master:default] [INFO] [Orchestrator] 进入并发启动模式：所有服务同时启动
[2026-04-13 11:00:00] [Master:default] [INFO] [Orchestrator] 启动服务 Dispatcher...
[2026-04-13 11:00:00] [Master:default] [INFO] [Orchestrator] [并发启动] dispatcher ...
[2026-04-13 11:00:00] [Master:default] [INFO] [Orchestrator] 启动服务 Worker...
[2026-04-13 11:00:00] [Master:default] [INFO] [Orchestrator] [并发启动] worker ...

[2026-04-13 11:00:01] [Master:default] [INFO] [Orchestrator] 启动验收中: worker:3/4
[2026-04-13 11:00:02] [Master:default] [INFO] [Orchestrator] 启动验收通过: 所有关键角色 READY 已达阈值
[2026-04-13 11:00:02] [Master:default] [INFO] [Orchestrator] 所有服务启动完成

[2026-04-13 11:00:02] [Orchestrator] 服务器准备就绪
```

### 重点指标

| 指标 | 预期 | 说明 |
|-------|------|------|
| `进入并发启动模式` | 出现一次 | 表示并发模式已启用 |
| `已启动 worker#N` | 所有 Worker 快速输出 | 表示所有 Worker 并发启动，不是逐个 |
| `启动验收通过` | < 5s | 表示子进程正常上报 READY |
| `服务器准备就绪` | 总耗时 < 10s | 表示整个启动流程完成 |

---

## 故障排查

### 症状：Worker 无法连接 Master

**原因诊断**

1. **检查 IPC 控制面初始化**
   ```bash
   ps aux | grep weline-wls-master | head -5
   ```
   Master 进程应该正常运行

2. **检查 Master 是否进入主循环**
   ```bash
   netstat -ano | findstr "26895"  # Windows
   lsof -i :26895                 # Linux
   ```
   应该看到 Master 进程在 26895 端口监听

3. **查看 instance 文件**
   ```bash
   cat var/server/instances/master.json
   ```
   确认 `control_port` 正确

### 症状：进程重复启动

**排查步骤**

1. **查看日志中的 startupAcceptanceComplete 时机**
   - 如果出现多个 `已启动 worker` 日志，说明启动验收还未完成
   - 检查健康检查日志是否在验收前就已开始

2. **验证标志位设置**
   在 ServiceOrchestrator.php 中查看 startupAcceptanceComplete 的使用

### 症状：Master 启动卡顿

**可能原因**

1. **WELINE_BATCH_CREATE_WAIT_RESULTS 被意外启用**
   - 检查代码中是否定义了这个常量
   - 默认应该为 false 或未定义

2. **PowerShell 脚本执行缓慢**
   - 检查系统资源（CPU、内存、磁盘）
   - 在 var/log 中查看详细日志

---

## 向后兼容性

### 保留的特性

- **phased startup 模式**：旧配置 `concurrent_startup=false` 仍然支持
- **Windows 等待模式**：通过 WELINE_BATCH_CREATE_WAIT_RESULTS 启用
- **所有现有配置项**：全部保留，默认值不变

### 破坏性改变

**无**。所有修改都是：
- 完全向后兼容
- 通过配置启用新特性
- 旧系统仍按原方式工作

---

## 测试建议

### 单元测试

1. **ServiceOrchestrator 并发模式**
   ```php
   $context->setConfig('wls.orchestrator.concurrent_startup', true);
   $orchestrator->startAll($context);
   // 验证所有 Provider 的所有实例都已启动
   ```

2. **启动验收完成标志**
   ```php
   $this->assertFalse($orchestrator->startupAcceptanceComplete);
   // ... 启动验收完成后
   $this->assertTrue($orchestrator->startupAcceptanceComplete);
   ```

### 集成测试

1. **启动->连接->运行**
   ```bash
   php bin/w server:start --frontend
   # 等待日志出现 "服务器准备就绪"
   curl http://127.0.0.1:9981/
   # 验证响应 200
   ```

2. **多 Worker 并发启动**
   ```bash
   wls.orchestrator.concurrent_startup=true
   php bin/w server:start --frontend
   # 检查所有 Worker 几乎同时启动
   ```

### 性能测试

在 `dev/ai/scripts/` 下运行性能对比：
```bash
# 旧方式（phased startup）
time php bin/w server:start

# 新方式（concurrent startup）
wls.orchestrator.concurrent_startup=true
time php bin/w server:start
```

预期：并发启动应比分阶段启动快 20-40%。

---

## 相关文档

- [WLS 架构图](WLS架构图.md)
- [WLS 模式部署指南](WLS模式部署指南.md)
- [WLS-Worker 动态扩缩容架构设计](WLS-Worker动态扩缩容架构设计.md)
- [WLS-PORT-CONFLICT-FIX](WLS-PORT-CONFLICT-FIX.md)

---

## 修改历史

| 日期 | 修改 | 作者 |
|-------|------|------|
| 2026-04-13 | 创建文档，记录启动流程优化 | AI Agent |
| 2026-04-07 | 实施 Processer 非阻塞化修复 | AI Agent |
| 2026-04-07 | 实施 startupAcceptanceComplete 标志 | AI Agent |
| 2026-04-07 | 改进并发启动逻辑 | AI Agent |

