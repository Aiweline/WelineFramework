# WLS 严重缺陷修复报告

**修复日期**: 2026-04-02  
**修复范围**: 内存泄漏、IPC 可靠性、日志系统、健康检查、Fiber 调度器

---

## 🔴 已修复的严重缺陷

### 1. WlsLogger 内存泄漏 ✅

**问题**: 日志缓冲区无限增长导致 Worker 进程 OOM（256MB 限制下频繁崩溃）

**修复内容**:
- 添加 `maxBufferLines = 1000` 行数限制（防止单行过小但行数过多）
- 添加 `droppedLogCount` 计数器，内存压力下丢弃非关键日志
- 内存使用超过 90% 时仅保留 ERROR/FATAL 级别日志
- 每丢弃 100 条日志输出一次警告
- `flush()` 方法重置所有计数器，防止泄漏

**影响文件**:
- `app/code/Weline/Server/Log/WlsLogger.php`

**测试验证**:
```php
// 模拟 2000 条日志写入，验证 buffer 自动刷新
for ($i = 0; $i < 2000; $i++) {
    $logger->info("Test log line $i");
}
// ✓ 通过：buffer 在 1000 行时自动刷新
```

---

### 2. ControlClient IPC 缓冲区溢出 ✅

**问题**: IPC 读缓冲区 `$buffer` 无限增长，导致内存耗尽（错误日志显示 207 行崩溃）

**修复内容**:
- 添加 `maxBufferSize = 2MB` 硬限制
- 检测到溢出时记录错误日志并清空 buffer
- 触发连接断开和自动重连机制
- 防止恶意或异常大消息攻击
- 添加 `formatMsgPayload()` 内存压力检测，避免序列化大型数组

**影响文件**:
- `app/code/Weline/Server/IPC/ControlClient.php`

**关键代码**:
```php
if (strlen($this->buffer) + strlen($data) > $this->maxBufferSize) {
    $this->ipcLog("[IPC-{$this->selfTag}] ERROR: Buffer overflow detected");
    $this->buffer = '';
    $this->handleDisconnect(); // 触发重连
    return [];
}
```

---

### 3. NDJSON 协议边界检查增强 ✅

**问题**: 
- 完整缓冲区超过 2MB 时静默清空，无日志
- 半包消息无大小检查，可能累积到 2MB 才清空

**修复内容**:
- 完整缓冲区超过 2MB 时记录 `error_log`
- 半包消息超过 1MB 时立即丢弃并记录日志
- 防止协议错误或恶意数据导致内存泄漏

**影响文件**:
- `app/code/Weline/Framework/System/IPC/NdjsonProtocol.php`

**测试验证**:
```bash
✓ Test 1: 3MB 缓冲区溢出保护
✓ Test 2: 1.5MB 半包消息丢弃
✓ Test 3: 正常消息解析
```

---

### 4. ConnectionPool 空闲连接超时清理 ✅

**问题**: 
- 连接池无超时清理机制，空闲连接永久占用资源
- 泄漏的连接（busy 超过 30 秒）仅检测不回收

**修复内容**:
- `healthCheck()` 添加空闲超时检查（默认 5 分钟）
- 超时连接自动关闭（保留 `min_idle` 最小数量）
- `detectLeaks()` 添加 `autoReclaim` 参数，自动回收泄漏连接
- 支持通过 `options['idle_timeout']` 配置超时时间

**影响文件**:
- `app/code/Weline/Server/Shared/Connection/ConnectionPoolManager.php`

**配置示例**:
```php
$pool = ConnectionPoolManager::getInstance('127.0.0.1', 19970, [
    'idle_timeout' => 300.0,  // 5 分钟空闲超时
    'min_idle' => 2,          // 保留最少 2 个连接
]);
```

---

### 5. 生产环境日志分级控制 ✅

**问题**: 生产环境仍输出 DEBUG 日志，浪费磁盘 I/O 和内存

**修复内容**:
- 添加 `production_level` 配置项（默认 INFO）
- 非开发模式下强制最低日志级别为 INFO
- 防止配置错误导致生产环境输出调试日志

**影响文件**:
- `app/code/Weline/Server/Log/LogConfig.php`

**配置示例**:
```php
// app/etc/env.php
'wls' => [
    'log' => [
        'level' => 'DEBUG',           // 开发环境生效
        'production_level' => 'INFO', // 生产环境强制最低级别
    ],
],
```

---

## ⚠️ 已修复的中等优先级缺陷

### 6. Worker 健康检查 IPC ping/pong 机制 ✅

**问题**: `WorkerScaler::checkHealth()` 仅检查进程存在性，无法检测僵死进程

**修复内容**:
- 添加 `TYPE_PING` 和 `TYPE_PONG` IPC 消息类型
- Master 发送 ping，Worker 响应 pong（附带状态信息）
- `MasterControlServer` 跟踪 `last_pong_time` 时间戳
- `WorkerScaler::checkHealth()` 实现 2 秒超时检测
- Worker 端自动响应 ping 消息

**影响文件**:
- `app/code/Weline/Server/IPC/ControlMessage.php`
- `app/code/Weline/Server/IPC/MasterControlServer.php`
- `app/code/Weline/Server/Service/WorkerScaler.php`
- `app/code/Weline/Server/bin/worker.php`

**使用示例**:
```php
// Master 端检查 Worker 健康状态
$isHealthy = $workerScaler->checkHealth($pid, $timeoutSec = 2.0);
```

---

### 7. 配置热更新逻辑（Scale 命令）✅

**问题**: `server:scale --auto` 命令仅提示手动修改配置文件

**修复内容**:
- 实现自动读取和更新 `app/etc/env.php`
- 自动备份原配置文件（带时间戳）
- 支持 `--enable/--disable` 和 `--min/--max` 参数
- 写入失败时自动恢复备份

**影响文件**:
- `app/code/Weline/Server/Console/Server/Scale.php`

**使用示例**:
```bash
# 启用自动扩缩容，设置 2-8 Worker
php bin/w server:scale --auto --enable --min=2 --max=8

# 配置自动备份到 app/etc/env.php.backup.20260402123456
# 需要 reload 生效：php bin/w server:reload
```

---

### 8. Fiber 调度器心跳续约机制 ✅

**问题**: 长生命周期 Fiber 无超时强制回收，可能导致资源泄漏

**修复内容**:
- 添加 `last_activity` 时间戳跟踪（每次 resume/suspend 自动续约）
- 实现**心跳续约机制**：60 秒内无续约则判定为僵死，强制回收
- 区分长连接和普通请求：长连接不受闲置超时限制
- 记录警告日志，便于排查问题
- 支持通过 `env.php` 配置心跳超时时间

**影响文件**:
- `app/code/Weline/Server/bin/worker.php`

**配置示例**:
```php
// app/etc/env.php
'wls' => [
    'fiber' => [
        'heartbeat_timeout' => 60, // Fiber 心跳超时（秒），默认 60 秒
    ],
],
```

**心跳续约机制**:
- **自动续约**: Fiber 每次 `resume`/`suspend` 时自动更新 `last_activity`
- **超时检测**: 超过 60 秒未续约 → 判定为僵死，强制回收
- **长连接保护**: SSE/WebSocket 等长连接只要持续发送数据就会自动续约

**示例场景**:
- ✅ SSE 连接每 30 秒发送数据 → 自动续约，不会被回收
- ✅ WebSocket 持续收发消息 → 自动续约，不会被回收
- ✅ 长轮询每 45 秒返回数据 → 自动续约，不会被回收
- ❌ 普通请求卡在死循环 60 秒无响应 → 强制回收（心跳超时）
- ❌ 长连接建立后 60 秒无任何数据传输 → 强制回收（心跳超时）

**续约触发条件**:
- Fiber 从挂起状态恢复（`resume`）
- Fiber 再次挂起（`suspend`）
- 任何导致 Fiber 状态变化的操作

**建议配置**:
- **短连接场景**: `heartbeat_timeout: 30` （30 秒超时）
- **长连接场景**: `heartbeat_timeout: 120` （2 分钟超时，配合客户端 30-60 秒心跳）
- **混合场景**: `heartbeat_timeout: 60` （默认值，平衡保护）

---

### 9. 移除调试日志（WLS_DEBUG）✅

**问题**: 生产环境输出大量 `WLS_DEBUG` 日志，影响性能

**修复内容**:
- 移除 `stream_select` 调试日志（每 500 次输出）
- 移除 Fiber 调度状态监控日志（每 1000 次输出）
- 保留必要的错误和警告日志

**影响文件**:
- `app/code/Weline/Server/bin/worker.php`

---

## 📊 修复效果预期

| 指标 | 修复前 | 修复后 |
|------|--------|--------|
| Worker 内存峰值 | 256MB+ (OOM) | < 200MB |
| IPC 缓冲区最大值 | 无限制 | 2MB 硬限制 |
| 连接池泄漏 | 永久占用 | 30s 自动回收 |
| 生产环境 DEBUG 日志 | 输出 | 禁用 |
| 日志 buffer 最大行数 | 无限制 | 1000 行 |
| Worker 健康检查 | 仅进程存在性 | IPC ping/pong (2s 超时) |
| Fiber 心跳续约 | 无 | 60s 心跳超时（自动续约） |
| 配置热更新 | 手动编辑 | CLI 自动更新 |

---

## 🔧 后续建议

### 低优先级
1. **IPC 消息确认机制**: 实现 ACK/NACK 避免关键控制指令丢失
2. **日志轮转**: 实现基于大小和时间的自动轮转
3. **压力测试**: 添加 IPC 和连接池的压力测试用例

---

## 📝 使用注意事项

1. **内存限制**: 建议 Worker 进程 `memory_limit` 至少 512MB
2. **日志级别**: 生产环境确保 `deploy=prod` 或显式设置 `production_level`
3. **连接池监控**: 定期调用 `detectLeaks()` 和 `healthCheck()`
4. **IPC 重连**: ControlClient 自动重连，无需手动干预
5. **Fiber 心跳**: 长连接场景建议配置 `heartbeat_timeout: 120`，客户端 30-60 秒发送心跳
6. **健康检查**: Master 可定期 ping Worker 检测僵死进程

---

**修复完成，建议执行 `php bin/w server:restart -r` 重启 WLS 验证修复效果。**
