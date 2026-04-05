# WLS 零停机滚动重启方案

## 问题分析

### 当前流程（服务中断）
```
Batch 1: [Worker 1,2]
  1. 从 Dispatcher 移除 [1,2]  ← 流量中断开始
  2. 排水 [1,2]
  3. 停止 [1,2]
  4. 启动 [1,2]
  5. 等待 [1,2] READY
  6. 加入 Dispatcher [1,2]     ← 流量恢复
  
Batch 2: [Worker 3,4]
  （重复上述流程）
```

**问题**：步骤 3-5 期间，该批次 Worker 完全不可用，服务中断。

### 目标流程（零停机）
```
Batch 1: [Worker 1,2]
  1. 启动新 Worker [101,102]  ← 与旧 [1,2] 并存
  2. 等待 [101,102] READY
  3. 加入 Dispatcher [101,102] ← 新 Worker 开始分流
  4. 从 Dispatcher 移除 [1,2]
  5. 排水 [1,2]
  6. 停止 [1,2]
  7. (可选) 将 [101,102] 重命名为 [1,2]
  
Batch 2: [Worker 3,4]
  （重复上述流程）
```

**优势**：全程至少有一组 Worker 在处理请求，无服务中断。

## 技术方案

### 1. 临时 Worker ID 分配

#### 1.1 ID 范围规划
```php
// ServiceOrchestrator.php
private const TEMP_WORKER_ID_OFFSET = 100;  // 临时 ID 起始偏移

/**
 * 为滚动重启分配临时 Worker ID
 * 
 * @param int $originalId 原始 Worker ID (1-N)
 * @return int 临时 ID (101-1xx)
 */
private function allocateTempWorkerId(int $originalId): int
{
    return $originalId + self::TEMP_WORKER_ID_OFFSET;
}
```

#### 1.2 端口分配
```php
// WorkerProvider.php
public function getPort(int $instanceId, ServiceContext $context): ?int
{
    // 临时 Worker 使用动态端口（避免与原 Worker 冲突）
    if ($instanceId > 100) {
        return $this->basePort + $instanceId;  // 例如：9601, 9602...
    }
    
    // 正常 Worker 使用固定端口
    return $this->basePort + $instanceId;  // 例如：9501, 9502...
}
```

### 2. 零停机重启流程

#### 2.1 核心方法重构
```php
/**
 * 零停机滚动重启单个批次
 * 
 * @param int[] $instanceIds 要重启的 Worker ID（如 [1,2]）
 * @return string 'ok'|'failed'|'aborted'
 */
private function restartWorkerBatchZeroDowntime(
    array $instanceIds,
    ?int $imperialEpochSnap,
    int $completedBefore = 0,
    int $totalWorkers = 0,
    int $batchIndex = 0,
    int $batchTotal = 0
): string {
    $workerProvider = $this->registry->getProvider('worker');
    if ($workerProvider === null) {
        return 'failed';
    }
    
    $batchLabel = "Batch {$batchIndex}/{$batchTotal}";
    $oldIds = $instanceIds;  // [1, 2]
    $tempIds = array_map([$this, 'allocateTempWorkerId'], $oldIds);  // [101, 102]
    
    // === 阶段 1: 启动新 Worker（临时 ID） ===
    $this->sendReloadProgressMessage(
        "{$batchLabel}: starting new workers " . json_encode($tempIds) . " (temp IDs)",
        $completedBefore,
        $totalWorkers,
        'starting_new',
        $tempIds[0] ?? 0,
        ['old_ids' => $oldIds, 'temp_ids' => $tempIds]
    );
    
    if (!$this->waitForWorkerCriticalInfraReady("start temp workers")) {
        return 'failed';
    }
    
    $this->startInstanceIdsBatch($workerProvider, $tempIds, $this->context);
    
    // === 阶段 2: 等待新 Worker READY ===
    $this->sendReloadProgressMessage(
        "{$batchLabel}: waiting new workers " . json_encode($tempIds) . " READY",
        $completedBefore,
        $totalWorkers,
        'waiting_new_ready',
        $tempIds[0] ?? 0,
        ['old_ids' => $oldIds, 'temp_ids' => $tempIds]
    );
    
    $readyDeadline = microtime(true) + $this->startupTimeout + 20.0;
    $allReady = false;
    
    while (microtime(true) < $readyDeadline) {
        if ($imperialEpochSnap !== null && $this->ipcImperialEpoch !== $imperialEpochSnap) {
            // 清理临时 Worker
            $this->cleanupTempWorkers($tempIds);
            return 'aborted';
        }
        
        $allReady = true;
        foreach ($tempIds as $tempId) {
            $w = $this->registry->getInstance('worker', $tempId);
            if ($w === null || $w->state !== ServiceInstance::STATE_READY) {
                $allReady = false;
                break;
            }
        }
        
        if ($allReady) {
            break;
        }
        
        $this->controlServer?->poll(0, 100000);
        SchedulerSystem::usleep(100000);
    }
    
    if (!$allReady) {
        $this->cleanupTempWorkers($tempIds);
        WlsLogger::error_("[Orchestrator] 新 Worker " . json_encode($tempIds) . " 未能在超时内 READY");
        return 'failed';
    }
    
    // === 阶段 3: 新 Worker 加入 Dispatcher ===
    $this->sendReloadProgressMessage(
        "{$batchLabel}: adding new workers " . json_encode($tempIds) . " to dispatcher",
        $completedBefore,
        $totalWorkers,
        'adding_new_to_dispatcher',
        $tempIds[0] ?? 0,
        ['old_ids' => $oldIds, 'temp_ids' => $tempIds]
    );
    
    foreach ($tempIds as $tempId) {
        $newWorker = $this->registry->getInstance('worker', $tempId);
        if ($newWorker !== null && !$this->maintenanceMode) {
            $this->notifyDispatcherWorkerReady($newWorker);
        }
    }
    
    // 等待 Dispatcher 确认接收新 Worker
    $this->controlServer?->poll(0, 100000);
    SchedulerSystem::usleep(100000);
    
    // === 阶段 4: 从 Dispatcher 移除旧 Worker ===
    $this->sendReloadProgressMessage(
        "{$batchLabel}: removing old workers " . json_encode($oldIds) . " from dispatcher",
        $completedBefore,
        $totalWorkers,
        'removing_old_from_dispatcher',
        $oldIds[0] ?? 0,
        ['old_ids' => $oldIds, 'temp_ids' => $tempIds]
    );
    
    foreach ($oldIds as $oldId) {
        $oldWorker = $this->registry->getInstance('worker', $oldId);
        if ($oldWorker !== null) {
            $this->notifyDispatcherRemoveWorker($oldWorker->port);
        }
    }
    
    $this->controlServer?->poll(0, 80000);
    SchedulerSystem::usleep(80000);
    
    // === 阶段 5: 排水旧 Worker ===
    $this->sendReloadProgressMessage(
        "{$batchLabel}: draining old workers " . json_encode($oldIds),
        $completedBefore,
        $totalWorkers,
        'draining_old',
        $oldIds[0] ?? 0,
        ['old_ids' => $oldIds, 'temp_ids' => $tempIds]
    );
    
    foreach ($oldIds as $oldId) {
        $oldWorker = $this->registry->getInstance('worker', $oldId);
        if ($oldWorker !== null && $oldWorker->ipcClientId !== null) {
            $this->sendDrainToInstance($oldWorker);
        }
    }
    
    // 等待排水完成（简化版，完整实现参考原 restartWorkerBatchDispatcherAware）
    $drainDeadline = microtime(true) + $this->drainTimeout;
    while (microtime(true) < $drainDeadline) {
        if ($imperialEpochSnap !== null && $this->ipcImperialEpoch !== $imperialEpochSnap) {
            return 'aborted';
        }
        
        $allDrained = true;
        foreach ($oldIds as $oldId) {
            $oldWorker = $this->registry->getInstance('worker', $oldId);
            if ($oldWorker !== null && $oldWorker->state === ServiceInstance::STATE_DRAINING) {
                $allDrained = false;
                break;
            }
        }
        
        if ($allDrained) {
            break;
        }
        
        $this->controlServer?->poll(0, 100000);
        SchedulerSystem::usleep(100000);
    }
    
    // === 阶段 6: 停止旧 Worker ===
    $this->sendReloadProgressMessage(
        "{$batchLabel}: stopping old workers " . json_encode($oldIds),
        $completedBefore,
        $totalWorkers,
        'stopping_old',
        $oldIds[0] ?? 0,
        ['old_ids' => $oldIds, 'temp_ids' => $tempIds]
    );
    
    foreach ($oldIds as $oldId) {
        $oldWorker = $this->registry->getInstance('worker', $oldId);
        if ($oldWorker !== null) {
            $this->stopInstance($oldWorker);
        }
    }
    
    // 等待旧 Worker 退出
    $exitDeadline = microtime(true) + 15.0;
    while (microtime(true) < $exitDeadline) {
        $allGone = true;
        foreach ($oldIds as $oldId) {
            $w = $this->registry->getInstance('worker', $oldId);
            if ($w !== null && $w->ipcClientId !== null) {
                $allGone = false;
                break;
            }
        }
        if ($allGone) {
            break;
        }
        $this->controlServer?->poll(0, 100000);
        SchedulerSystem::usleep(100000);
    }
    
    // 清理旧 Worker
    foreach ($oldIds as $oldId) {
        $oldWorker = $this->registry->getInstance('worker', $oldId);
        if ($oldWorker !== null) {
            $this->registry->removeInstance('worker', $oldId);
            $this->cleanupInstancePidFile($oldWorker);
        }
    }
    
    // === 阶段 7 (可选): 将临时 ID 重命名为原 ID ===
    // 注意：这需要修改 registry 的 key，可能比较复杂
    // 简化方案：保持临时 ID，下次重启时再回收
    
    WlsLogger::info_(
        "[Orchestrator] {$batchLabel} 零停机重启完成：旧 " . json_encode($oldIds) . 
        " → 新 " . json_encode($tempIds)
    );
    
    return 'ok';
}

/**
 * 清理临时 Worker（失败时回滚）
 */
private function cleanupTempWorkers(array $tempIds): void
{
    foreach ($tempIds as $tempId) {
        $tempWorker = $this->registry->getInstance('worker', $tempId);
        if ($tempWorker !== null) {
            $this->stopInstance($tempWorker);
            $this->registry->removeInstance('worker', $tempId);
            $this->cleanupInstancePidFile($tempWorker);
        }
    }
}
```

#### 2.2 调用入口修改
```php
// 在 rollingRestartMultiWorker() 中替换调用
private function rollingRestartMultiWorker(int $total): void
{
    // ... 前置代码 ...
    
    foreach ($batches as $batch) {
        $batchIdx++;
        
        // 使用零停机重启方法
        $result = $this->restartWorkerBatchZeroDowntime(
            $batch,
            $epochSnap,
            $restarted,
            $total,
            $batchIdx,
            $batchTotal
        );
        
        if ($result === 'aborted' || $result === 'failed') {
            return;
        }
        
        $restarted += count($batch);
    }
    
    // ... 后续代码 ...
}
```

### 3. ID 管理优化（可选）

#### 3.1 临时 ID 回收
```php
/**
 * 重启完成后，将临时 Worker 迁移回原 ID
 * （需要在所有批次完成后统一执行）
 */
private function migrateTempWorkersToOriginalIds(): void
{
    $tempWorkers = [];
    
    // 收集所有临时 Worker
    foreach ($this->registry->getAllInstances('worker') as $worker) {
        if ($worker->instanceId > self::TEMP_WORKER_ID_OFFSET) {
            $tempWorkers[] = $worker;
        }
    }
    
    if (empty($tempWorkers)) {
        return;
    }
    
    WlsLogger::info_('[Orchestrator] 开始迁移临时 Worker ID 回原 ID');
    
    foreach ($tempWorkers as $tempWorker) {
        $originalId = $tempWorker->instanceId - self::TEMP_WORKER_ID_OFFSET;
        
        // 检查原 ID 是否已被占用
        $existing = $this->registry->getInstance('worker', $originalId);
        if ($existing !== null) {
            WlsLogger::warning_(
                "[Orchestrator] 原 ID {$originalId} 仍被占用，跳过迁移临时 Worker {$tempWorker->instanceId}"
            );
            continue;
        }
        
        // 从 Dispatcher 移除临时 Worker
        $this->notifyDispatcherRemoveWorker($tempWorker->port);
        
        // 修改 Worker 的 instanceId（需要重启进程）
        $this->stopInstance($tempWorker);
        $this->registry->removeInstance('worker', $tempWorker->instanceId);
        
        // 以原 ID 重新启动
        $workerProvider = $this->registry->getProvider('worker');
        if ($workerProvider !== null && $this->context !== null) {
            $newWorker = $this->startInstance($workerProvider, $originalId, $this->context);
            if ($newWorker !== null) {
                // 等待 READY 并加入 Dispatcher
                if ($this->waitWorkerReadyWithEpoch($originalId, $this->startupTimeout, $this->ipcImperialEpoch)) {
                    $this->notifyDispatcherWorkerReady($newWorker);
                    WlsLogger::info_("[Orchestrator] 临时 Worker {$tempWorker->instanceId} 已迁移为 Worker {$originalId}");
                }
            }
        }
    }
}
```

### 4. 配置选项

```php
// env.php
'wls' => [
    'orchestrator' => [
        'rolling_restart_mode' => 'zero_downtime',  // 'zero_downtime' | 'legacy'
        'temp_worker_id_offset' => 100,
        'enable_id_migration' => false,  // 是否在重启后迁移 ID
    ],
],
```

## 实施步骤

1. **Phase 1: 核心实现**
   - 实现 `allocateTempWorkerId()` 和 `restartWorkerBatchZeroDowntime()`
   - 修改 `WorkerProvider::getPort()` 支持临时 ID
   - 添加配置开关

2. **Phase 2: 集成测试**
   - 单元测试：临时 ID 分配逻辑
   - 集成测试：零停机重启流程
   - 压测：重启期间流量无中断

3. **Phase 3: 可选优化**
   - 实现 ID 迁移功能
   - 添加监控指标（重启期间并发 Worker 数）
   - 优化端口分配策略

## 风险评估

### 高风险
- **端口冲突**：临时 Worker 端口必须与原 Worker 不冲突
  - 缓解：动态端口分配 + 端口占用检测

### 中风险
- **内存占用**：重启期间 Worker 数量翻倍
  - 缓解：分批重启 + 监控内存使用

### 低风险
- **ID 混乱**：临时 ID 可能导致监控/日志混乱
  - 缓解：日志中明确标注临时 ID + 可选的 ID 迁移

## 回滚方案

通过配置开关 `rolling_restart_mode: 'legacy'` 可立即回退到原流程。

## 性能影响

- **重启时间**：略微增加（需等待新 Worker 启动）
- **服务可用性**：100%（零停机）
- **资源占用**：重启期间内存/CPU 峰值增加 50%-100%

## 监控指标

```php
// 新增 Telemetry 指标
$this->telemetry->recordMetric('orchestrator.rolling_restart.concurrent_workers', $count);
$this->telemetry->recordMetric('orchestrator.rolling_restart.temp_workers_active', $tempCount);
$this->telemetry->recordMetric('orchestrator.rolling_restart.zero_downtime_success', 1);
```
