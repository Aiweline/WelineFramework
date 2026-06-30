# WLS 重载后 Worker 池同步失败架构方案

> **状态：历史归档（已被取代）。** 本文档描述的"带 ACK 的 ADD_WORKER 推送 + 三层同步保障"方案是早期设计稿。
> 当前实际链路已演进为 **Master Registry 单一事实源 + 版本化全量路由表（SET_ROUTE_TABLE / ROUTE_TABLE_ACK / ROUTE_OBSERVATION）**，
> 由 `ServiceOrchestrator::convergeDispatcherRouteTableAfterWorkerReady` + `syncDispatcherFullWorkerPoolFromRegistry` 收敛；
> 文档中出现的 `notifyDispatcherWorkerReadyWithAck`、`ControlMessage::addWorker` 等示例符号已不存在于生产代码。
> 仅保留作设计思路追溯。

## 问题诊断

### 现象
执行 `php bin/w server:reload` 后：
- Master 日志显示：`Batch 2/2: workers [2] are READY, rejoining dispatcher`
- 但实际无法访问（Dispatcher 端口 9981 连接失败）
- Worker 进程存在且状态为 READY，但 Dispatcher 未识别

### 根本原因
1. **IPC 消息丢失风险**：滚动重启过程中通过 `ADD_WORKER`/`REMOVE_WORKER` 逐条更新 Dispatcher，若 IPC 消息丢包或顺序错乱，会导致 Dispatcher 内部状态与 Master Registry 漂移
2. **同步时机问题**：`syncDispatcherFullWorkerPoolFromRegistry()` 在批次完成后调用，但可能因为：
   - Dispatcher IPC 连接状态异常
   - 消息发送后未确认送达
   - Dispatcher 处理消息时内部状态锁定
3. **缺少确认机制**：Master 发送 `SET_WORKER_POOL` 后没有等待 Dispatcher 的 ACK，无法确认同步成功

---

## 架构方案：三层同步保障机制

### 1. 实时同步层（Primary）：带 ACK 的 Worker 池更新

#### 设计原则
- 每次 Worker 状态变更后，Master 必须等待 Dispatcher 确认收到并应用
- 超时未收到 ACK 则重试，最多 3 次
- 失败后触发全量同步

#### 实现要点
```php
// ServiceOrchestrator.php

private array $pendingWorkerPoolSync = [
    'request_id' => null,
    'expected_dispatchers' => [],  // [dispatcherId => true]
    'acked_dispatchers' => [],     // [dispatcherId => true]
    'sent_at' => 0.0,
    'retry_count' => 0,
];

private function notifyDispatcherWorkerReadyWithAck(ServiceInstance $worker): bool
{
    $requestId = $this->generateRequestId();
    $msg = ControlMessage::addWorker([$worker->port], $requestId);
    
    $dispatchers = $this->registry->getInstancesByRole('dispatcher');
    $this->pendingWorkerPoolSync = [
        'request_id' => $requestId,
        'expected_dispatchers' => array_fill_keys(
            array_map(fn($d) => $d->instanceId, $dispatchers), 
            true
        ),
        'acked_dispatchers' => [],
        'sent_at' => microtime(true),
        'retry_count' => 0,
    ];
    
    foreach ($dispatchers as $dispatcher) {
        if ($dispatcher->ipcClientId !== null) {
            $this->controlServer->sendTo($dispatcher->ipcClientId, $msg);
        }
    }
    
    // 等待 ACK（超时 2 秒）
    return $this->waitForWorkerPoolSyncAck(2.0);
}

private function waitForWorkerPoolSyncAck(float $timeout): bool
{
    $deadline = microtime(true) + $timeout;
    
    while (microtime(true) < $deadline) {
        $this->controlServer->poll(0, 50000);
        
        if ($this->pendingWorkerPoolSync['expected_dispatchers'] === 
            $this->pendingWorkerPoolSync['acked_dispatchers']) {
            return true;
        }
        
        usleep(50000);
    }
    
    // 超时，记录未响应的 Dispatcher
    $missing = array_diff_key(
        $this->pendingWorkerPoolSync['expected_dispatchers'],
        $this->pendingWorkerPoolSync['acked_dispatchers']
    );
    
    WlsLogger::warning_(
        '[Orchestrator] Worker 池同步 ACK 超时，未响应 Dispatcher: ' . 
        implode(',', array_keys($missing))
    );
    
    return false;
}

// 在 IPC 消息处理中添加 ACK 处理
private function handleWorkerPoolSyncAck(array $message, int $clientId): void
{
    $requestId = $message['request_id'] ?? null;
    $dispatcherId = $message['dispatcher_id'] ?? null;
    
    if ($requestId === $this->pendingWorkerPoolSync['request_id']) {
        $this->pendingWorkerPoolSync['acked_dispatchers'][$dispatcherId] = true;
    }
}
```

#### Dispatcher 端修改
```php
// Dispatcher.php

private function handleAddWorker(array $message): void
{
    $ports = $message['ports'] ?? [];
    $requestId = $message['request_id'] ?? null;
    
    foreach ($ports as $port) {
        $this->workerPool->addWorker($port);
    }
    
    // 发送 ACK
    if ($requestId !== null) {
        $this->sendToMaster(ControlMessage::workerPoolSyncAck(
            $requestId, 
            $this->instanceId
        ));
    }
}
```

---

### 2. 周期校验层（Secondary）：心跳式状态对齐

#### 设计原则
- Master 每 5 秒向 Dispatcher 查询当前 Worker 池状态
- 对比 Registry 中的 READY Worker，发现不一致则触发全量同步
- 作为实时同步的兜底机制

#### 实现要点
```php
// ServiceOrchestrator.php

private float $lastWorkerPoolHealthCheck = 0.0;
private float $workerPoolHealthCheckInterval = 5.0;

private function periodicWorkerPoolHealthCheck(): void
{
    $now = microtime(true);
    if ($now - $this->lastWorkerPoolHealthCheck < $this->workerPoolHealthCheckInterval) {
        return;
    }
    
    $this->lastWorkerPoolHealthCheck = $now;
    
    $dispatchers = $this->registry->getInstancesByRole('dispatcher');
    if (empty($dispatchers)) {
        return;
    }
    
    // 查询 Dispatcher 当前 Worker 池
    $requestId = $this->generateRequestId();
    $msg = ControlMessage::queryWorkerPool($requestId);
    
    foreach ($dispatchers as $dispatcher) {
        if ($dispatcher->ipcClientId !== null) {
            $this->controlServer->sendTo($dispatcher->ipcClientId, $msg);
        }
    }
    
    // 等待响应并对比
    $this->waitForWorkerPoolQueryResponse($requestId, 1.0);
}

private function waitForWorkerPoolQueryResponse(string $requestId, float $timeout): void
{
    $deadline = microtime(true) + $timeout;
    $responses = [];
    
    while (microtime(true) < $deadline) {
        $this->controlServer->poll(0, 50000);
        
        // 收集响应（在 IPC 处理中填充 $this->workerPoolQueryResponses）
        if (isset($this->workerPoolQueryResponses[$requestId])) {
            $responses = $this->workerPoolQueryResponses[$requestId];
            break;
        }
        
        usleep(50000);
    }
    
    // 对比 Registry 与 Dispatcher 状态
    $expectedPorts = [];
    foreach ($this->registry->getInstancesByRole('worker') as $w) {
        if ($w->state === ServiceInstance::STATE_READY && $w->port > 0) {
            $expectedPorts[] = $w->port;
        }
    }
    sort($expectedPorts);
    
    foreach ($responses as $dispatcherId => $actualPorts) {
        sort($actualPorts);
        if ($expectedPorts !== $actualPorts) {
            WlsLogger::warning_(
                "[Orchestrator] Dispatcher#{$dispatcherId} Worker 池漂移检测到，" .
                "期望: " . implode(',', $expectedPorts) . " " .
                "实际: " . implode(',', $actualPorts) . " " .
                "触发全量同步"
            );
            $this->syncDispatcherFullWorkerPoolFromRegistry();
            break;
        }
    }
}
```

---

### 3. 强制同步层（Tertiary）：重载完成后的确认性同步

#### 设计原则
- 每次滚动重启/重载完成后，强制执行全量同步
- 同步后验证 Dispatcher 是否可访问（健康检查）
- 失败则重试，最多 3 次

#### 实现要点
```php
// ServiceOrchestrator.php

private function gracefulReloadWorkersWithDispatcherBatches(
    ServiceProviderInterface $provider,
    array $instances,
    string $type,
    ?int $imperialEpochSnap,
): void {
    // ... 现有批次重载逻辑 ...
    
    // 批次完成后强制全量同步
    $syncSuccess = $this->syncDispatcherFullWorkerPoolFromRegistryWithRetry(3);
    
    if (!$syncSuccess) {
        WlsLogger::error_('[Orchestrator] 重载完成但 Dispatcher 同步失败');
        if ($this->rollingRestartClientId !== null) {
            $this->controlServer->sendTo(
                $this->rollingRestartClientId,
                ControlMessage::reloadFailed('Dispatcher sync failed after reload')
            );
        }
        return;
    }
    
    // 验证 Dispatcher 可访问性
    if (!$this->verifyDispatcherAccessibility()) {
        WlsLogger::error_('[Orchestrator] 重载完成但 Dispatcher 不可访问');
        if ($this->rollingRestartClientId !== null) {
            $this->controlServer->sendTo(
                $this->rollingRestartClientId,
                ControlMessage::reloadFailed('Dispatcher not accessible after reload')
            );
        }
        return;
    }
    
    // 成功
    if ($this->rollingRestartClientId !== null) {
        $elapsedMs = (microtime(true) - $startTime) * 1000;
        $this->controlServer->sendTo(
            $this->rollingRestartClientId, 
            ControlMessage::reloadCompleted($elapsedMs, $done)
        );
    }
}

private function syncDispatcherFullWorkerPoolFromRegistryWithRetry(int $maxRetries): bool
{
    for ($i = 0; $i < $maxRetries; $i++) {
        $this->syncDispatcherFullWorkerPoolFromRegistry();
        
        // 等待 150ms 让 Dispatcher 处理
        usleep(150000);
        
        // 验证同步结果
        if ($this->verifyDispatcherWorkerPoolSync()) {
            return true;
        }
        
        WlsLogger::warning_(
            "[Orchestrator] Dispatcher 全量同步验证失败，重试 " . ($i + 1) . "/{$maxRetries}"
        );
        usleep(500000); // 重试前等待 500ms
    }
    
    return false;
}

private function verifyDispatcherWorkerPoolSync(): bool
{
    $requestId = $this->generateRequestId();
    $msg = ControlMessage::queryWorkerPool($requestId);
    
    $dispatchers = $this->registry->getInstancesByRole('dispatcher');
    foreach ($dispatchers as $dispatcher) {
        if ($dispatcher->ipcClientId !== null) {
            $this->controlServer->sendTo($dispatcher->ipcClientId, $msg);
        }
    }
    
    // 等待响应
    $deadline = microtime(true) + 1.0;
    while (microtime(true) < $deadline) {
        $this->controlServer->poll(0, 50000);
        
        if (isset($this->workerPoolQueryResponses[$requestId])) {
            $responses = $this->workerPoolQueryResponses[$requestId];
            
            // 验证所有 Dispatcher 的 Worker 池是否一致
            $expectedPorts = [];
            foreach ($this->registry->getInstancesByRole('worker') as $w) {
                if ($w->state === ServiceInstance::STATE_READY && $w->port > 0) {
                    $expectedPorts[] = $w->port;
                }
            }
            sort($expectedPorts);
            
            foreach ($responses as $actualPorts) {
                sort($actualPorts);
                if ($expectedPorts !== $actualPorts) {
                    return false;
                }
            }
            
            return true;
        }
        
        usleep(50000);
    }
    
    return false;
}

private function verifyDispatcherAccessibility(): bool
{
    $dispatchers = $this->registry->getInstancesByRole('dispatcher');
    if (empty($dispatchers)) {
        return false;
    }
    
    foreach ($dispatchers as $dispatcher) {
        if ($dispatcher->port === null || $dispatcher->port <= 0) {
            continue;
        }
        
        // 尝试连接 Dispatcher 端口
        $socket = @fsockopen('127.0.0.1', $dispatcher->port, $errno, $errstr, 1.0);
        if ($socket === false) {
            WlsLogger::warning_(
                "[Orchestrator] Dispatcher#{$dispatcher->instanceId} 端口 {$dispatcher->port} 不可访问: {$errstr}"
            );
            return false;
        }
        fclose($socket);
    }
    
    return true;
}
```

---

## 新增 IPC 消息类型

```php
// ControlMessage.php

public const ACTION_WORKER_POOL_SYNC_ACK = 'worker_pool_sync_ack';
public const ACTION_QUERY_WORKER_POOL = 'query_worker_pool';
public const ACTION_WORKER_POOL_STATUS = 'worker_pool_status';

public static function workerPoolSyncAck(string $requestId, int $dispatcherId): array
{
    return [
        'action' => self::ACTION_WORKER_POOL_SYNC_ACK,
        'request_id' => $requestId,
        'dispatcher_id' => $dispatcherId,
        'timestamp' => microtime(true),
    ];
}

public static function queryWorkerPool(string $requestId): array
{
    return [
        'action' => self::ACTION_QUERY_WORKER_POOL,
        'request_id' => $requestId,
        'timestamp' => microtime(true),
    ];
}

public static function workerPoolStatus(string $requestId, array $ports): array
{
    return [
        'action' => self::ACTION_WORKER_POOL_STATUS,
        'request_id' => $requestId,
        'ports' => $ports,
        'timestamp' => microtime(true),
    ];
}
```

---

## 实施优先级

### Phase 1（立即修复）：强制同步层
- 在 `gracefulReloadWorkersWithDispatcherBatches()` 结束时添加重试机制
- 添加 Dispatcher 可访问性验证
- **预计修复 90% 的重载失败问题**

### Phase 2（短期优化）：周期校验层
- 添加 5 秒心跳式状态对齐
- 实现 `QUERY_WORKER_POOL` 和 `WORKER_POOL_STATUS` 消息
- **提供持续的状态监控和自动修复**

### Phase 3（长期完善）：实时同步层
- 为所有 Worker 池变更添加 ACK 机制
- 实现超时重试逻辑
- **彻底消除 IPC 消息丢失风险**

---

## 配置项

```php
// env.php
'wls' => [
    'orchestrator' => [
        // Worker 池同步 ACK 超时（秒）
        'worker_pool_sync_timeout' => 2.0,
        
        // Worker 池同步最大重试次数
        'worker_pool_sync_max_retries' => 3,
        
        // Worker 池健康检查间隔（秒）
        'worker_pool_health_check_interval' => 5.0,
        
        // 重载完成后强制同步重试次数
        'reload_sync_max_retries' => 3,
    ],
],
```

---

## 监控指标

### 新增日志
- `[Orchestrator] Worker 池同步 ACK 超时`
- `[Orchestrator] Dispatcher Worker 池漂移检测到`
- `[Orchestrator] Dispatcher 全量同步验证失败`
- `[Orchestrator] Dispatcher 不可访问`

### 新增指标
- `wls.orchestrator.worker_pool_sync_timeout_count`
- `wls.orchestrator.worker_pool_drift_detected_count`
- `wls.orchestrator.dispatcher_inaccessible_count`

---

## 回滚方案

如果新机制导致问题，可通过配置禁用：

```php
'wls' => [
    'orchestrator' => [
        'enable_worker_pool_ack' => false,           // 禁用 ACK 机制
        'enable_worker_pool_health_check' => false,  // 禁用周期校验
        'enable_reload_sync_verify' => false,        // 禁用重载后验证
    ],
],
```
