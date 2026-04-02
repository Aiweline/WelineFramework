# WLS 模块问题清单

**审查日期**: 2026-04-02  
**审查范围**: 整个 Weline\Server 模块  
**审查方法**: 系统性代码审查 + 错误日志分析

---

## 🔴 严重问题（Critical）- 必须立即修复

### C1. IPC 消息类型错误 - ServiceOrchestrator::broadcastRoutingCacheClear()
**文件**: `Service/ServiceOrchestrator.php:7186-7189`  
**问题**: 直接使用 `json_encode()` 构造消息，而不是使用 `ControlMessage::encode()`，导致消息格式不一致
```php
// 错误代码
$message = \json_encode([
    'type' => ControlMessage::TYPE_ROUTING_CACHE_CLEAR,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
$this->controlServer->sendTo($instance->ipcClientId, $message);
```
**影响**: 
- 消息可能缺少必要的协议字段
- 与其他 IPC 消息格式不一致
- 可能导致 Dispatcher 解析失败

**错误日志证据**:
```
[2026-04-02 08:28:38] [Master@default] [ERROR] [Orchestrator] 控制操作执行异常 
id=ctrl_op_1 action=routing_cache_clear 
error=Weline\Server\IPC\MasterControlServer::sendTo(): Argument #2 ($message) must be of type string, array given
```

**修复方案**: 使用 `ControlMessage::encode()` 统一编码
```php
$message = ControlMessage::encode([
    'type' => ControlMessage::TYPE_ROUTING_CACHE_CLEAR,
]);
$this->controlServer->sendTo($instance->ipcClientId, $message);
```

---

### C2. 类型错误 - SchedulerSystem::sleep() 参数类型不匹配
**文件**: `bin/worker_ssl.php:1113`  
**问题**: 传递 `float` 类型给只接受 `int` 的 `SchedulerSystem::sleep()`
```php
// 错误代码
\Weline\Framework\Runtime\SchedulerSystem::sleep(1);  // 在循环中可能累积为 float
```
**影响**: 
- 运行时 TypeError 异常
- Worker SSL 预热失败
- 进程崩溃

**错误日志证据**:
```
[2026-04-02 09:04:02] [WorkerSSL#1:16897@default] [ERROR] 
Weline\Framework\Runtime\SchedulerSystem::sleep(): Argument #1 ($seconds) must be of type int, float given
```

**修复方案**: 确保传递整数类型
```php
\Weline\Framework\Runtime\SchedulerSystem::sleep((int)1);
```

---

### C3. Dispatcher 异常或缺失 - 启动失败
**文件**: 多个启动相关文件  
**问题**: Dispatcher 进程无法正常启动或注册
```
[2026-04-02 08:14:33] [Master@default] [ERROR] [Master自检] Dispatcher 异常或缺失: 期望 1，就绪 0 — 尝试维护/拉起
```
**影响**: 
- Windows 模式下无法接收 HTTP 请求
- 整个 WLS 服务不可用

**可能原因**:
1. Dispatcher 启动命令错误
2. 端口冲突
3. IPC 注册超时
4. 进程启动后立即崩溃

**修复方案**: 需要进一步调查启动日志，检查：
- Dispatcher 进程是否成功启动
- IPC 连接是否建立
- 端口绑定是否成功

---

## 🟡 中等问题（Medium）- 应尽快修复

### M1. 资源泄漏风险 - 连接池未正确清理
**文件**: `Shared/Connection/ConnectionPoolManager.php`  
**问题**: 在某些异常路径下，连接可能未被正确释放
```php
public function acquire(float $timeoutSec = 0.05): ?PooledConnectionInterface
{
    // ... 获取连接逻辑
    // 如果在 connect() 后、标记 busy 前发生异常，连接会泄漏
}
```
**影响**: 
- 长时间运行后连接池耗尽
- 共享服务（Session/Memory）连接失败

**修复方案**: 添加异常处理和资源清理
```php
try {
    $conn = $this->createConnection();
    if ($conn->connect()) {
        $this->pool[] = ['conn' => $conn, 'busy' => true, 'last_used' => \microtime(true)];
        return $conn;
    }
} catch (\Throwable $e) {
    // 清理失败的连接
    if (isset($conn)) {
        $conn->close();
    }
    throw $e;
}
```

---

### M2. 错误处理不完整 - MasterControlServer::sendTo()
**文件**: `IPC/MasterControlServer.php:584-609`  
**问题**: `sendTo()` 方法在发送失败时只记录日志，但调用方无法区分失败原因
```php
public function sendTo(int $clientId, string $message): bool
{
    // ... 发送逻辑
    if (!$sent) {
        $this->ipcLog("[IPC-Master] SEND FAILED --> {$tag}: type={$type}");
        // 只检查 EOF，不检查其他错误
        if (@\feof($socket)) {
            $this->removeClient($clientId);
        }
    }
    return $sent;
}
```
**影响**: 
- 调用方无法判断是临时失败还是永久失败
- 可能导致消息丢失而不自知

**修复方案**: 增强错误处理
```php
if (!$sent) {
    $errno = \socket_last_error($socket);
    $this->ipcLog("[IPC-Master] SEND FAILED --> {$tag}: type={$type}, errno={$errno}");
    
    // 区分临时错误和永久错误
    if (@\feof($socket) || \in_array($errno, [32, 104, 110], true)) { // EPIPE, ECONNRESET, ETIMEDOUT
        $this->removeClient($clientId);
    }
}
```

---

### M3. 并发控制缺失 - ServiceOrchestrator 状态管理
**文件**: `Service/ServiceOrchestrator.php`  
**问题**: 多个控制操作可能并发执行，缺少互斥保护
```php
private ?array $activeControlOperation = null;
private array $pendingControlOperations = [];

// 没有明确的锁机制防止并发修改
```
**影响**: 
- 滚动重启期间收到新的 reload 命令可能导致状态混乱
- 多个 CLI 命令同时执行可能互相干扰

**修复方案**: 实现操作队列和互斥机制（已有 `$ipcExclusiveCommand` 但未完全实现）

---

### M4. 超时处理不一致 - Worker 启动等待
**文件**: `Service/WorkerScaler.php:90`  
**问题**: 启动超时硬编码为 10 秒，但在高负载或慢启动环境下可能不够
```php
private const START_TIMEOUT = 10;
```
**影响**: 
- 在慢速环境下 Worker 可能被误判为启动失败
- 导致不必要的重启循环

**修复方案**: 使超时可配置，并根据环境自适应调整

---

## 🟢 优化建议（Enhancement）- 提升质量

### E1. 日志过于冗余 - IPC 详细日志
**文件**: `IPC/MasterControlServer.php`  
**问题**: 每条 IPC 消息都记录详细日志，在高并发下产生大量日志
```php
private function ipcVerboseLog(string $message): void
{
    if ($this->verboseLog) {
        WlsLogger::debug_($message);
    }
}
```
**建议**: 
- 仅在 DEV 模式下启用详细日志
- 生产环境只记录重要消息（SHUTDOWN、RELOAD 等）
- 添加日志采样机制

---

### E2. 魔法数字过多 - 超时和阈值硬编码
**文件**: 多个文件  
**问题**: 大量超时、重试次数等参数硬编码
```php
private float $healthCheckInterval = 30.0;
private float $registerTimeout = 60.0;
private float $reconcileInterval = 5.0;
private const WORKER_FAIL_THRESHOLD = 3;
private const WORKER_BLACKLIST_RECOVERY_SECONDS = 5;
```
**建议**: 
- 提取为配置项
- 允许运行时调整
- 根据环境自适应

---

### E3. 错误消息国际化不完整
**文件**: 多个文件  
**问题**: 部分错误消息未使用 `__()` 国际化
```php
WlsLogger::error_("SSL Worker#{$workerId} 预热失败，无法正常处理请求，退出");
```
**建议**: 统一使用国际化函数

---

### E4. 缺少健康检查端点
**文件**: 整个模块  
**问题**: 没有标准的健康检查 HTTP 端点供负载均衡器使用
**建议**: 
- 添加 `/health` 端点
- 返回各组件状态（Worker、Dispatcher、Session、Memory）
- 支持深度检查和浅检查

---

### E5. 监控指标不足
**文件**: 多个文件  
**问题**: 缺少关键性能指标的收集和暴露
**建议**: 
- IPC 消息延迟
- 连接池使用率
- Worker 负载分布
- 请求队列长度
- 错误率统计

---

## 📊 问题统计

| 严重程度 | 数量 | 必须修复 |
|---------|------|---------|
| 🔴 严重 | 3    | ✅ 是   |
| 🟡 中等 | 4    | ⚠️ 建议 |
| 🟢 优化 | 5    | ❌ 否   |
| **总计** | **12** | **3** |

---

## 🔧 修复优先级

### 第一优先级（立即修复）
1. **C1**: IPC 消息类型错误 - 影响路由缓存清理
2. **C2**: SchedulerSystem::sleep() 类型错误 - 导致 Worker 崩溃
3. **C3**: Dispatcher 启动失败 - 整个服务不可用

### 第二优先级（本周内修复）
4. **M1**: 连接池资源泄漏
5. **M2**: IPC 错误处理不完整
6. **M3**: 并发控制缺失

### 第三优先级（下个迭代）
7. **M4**: 超时处理优化
8. **E1-E5**: 代码质量优化

---

## 🧪 测试建议

### 单元测试
- [ ] IPC 消息编解码测试
- [ ] 连接池获取/释放测试
- [ ] 超时处理测试

### 集成测试
- [ ] Dispatcher 启动流程测试
- [ ] 滚动重启流程测试
- [ ] 并发控制操作测试

### 压力测试
- [ ] 高并发 IPC 消息测试
- [ ] 连接池耗尽场景测试
- [ ] Worker 频繁重启测试

---

## 📝 备注

1. 所有严重问题都有对应的错误日志证据
2. 建议在修复后添加回归测试防止再次出现
3. 部分问题可能相互关联，建议按优先级顺序修复
4. 修复后需要更新相关文档和架构图
