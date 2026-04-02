# WLS Worker 动态扩缩容架构设计

## 概述

本文档描述 WelineFramework WLS (Weline Long-running Server) Worker 动态扩缩容的完整架构设计和实现方案。

## 架构分层

```
┌─────────────────────────────────────────────────────────────┐
│                      CLI 层 (用户接口)                        │
│                  Console/Server/Scale.php                    │
└─────────────────────────────────────────────────────────────┘
                              ↓ IPC 命令
┌─────────────────────────────────────────────────────────────┐
│                   Master 控制层 (协调器)                      │
│              Service/ScalingController.php                   │
│         (协调决策器、监控器、生命周期管理器)                    │
└─────────────────────────────────────────────────────────────┘
         ↓ 决策              ↓ 指标              ↓ 启停
┌──────────────────┐  ┌──────────────────┐  ┌──────────────────┐
│   扩容决策器      │  │   负载监控器      │  │  Worker 生命周期  │
│ ScalingDecider   │  │  LoadMonitor     │  │  WorkerScaler    │
│  (算法+配置)     │  │  (指标聚合)      │  │  (启动/停止)     │
└──────────────────┘  └──────────────────┘  └──────────────────┘
                              ↑ load_report
                    ┌──────────────────────┐
                    │   Worker 进程         │
                    │  (定期上报负载指标)    │
                    └──────────────────────┘
```

## 核心模块

### 1. IPC 协议扩展 (ControlMessage.php)

**新增消息类型**：

```php
// Worker 扩缩容消息类型
TYPE_SCALE_WORKERS       // CLI/Master → Master：扩缩容命令
TYPE_WORKER_SCALED       // Master → CLI：扩缩容完成响应
TYPE_LOAD_REPORT         // Worker → Master：负载指标上报
TYPE_GRACEFUL_SHUTDOWN   // Master → Worker：优雅关闭

// CLI 命令动作
ACTION_SCALE_WORKERS     // 手动扩缩容
ACTION_SCALING_STATUS    // 查询扩缩容状态
```

**消息工厂方法**：

```php
ControlMessage::scaleWorkers(int $targetWorkers, array $options = [])
ControlMessage::workerScaled(bool $success, int $currentWorkers, ...)
ControlMessage::loadReport(int $workerId, float $cpuUsage, ...)
ControlMessage::gracefulShutdown(int $timeoutSec = 30)
```

### 2. 负载监控器 (LoadMonitor.php)

**职责**：
- 收集和聚合 Worker 负载指标
- 判断是否需要扩缩容

**指标类型**：
- CPU 使用率（平均值、最大值）
- 内存使用量
- 请求队列长度（总计、最大值）
- 平均响应时间
- 活跃连接数

**核心方法**：

```php
updateMetrics(int $workerId, float $cpuUsage, ...)  // 更新指标
getAggregatedMetrics(): array                       // 获取聚合指标
shouldScaleUp(): bool                               // 是否需要扩容
shouldScaleDown(): bool                             // 是否需要缩容
```

**扩容条件**（满足任一）：
- 平均 CPU > 80%
- 最大 CPU > 90%
- 总队列 > 10 * Worker 数
- 最大队列 > 20

**缩容条件**（同时满足）：
- 平均 CPU < 30%
- 最大 CPU < 50%
- 总队列 < 2 * Worker 数
- Worker 数 > 1

### 3. 扩容决策器 (ScalingDecider.php)

**职责**：
- 根据负载指标和配置决定目标 Worker 数
- 管理冷却期，防止频繁扩缩容

**配置项**（从 `etc/env.php` 读取）：

```php
'wls' => [
    'scaling' => [
        'enabled' => false,                    // 是否启用自动扩缩容
        'min_workers' => 1,                    // 最小 Worker 数
        'max_workers' => 4,                    // 最大 Worker 数（默认 CPU 核心数 * 2）
        'scale_up_threshold_cpu' => 80.0,     // 扩容 CPU 阈值
        'scale_down_threshold_cpu' => 30.0,   // 缩容 CPU 阈值
        'scale_up_threshold_queue' => 10,     // 扩容队列阈值
        'cooldown_seconds' => 60,             // 冷却期（秒）
    ],
],
```

**核心方法**：

```php
decide(array $metrics, int $currentWorkers): ?int  // 决策目标 Worker 数
isEnabled(): bool                                  // 是否启用自动扩缩容
getMinWorkers(): int                               // 获取最小 Worker 数
getMaxWorkers(): int                               // 获取最大 Worker 数
```

**扩缩容策略**：
- 扩容：每次 +1
- 缩容：每次 -1
- 冷却期：默认 60 秒，防止抖动

### 4. Worker 生命周期管理器 (WorkerScaler.php)

**职责**：
- 平滑启动新 Worker
- 优雅关闭旧 Worker
- 健康检查

**核心方法**：

```php
scaleUp(int $count, ServiceContext $context): array    // 扩容
scaleDown(int $count): array                           // 缩容
checkHealth(int $pid): bool                            // 健康检查
getCurrentWorkerCount(): int                           // 获取当前 Worker 数
```

**扩容流程**：
1. 构建启动命令（`WorkerProvider::buildCommand()`）
2. 启动 Worker 进程（`ServiceOrchestrator::startInstance()`）
3. 等待 Worker 注册（超时 10 秒）
4. 验证 Worker 状态为 `STATE_READY`
5. 返回新增的 PID 列表

**缩容流程**：
1. 选择要停止的 Worker（优先选择 ID 最大的）
2. 发送 `graceful_shutdown` IPC 消息
3. 等待 Worker 退出（超时 30 秒）
4. 超时则强制 kill
5. 返回移除的 PID 列表

### 5. 扩缩容控制器 (ScalingController.php)

**职责**：
- 协调负载监控、扩容决策和 Worker 生命周期管理
- 处理手动扩缩容命令
- 自动扩缩容（定期检查）
- 并发安全（文件锁）

**核心方法**：

```php
handleScaleCommand(int $targetWorkers): array  // 处理手动扩缩容命令
autoScale(): ?array                            // 自动扩缩容
getStatus(): array                             // 获取扩缩容状态
```

**并发安全**：
- 使用文件锁 `var/wls/scaling.lock` 防止多个扩容命令同时执行
- 手动扩缩容：阻塞等待锁
- 自动扩缩容：非阻塞尝试获取锁，失败则跳过本次

### 6. CLI 命令 (Console/Server/Scale.php)

**命令签名**：

```bash
php bin/w server:scale [options]
```

**选项**：

```
--workers=N    设置目标 Worker 数（手动扩缩容）
--auto         启用自动扩缩容
--no-auto      禁用自动扩缩容
--min=N        设置最小 Worker 数
--max=N        设置最大 Worker 数
--status       显示扩缩容状态
```

**使用示例**：

```bash
# 手动扩容到 4 个 Worker
php bin/w server:scale --workers=4

# 启用自动扩缩容，最小 2 个，最大 8 个
php bin/w server:scale --auto --min=2 --max=8

# 查看扩缩容状态
php bin/w server:scale --status
```

## 集成点

### 1. MasterControlServer (IPC 消息处理)

需要在 `onMessage` 回调中处理以下消息：

```php
// 处理 load_report 消息（Worker → Master）
case ControlMessage::TYPE_LOAD_REPORT:
    $loadMonitor->updateMetrics(
        $msg['worker_id'],
        $msg['cpu_usage'],
        $msg['memory_usage'],
        $msg['queue_length'],
        $msg['avg_response_time'],
        $msg['active_connections']
    );
    break;

// 处理 scale_workers 命令（CLI → Master）
case ControlMessage::TYPE_COMMAND:
    if ($msg['action'] === ControlMessage::ACTION_SCALE_WORKERS) {
        $targetWorkers = $msg['target_workers'] ?? 0;
        $result = $scalingController->handleScaleCommand($targetWorkers);
        $response = ControlMessage::workerScaled(
            $result['success'],
            $result['current_workers'],
            $result['target_workers'],
            $result['added_pids'],
            $result['removed_pids'],
            $result['message']
        );
        $this->sendToClient($clientId, $response);
    }
    break;

// 处理 scaling_status 查询（CLI → Master）
case ControlMessage::ACTION_SCALING_STATUS:
    $status = $scalingController->getStatus();
    $response = ControlMessage::commandResult(true, $status);
    $this->sendToClient($clientId, $response);
    break;
```

### 2. ServiceOrchestrator (定期自动扩缩容)

在 Master 主循环中定期调用自动扩缩容：

```php
// Master 主循环
while ($this->running) {
    // ... 现有逻辑 ...

    // 每 30 秒检查一次自动扩缩容
    if (time() - $lastAutoScaleTime >= 30) {
        $result = $scalingController->autoScale();
        if ($result !== null) {
            $logger->info("Auto-scaling: {$result['message']}");
        }
        $lastAutoScaleTime = time();
    }

    // ... 现有逻辑 ...
}
```

### 3. WorkerProvider (负载指标上报)

Worker 进程需要定期上报负载指标：

```php
// Worker 主循环
while ($running) {
    // ... 处理请求 ...

    // 每 10 秒上报一次负载指标
    if (microtime(true) - $lastReportTime >= 10.0) {
        $cpuUsage = $this->getCpuUsage();
        $memoryUsage = memory_get_usage(true);
        $queueLength = $this->getQueueLength();
        $avgResponseTime = $this->getAvgResponseTime();
        $activeConnections = $this->getActiveConnections();

        $message = ControlMessage::loadReport(
            $workerId,
            $cpuUsage,
            $memoryUsage,
            $queueLength,
            $avgResponseTime,
            $activeConnections
        );

        $this->sendToMaster($message);
        $lastReportTime = microtime(true);
    }
}
```

### 4. Dispatcher 路由更新

Dispatcher 需要动态更新 Worker 池：

```php
// 新 Worker 注册时
case ControlMessage::TYPE_REGISTER:
    if ($msg['role'] === 'worker') {
        $port = $msg['port'];
        $this->addWorkerToPool($port);
    }
    break;

// Worker 断开时
case ControlMessage::TYPE_EXITED:
    if ($msg['role'] === 'worker') {
        $port = $msg['port'];
        $this->removeWorkerFromPool($port);
    }
    break;
```

## 测试用例

### 1. 手动扩容测试

```bash
# 启动服务器（2 个 Worker）
php bin/w server:start --workers=2

# 扩容到 4 个
php bin/w server:scale --workers=4

# 验证：ps aux | grep weline-wls-worker
# 应该看到 4 个 Worker 进程
```

### 2. 手动缩容测试

```bash
# 缩容到 2 个
php bin/w server:scale --workers=2

# 验证：ps aux | grep weline-wls-worker
# 应该看到 2 个 Worker 进程
```

### 3. 自动扩容测试

```bash
# 启用自动扩缩容
# 编辑 app/etc/env.php，设置 wls/scaling/enabled = true

# 重启服务器
php bin/w server:restart -r

# 模拟高负载（发送大量请求）
ab -n 10000 -c 100 http://localhost/

# 查看状态
php bin/w server:scale --status

# 应该看到 Worker 数自动增加
```

### 4. 自动缩容测试

```bash
# 停止负载

# 等待 60 秒（冷却期）

# 查看状态
php bin/w server:scale --status

# 应该看到 Worker 数自动减少
```

### 5. 并发安全测试

```bash
# 同时发送多个扩容命令
php bin/w server:scale --workers=4 &
php bin/w server:scale --workers=5 &
php bin/w server:scale --workers=6 &

# 验证：只有一个命令执行成功，其他返回 "Another scaling operation is in progress"
```

## 性能考虑

1. **负载指标收集开销**：
   - Worker 每 10 秒上报一次，避免频繁 IPC 通信
   - 指标计算使用滑动窗口，避免全量统计

2. **扩缩容决策开销**：
   - 冷却期 60 秒，避免频繁扩缩容
   - 每次只扩缩容 1 个 Worker，避免大幅波动

3. **并发安全开销**：
   - 使用文件锁，开销极小
   - 自动扩缩容使用非阻塞锁，避免阻塞主循环

4. **Worker 启动开销**：
   - 平滑启动，等待注册后才加入路由池
   - 启动超时 10 秒，避免长时间阻塞

5. **Worker 停止开销**：
   - 优雅关闭，等待请求处理完成
   - 停止超时 30 秒，超时则强制 kill

## 安全考虑

1. **最小/最大 Worker 数限制**：
   - 防止缩容到 0 个 Worker
   - 防止扩容超过系统资源限制

2. **并发安全**：
   - 文件锁防止多个扩容命令同时执行
   - 避免竞态条件导致 Worker 数异常

3. **优雅关闭**：
   - 等待请求处理完成，避免请求丢失
   - 超时强制 kill，避免僵尸进程

4. **健康检查**：
   - 定期检查 Worker 健康状态
   - 不健康的 Worker 自动重启

## 未来优化

1. **更智能的扩缩容算法**：
   - 基于机器学习预测负载趋势
   - 根据历史数据动态调整阈值

2. **更细粒度的负载指标**：
   - 按路由统计负载
   - 按用户统计负载

3. **更灵活的扩缩容策略**：
   - 支持按时间段配置不同的 Worker 数
   - 支持按负载类型配置不同的扩缩容策略

4. **更完善的监控和告警**：
   - 集成 Prometheus/Grafana
   - 扩缩容事件告警

## 总结

本架构设计实现了 WLS Worker 的动态扩缩容功能，支持手动和自动两种模式，具备以下特点：

- **平滑扩容**：新 Worker 无缝加入，无请求丢失
- **安全缩容**：等待请求处理完成，优雅退出
- **智能决策**：基于负载指标自动扩缩容
- **并发安全**：文件锁防止竞态条件
- **易于使用**：CLI 命令简单直观
- **高性能**：低开销，不影响主业务

---

**文件清单**：

1. `app/code/Weline/Server/IPC/ControlMessage.php` - IPC 协议扩展
2. `app/code/Weline/Server/Service/LoadMonitor.php` - 负载监控器
3. `app/code/Weline/Server/Service/ScalingDecider.php` - 扩容决策器
4. `app/code/Weline/Server/Service/WorkerScaler.php` - Worker 生命周期管理器
5. `app/code/Weline/Server/Service/ScalingController.php` - 扩缩容控制器
6. `app/code/Weline/Server/Console/Server/Scale.php` - CLI 命令

**集成点**：

1. `MasterControlServer::onMessage()` - 处理 IPC 消息
2. `ServiceOrchestrator` 主循环 - 定期自动扩缩容
3. `WorkerProvider` 主循环 - 上报负载指标
4. `DispatcherProvider` - 动态更新 Worker 池
