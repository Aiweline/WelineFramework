# WLS 配置指南

本文档说明 WLS 缺陷修复后新增的配置选项。

---

## 📋 配置位置

所有配置位于 `app/etc/env.php` 的 `wls` 节点下。

参考示例：`app/etc/env.sample.php`

---

## 🔧 日志配置 (`wls.log`)

### 基础配置

```php
'wls' => [
    'log' => [
        'enabled' => true,                // 是否启用日志
        'path' => 'var/log/wls/',         // 日志目录
        'level' => 'DEBUG',               // 开发环境日志级别
        'production_level' => 'INFO',     // 生产环境强制最低级别
        'stdout' => 'auto',               // 控制台输出
        'rotate' => 'daily',              // 日志轮转策略
        'max_files' => 7,                 // 保留天数
        'max_size' => 52428800,           // 单文件最大 50MB
    ],
],
```

### 日志级别说明

| 级别 | 优先级 | 用途 | 建议环境 |
|------|--------|------|----------|
| DEBUG | 100 | 调试信息（详细） | 开发环境 |
| INFO | 200 | 一般信息 | 测试/生产环境 |
| NOTICE | 250 | 注意事项 | 生产环境 |
| WARNING | 300 | 警告信息 | 生产环境 |
| ERROR | 400 | 错误信息 | 所有环境 |
| FATAL | 500 | 致命错误 | 所有环境 |

### 生产环境日志级别控制

- **开发环境** (`deploy=dev`): 使用 `level` 配置
- **生产环境** (`deploy=prod`): 强制使用 `production_level`（默认 INFO）

**示例**:
```php
'level' => 'DEBUG',           // 开发环境输出 DEBUG 日志
'production_level' => 'INFO', // 生产环境最低 INFO，忽略 DEBUG
```

### 日志轮转

- **策略**: `daily` - 按日期分割日志文件
- **文件名格式**: 
  - `wls-2026-04-02.log` (主日志)
  - `error-2026-04-02.log` (错误日志)
  - `crash-2026-04-02.log` (崩溃日志)
- **自动清理**: 保留最近 `max_files` 天的日志，每小时检查一次

---

## 🔄 Fiber 调度器配置 (`wls.fiber`)

### 心跳续约机制

```php
'wls' => [
    'fiber' => [
        'heartbeat_timeout' => 60,    // 心跳超时（秒）
        'idle_ttl' => 0,              // 闲置超时（秒），0=禁用
        'max_active' => 0,            // 最大活跃数，0=无限制
    ],
],
```

### 心跳超时说明

**工作原理**:
- Fiber 每次 `resume`/`suspend` 时自动更新 `last_activity` 时间戳
- 超过 `heartbeat_timeout` 秒未更新 → 判定为僵死，强制回收

**场景建议**:

| 场景 | 推荐值 | 说明 |
|------|--------|------|
| 短连接 API | 30 秒 | 快速响应，及时回收异常请求 |
| 长连接（SSE/WebSocket） | 120 秒 | 配合客户端 30-60 秒心跳 |
| 混合场景 | 60 秒 | 默认值，平衡保护 |

**示例场景**:

✅ **正常场景（不会被回收）**:
```
SSE 连接每 30 秒发送数据:
0s   - 建立连接，Fiber 挂起
30s  - 发送数据，Fiber resume → suspend（自动续约）
60s  - 发送数据，Fiber resume → suspend（自动续约）
...  - 持续运行，不会被回收
```

❌ **异常场景（会被回收）**:
```
死循环请求:
0s   - 开始处理请求
10s  - 进入死循环（CPU 100%）
60s  - 心跳超时，强制回收（无任何 resume/suspend）
```

---

## 📈 Worker 自动扩缩容配置 (`wls.scaling`)

```php
'wls' => [
    'scaling' => [
        'enabled' => false,              // 是否启用自动扩缩容
        'min_workers' => 2,              // 最小 Worker 数
        'max_workers' => 8,              // 最大 Worker 数
        'scale_up_threshold' => 0.8,     // 扩容阈值（80% 负载）
        'scale_down_threshold' => 0.3,   // 缩容阈值（30% 负载）
        'cooldown_sec' => 60,            // 扩缩容冷却时间（秒）
    ],
],
```

### CLI 命令

```bash
# 手动扩缩容
php bin/w server:scale --workers=4

# 配置自动扩缩容
php bin/w server:scale --auto --enable --min=2 --max=8

# 查看扩缩容状态
php bin/w server:scale --status
```

---

## 🏥 健康检查配置

### Worker 健康检查

WLS 现在支持 IPC ping/pong 健康检查机制：

- **Master → Worker**: 发送 `TYPE_PING` 消息
- **Worker → Master**: 响应 `TYPE_PONG` 消息（附带状态信息）
- **超时检测**: 2 秒未响应 → 判定为不健康

**使用示例**:
```php
// Master 端检查 Worker 健康状态
$isHealthy = $workerScaler->checkHealth($pid, $timeoutSec = 2.0);
```

---

## 🔒 内存保护配置

### WlsLogger 内存保护

**自动保护机制**:
- 缓冲区最大 1000 行
- 内存使用超过 90% 时丢弃非关键日志（仅保留 ERROR/FATAL）
- 每丢弃 100 条日志输出一次警告

**无需配置**，自动生效。

### IPC 缓冲区保护

**自动保护机制**:
- ControlClient 缓冲区最大 2MB
- NDJSON 完整缓冲区最大 2MB
- NDJSON 半包消息最大 1MB

**无需配置**，自动生效。

### ConnectionPool 保护

**自动保护机制**:
- 空闲连接 5 分钟超时自动关闭
- 泄漏连接 30 秒自动回收

**可选配置**:
```php
$pool = ConnectionPoolManager::getInstance('127.0.0.1', 19970, [
    'idle_timeout' => 300.0,  // 空闲超时（秒）
    'min_idle' => 2,          // 保留最少连接数
]);
```

---

## 📊 推荐配置

### 开发环境

```php
'wls' => [
    'log' => [
        'level' => 'DEBUG',
        'production_level' => 'INFO',
        'max_files' => 3,
    ],
    'fiber' => [
        'heartbeat_timeout' => 30,
    ],
    'scaling' => [
        'enabled' => false,
    ],
],
```

### 生产环境（短连接 API）

```php
'wls' => [
    'log' => [
        'level' => 'INFO',
        'production_level' => 'INFO',
        'max_files' => 7,
    ],
    'fiber' => [
        'heartbeat_timeout' => 30,
    ],
    'scaling' => [
        'enabled' => true,
        'min_workers' => 4,
        'max_workers' => 16,
    ],
],
```

### 生产环境（长连接场景）

```php
'wls' => [
    'log' => [
        'level' => 'INFO',
        'production_level' => 'INFO',
        'max_files' => 7,
    ],
    'fiber' => [
        'heartbeat_timeout' => 120,  // 2 分钟超时
    ],
    'scaling' => [
        'enabled' => true,
        'min_workers' => 4,
        'max_workers' => 16,
    ],
],
```

---

## 🔍 监控建议

### 关键指标

1. **Worker 内存使用**: 应 < 200MB
2. **日志文件大小**: 按日期轮转，自动清理
3. **Fiber 心跳超时**: 监控 `Fiber 心跳超时` 日志
4. **IPC 缓冲区溢出**: 监控 `Buffer overflow detected` 日志

### 日志监控

```bash
# 查看心跳超时日志
tail -f var/log/wls/wls-$(date +%Y-%m-%d).log | grep "心跳超时"

# 查看 IPC 缓冲区溢出
tail -f var/log/wls/wls-$(date +%Y-%m-%d).log | grep "Buffer overflow"

# 查看内存压力日志
tail -f var/log/wls/wls-$(date +%Y-%m-%d).log | grep "memory pressure"
```

---

## 🚀 应用配置

修改配置后需要重启 WLS：

```bash
# 重启 WLS（重新加载配置）
php bin/w server:restart -r

# 或者仅重载代码（不重启 Master）
php bin/w server:reload
```

---

## 📝 相关文档

- [WLS_CRITICAL_FIXES.md](WLS_CRITICAL_FIXES.md) - 缺陷修复详细报告
- [tests/WlsFixesSmokeTest.php](tests/WlsFixesSmokeTest.php) - 冒烟测试用例
- [app/etc/env.sample.php](app/etc/env.sample.php) - 完整配置示例
