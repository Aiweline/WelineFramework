# WLS Session 共享服务架构

## 概述

WLS Session 共享服务是一个可扩展的 Session 管理架构，通过独立的 Session Server 进程解决多 Worker 间 Session 状态不一致的问题。默认使用 WLS 内置 Session Server（单进程内存服务），同时支持 Redis/Memcached 等外部存储的无缝切换。

## 设计目标

1. **100% 一致性**：所有 Worker 共享同一 Session 存储，消除状态不一致
2. **高性能**：内存存储 + TCP 通信，延迟约 0.1ms
3. **可扩展**：支持切换到 Redis/Memcached 等外部存储
4. **可靠性**：定时持久化 + Master 自动复活 + 降级模式
5. **安全性**：Token 认证，防止未授权访问
6. **可观测性**：Prometheus 格式监控指标

## 架构设计

```
┌─────────────────────────────────────────────────────────────────┐
│                         Worker 层                                │
│  ┌─────────┐  ┌─────────┐  ┌─────────┐                         │
│  │ Worker1 │  │ Worker2 │  │ WorkerN │                         │
│  └────┬────┘  └────┬────┘  └────┬────┘                         │
│       │            │            │                               │
│       └────────────┼────────────┘                               │
│                    │                                            │
│            ┌───────▼───────┐                                    │
│            │WlsSharedSession│ (SessionDriverHandlerInterface)   │
│            └───────┬───────┘                                    │
│                    │                                            │
│            ┌───────▼───────┐                                    │
│            │ SessionBackend │ (可插拔后端)                       │
│            └───────┬───────┘                                    │
│                    │                                            │
└────────────────────┼────────────────────────────────────────────┘
                     │
     ┌───────────────┼───────────────┐
     │               │               │
┌────▼────┐   ┌──────▼─────┐   ┌─────▼────┐
│  WLS    │   │   Redis    │   │Memcached │
│ Server  │   │  Backend   │   │ Backend  │
└────┬────┘   └────────────┘   └──────────┘
     │
     │ TCP (NDJSON)
     ▼
┌────────────────────┐
│   Session Server   │  ← 单进程 TCP 服务
│  ┌──────────────┐  │
│  │  内存存储    │  │
│  │  (HashMap)   │  │
│  └──────┬───────┘  │
│         │          │
│  ┌──────▼───────┐  │
│  │ 定时持久化   │  │
│  │ var/session/ │  │
│  └──────────────┘  │
└────────────────────┘
```

## 核心组件

### 1. SessionBackendInterface

统一的后端接口，所有 Session 存储后端必须实现此接口。

**位置**: `app/code/Weline/Server/Session/Backend/SessionBackendInterface.php`

**主要方法**:
- `get(sessionId, key)` - 获取 Session 数据
- `set(sessionId, key, value, ttl)` - 设置 Session 数据
- `getAll(sessionId)` - 获取整个 Session
- `setAll(sessionId, data, ttl)` - 批量设置 Session
- `destroy(sessionId)` - 销毁 Session
- `exists(sessionId)` - 检查 Session 是否存在
- `gc(maxLifetime)` - 垃圾回收
- `connect()` / `disconnect()` / `isConnected()` - 连接管理

### 2. Session Server

独立的 Session 存储服务进程，使用 TCP + NDJSON 协议通信。

**位置**: `app/code/Weline/Server/Session/Server/`

**组件**:
- `SessionServer.php` - TCP 服务端核心
- `SessionStore.php` - 内存存储（支持 TTL、LRU 淘汰、定时持久化）
- `SessionProtocol.php` - NDJSON 协议编解码

**特性**:
- 单进程内存存储，保证 Session 一致性
- 定时持久化到文件，支持重启恢复
- 智能 LRU 淘汰（优先淘汰即将过期的 Session）
- 与 Master IPC 集成，支持优雅关闭
- Token 认证机制，防止未授权访问
- Prometheus 格式监控指标
- 原子操作支持（increment、decrement、append、compareAndSet）

### 3. Session Client

Worker 进程通过此客户端与 Session Server 通信。

**位置**: `app/code/Weline/Server/Session/Client/SessionClient.php`

**特性**:
- 同步阻塞 I/O
- 自动重连机制（最多 3 次重试）
- 超时控制
- 自动认证（连接后发送 auth 命令）
- 健康检查支持（ping + reconnect）

### 4. 后端实现

**默认后端 (WLS)**:
- `WlsSessionBackend.php` - 封装 SessionClient

**可选后端**:
- `RedisSessionBackend.php` - Redis 存储
- `MemcachedSessionBackend.php` - Memcached 存储

### 5. Session 驱动

**位置**: `app/code/Weline/Server/extends/module/Weline_Framework/Session/WlsSharedSession.php`

实现 `SessionDriverHandlerInterface`，作为框架 Session 系统与后端的桥梁。

**特性**:
- 本地缓存减少网络请求
- 降级模式：Session Server 不可用时使用本地缓存，写操作排队等待恢复
- 自动重连和状态恢复

## 配置说明

在 `app/etc/env.php` 中配置：

```php
'session' => [
    'default' => 'file',
    'wls_managed' => true,
    
    'wls' => [
        'enabled' => true,           // 是否启用 WLS Session Server
        'backend' => 'wls',          // 后端类型：wls（推荐） | redis | memcached
        
        // WLS 内置 Session Server 配置
        'wls_server' => [
            'port' => 19970,              // Session Server 监听端口
            'persist_interval' => 30,     // 定时持久化间隔（秒），建议 30-60
            'persist_on_writes' => 100,   // 每 N 次写入持久化
            'persist_on_critical' => true,// 关键操作后强制持久化
            'max_sessions' => 50000,      // 最大 Session 数量
            'session_ttl' => 3600,        // 默认 Session TTL
            'auth_enabled' => true,       // 启用 Token 认证（推荐）
            'serializer' => 'json',       // 序列化器：json | msgpack | igbinary
        ],
        
        // Redis 后端配置
        'redis' => [
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
            'prefix' => 'wls_sess:',
        ],
        
        // Memcached 后端配置
        'memcached' => [
            'servers' => [['127.0.0.1', 11211]],
            'prefix' => 'wls_sess:',
        ],
    ],
],
```

## 进程管理

Session Server 由 Master 进程统一管理：

1. **启动**: Master 在阶段 0 启动 Session Server
2. **健康检查**: Master 定期检测 Session Server 端口和 PID
3. **自动复活**: Session Server 意外退出时自动重启
4. **优雅关闭**: 收到 shutdown 信号时先持久化数据

## NDJSON 协议

请求格式：
```json
{"cmd":"get","sid":"abc123","key":"user_id"}
{"cmd":"set","sid":"abc123","key":"user_id","val":123,"ttl":3600}
{"cmd":"destroy","sid":"abc123"}
```

响应格式：
```json
{"ok":true,"data":...}
{"ok":false,"err":"Error message"}
```

## 性能预期

| 场景 | 延迟 | 一致性 |
|------|------|--------|
| WLS Session Server (TCP) | ~0.1ms | 100% |
| Redis | ~0.3ms | 100% |
| Memcached | ~0.2ms | 100% |

## 文件结构

```
app/code/Weline/Server/
├── Session/
│   ├── Backend/
│   │   ├── SessionBackendInterface.php    # 后端接口
│   │   ├── SessionBackendFactory.php      # 工厂类
│   │   ├── WlsSessionBackend.php          # WLS 后端
│   │   ├── RedisSessionBackend.php        # Redis 后端
│   │   └── MemcachedSessionBackend.php    # Memcached 后端
│   ├── Server/
│   │   ├── SessionServer.php              # TCP 服务端
│   │   ├── SessionStore.php               # 内存存储
│   │   └── SessionProtocol.php            # NDJSON 协议
│   └── Client/
│       └── SessionClient.php              # TCP 客户端
├── bin/
│   └── session_server.php                 # 入口脚本
├── extends/module/Weline_Framework/Session/
│   └── WlsSharedSession.php               # Session 驱动
├── Observer/
│   └── SessionDriverInterceptor.php       # 驱动拦截器
└── test/Session/
    ├── SessionProtocolTest.php            # 协议测试
    ├── SessionStoreTest.php               # 存储测试
    ├── SessionBackendFactoryTest.php      # 工厂测试
    └── SessionServerIntegrationTest.php   # 集成测试
```

## 使用方式

### 默认模式（推荐）

无需额外配置，WLS 启动时自动启动 Session Server，所有 Worker 共享 Session。

### 切换到 Redis

```php
'wls' => [
    'enabled' => true,
    'backend' => 'redis',
    'redis' => [
        'host' => '127.0.0.1',
        'port' => 6379,
    ],
],
```

### 禁用 WLS Session 管理

```php
'wls_managed' => false,  // 使用原生 PHP Session
```

## 安全机制

### Token 认证

启用 `auth_enabled` 后，Session Server 启动时会：
1. 生成 64 字符随机 Token
2. 写入 `var/session/session_server.token`（权限 0600）
3. Client 连接后首次发送 `auth` 命令验证
4. 验证失败立即断开连接

### 降级模式

当 Session Server 不可用时，`WlsSharedSession` 会：
1. 进入降级模式，记录警告日志
2. 读操作从本地缓存返回
3. 写操作排队等待恢复
4. Server 恢复后自动重放待写入队列

## 监控指标

通过 `metrics` 命令获取 Prometheus 格式指标：

```
# HELP wls_session_sessions_total Current number of sessions
# TYPE wls_session_sessions_total gauge
wls_session_sessions_total 12345

# HELP wls_session_requests_total Total requests by operation
# TYPE wls_session_requests_total counter
wls_session_requests_total{op="get"} 100000
wls_session_requests_total{op="set"} 50000

# HELP wls_session_evictions_total Total evicted sessions
# TYPE wls_session_evictions_total counter
wls_session_evictions_total 500
```

## 原子操作

支持并发安全的原子操作：

| 操作 | 命令 | 说明 |
|------|------|------|
| 递增 | `incr` | 原子递增数值，返回新值 |
| 递减 | `decr` | 原子递减数值，返回新值 |
| 追加 | `append` | 原子追加元素到数组或字符串 |
| CAS | `cas` | 比较并设置，仅当当前值等于期望值时才设置 |

## 风险与缓解

| 风险 | 缓解措施 |
|------|----------|
| Session Server 单点故障 | Master 自动复活 + 降级模式 + 磁盘持久化 |
| TCP 连接开销 | 连接复用 + 预连接，延迟约 0.1ms |
| 内存溢出 | 智能 LRU 淘汰（优先即将过期）+ max_sessions 限制 |
| 热重载数据丢失 | Session Server 独立于 Worker，不参与热重载 |
| 未授权访问 | Token 认证 + 仅监听 127.0.0.1 |
| 数据丢失窗口 | 关键操作强制持久化 + 减小持久化间隔 |
| 并发竞态 | 原子操作支持（increment、CAS 等） |
