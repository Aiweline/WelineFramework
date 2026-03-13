# Weline Server 模块

高性能异步常驻内存 HTTP 服务器，支持跨平台多进程架构。

## 📦 模块信息

- **模块名**: `Weline_Server`
- **类型**: 基础设施模块
- **协议支持**: HTTP/1.1, WebSocket, TCP, UDP

## 🚀 快速开始

```bash
# 启动服务器（自动探测最佳配置）
php bin/w server:start

# 查看状态
php bin/w server:status

# 压力测试（自动探测运行中的服务器）
php bin/w server:benchmark

# 停止服务器
php bin/w server:stop
```

## 📖 服务器类型

### 1. WLS (Weline Server) - 高性能服务器

适用于 **生产环境** 和 **高并发场景**。

#### 特性

| 特性 | 说明 |
|-----|------|
| 常驻内存 | 启动后常驻内存，避免每次请求重新加载 |
| 多进程 | 支持多 Worker 进程，充分利用多核 CPU |
| 异步 I/O | 基于事件循环的非阻塞 I/O |
| 高性能 | 单进程 QPS 15,000+，多进程可达 100,000+ |
| 跨平台 | 支持 Windows/Linux/Mac |

#### 启动命令

```bash
# 默认启动（智能模式）
php bin/w server:start

# 命名实例
php bin/w server:start api-server

# 指定端口和进程数
php bin/w server:start -p 9000 -c 8

# 守护进程模式（仅 Linux/Mac）
php bin/w server:start -d
```

#### 配置参数

| 参数 | 简写 | 说明 | 默认值 |
|-----|------|------|--------|
| `--port` | `-p` | 监听端口 | 9981 |
| `--host` | `-h` | 监听地址 | 127.0.0.1 |
| `--count` | `-c` | Worker 进程数 | 智能推算 |
| `--daemon` | `-d` | 守护进程模式 | false |

#### 智能模式

当 `worker_count` 设置为 `'auto'` 时，系统会根据服务器性能自动推算：

| 工作模式 | 计算公式 | 适用场景 |
|---------|---------|---------|
| `io` | CPU 核心数 × 2 | 数据库查询、API 请求、文件 I/O |
| `cpu` | CPU 核心数 | 图像处理、加密计算、复杂算法 |

> **Windows 限制**: 由于 Windows 多进程开销较大，推荐值不超过 CPU 核心数

### 2. CLI Server - 开发服务器

适用于 **开发环境** 和 **快速调试**。

#### 特性

| 特性 | 说明 |
|-----|------|
| 内置服务器 | 使用 PHP 内置 CLI 服务器 |
| 单进程 | 简单轻量，适合开发调试 |
| 热重载 | 文件修改后自动生效 |
| 零配置 | 无需额外配置即可启动 |

#### 启动命令

```bash
# 启动 CLI 服务器
php bin/w server:start cli -p 8080

# 或使用 PHP 内置命令
php -S 127.0.0.1:8080 -t pub
```

## ⚙️ 环境配置 (env.php)

在 `app/etc/env.php` 中配置服务器参数：

```php
'server' => [
    'host' => '127.0.0.1',      // 监听地址
    'port' => 9443,             // 监听端口（HTTPS 默认 443/9443）
    'worker_count' => 'auto',   // 'auto' 或具体数字
    'mode' => 'io',             // 'io' 或 'cpu'
    'https' => true,            // 启用 HTTPS（默认 true）
    'http_redirect_port' => 9980, // HTTP 重定向端口（可选，默认 = HTTPS端口 - 463）
],

// 多实例配置（可选）
'servers' => [
    'api' => [
        'host' => '127.0.0.1',
        'port' => 9001,
        'worker_count' => 4,
    ],
    'websocket' => [
        'host' => '0.0.0.0',
        'port' => 9002,
        'worker_count' => 2,
    ],
],
```

## 📊 命令参考

### server:start

启动服务器。

```bash
php bin/w server:start [name] [-p port] [-c count] [--host ip] [-d]
```

### server:stop

停止服务器。

```bash
# 停止默认实例
php bin/w server:stop

# 停止指定实例
php bin/w server:stop api-server

# 停止所有实例
php bin/w server:stop --all
```

### server:status

查看服务器状态（树形展示）。

```bash
# 查看所有实例
php bin/w server:status

# 查看指定实例
php bin/w server:status api-server
```

输出示例：

```
实例 [default] 状态

╔══════════════════════════════════════════════════════════════╗
║                    实例详细信息                                ║
╠══════════════════════════════════════════════════════════════╣
║  实例名称：default                                           ║
║  监听地址：http://127.0.0.1:9981                             ║
║  端口范围：9981 - 9984                                       ║
║  Worker 数：4                                                ║
╚══════════════════════════════════════════════════════════════╝

Worker 进程状态：

  ├─ Worker #1 (端口: 9981) ● 运行中
  │    └─ 内存：22.45 MB (PID: 28212)
  ├─ Worker #2 (端口: 9982) ● 运行中
  │    └─ 内存：22.48 MB (PID: 28524)
  ├─ Worker #3 (端口: 9983) ● 运行中
  │    └─ 内存：22.32 MB (PID: 28836)
  └─ Worker #4 (端口: 9984) ● 运行中
       └─ 内存：22.51 MB (PID: 29148)

状态：全部运行中 (4/4)
```

### server:benchmark

压力测试（自动探测运行中的服务器）。

```bash
# 自动探测服务器
php bin/w server:benchmark

# 自定义参数
php bin/w server:benchmark -c 500 -n 50000
```

参数说明：

| 参数 | 简写 | 说明 | 默认值 |
|-----|------|------|--------|
| `--concurrency` | `-c` | 并发数 | 100 |
| `--requests` | `-n` | 总请求数 | 10000 |
| `--path` | - | 请求路径 | / |
| `--port` | `-p` | 指定端口（可选） | 自动探测 |

## 🔧 性能优化

### 事件循环（最重要！）

Weline Server 支持多种事件循环，自动选择最优方案并优雅降级：

| 事件循环 | 性能 | 安装方式 | 说明 |
|---------|------|---------|------|
| **Event 扩展** | 30,000-50,000 QPS | `pecl install event` | 最优方案，推荐使用 |
| stream_select | 15,000-20,000 QPS | 无需安装 | 回退方案，纯 PHP |

#### 检测与优雅降级

```
启动时自动检测：
┌─────────────────────────────────────────────────────────────┐
│ 1. 检测 event 扩展 → 有则使用（最优性能）                     │
│ 2. 回退 stream_select → 始终可用（兼容性保证）                │
└─────────────────────────────────────────────────────────────┘
```

#### 安装 Event 扩展

**Linux/Mac:**
```bash
pecl install event
echo "extension=event" >> $(php --ini | grep "Loaded Configuration" | cut -d: -f2 | tr -d ' ')
```

**Windows:**
1. 从 [PECL](https://pecl.php.net/package/event) 下载对应版本的 `php_event.dll`
2. 复制到 PHP 的 `ext` 目录
3. 在 `php.ini` 中添加：`extension=event`

启动时系统会自动检测并提示优化建议：

### 推荐配置

| 配置项 | 推荐值 | 说明 |
|-------|--------|------|
| `opcache` 扩展 | 启用 | 提升 PHP 执行速度 50%+ |
| `opcache.enable_cli` | 1 | CLI 模式启用 OPCache |
| `opcache.jit` | tracing | PHP 8+ JIT 编译器 |
| `proc_open` 函数 | 启用 | 精确的进程管理 |
| `memory_limit` | 256M+ | 内存限制 |

### php.ini 配置示例

```ini
; OPCache 配置
opcache.enable=1
opcache.enable_cli=1
opcache.jit=tracing
opcache.jit_buffer_size=128M

; 内存限制
memory_limit=256M

; 移除禁用函数
; 从 disable_functions 中移除: proc_open, proc_close, proc_get_status
```

## 🏗️ 架构说明

### HTTPS 模式完整架构

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│                              WLS Server (HTTPS Mode)                            │
├─────────────────────────────────────────────────────────────────────────────────┤
│                                                                                 │
│  ┌────────────────────────────────────────────────────────────────────────┐    │
│  │                         Master Process (监控)                          │    │
│  │  • 监控所有 Worker 健康状态                                             │    │
│  │  • 自动重启异常退出的 Worker                                            │    │
│  │  • 管理 HTTP Redirect Worker 生命周期                                   │    │
│  │  • reload 时：只重载业务 Worker，不重载 HTTP Redirect Worker            │    │
│  │  • stop 时：关闭所有进程（包括 HTTP Redirect Worker）                    │    │
│  └────────────────────────────────────────────────────────────────────────┘    │
│                                      │                                          │
│           ┌──────────────────────────┼──────────────────────────┐              │
│           │                          │                          │              │
│           ▼                          ▼                          ▼              │
│  ┌─────────────────┐      ┌─────────────────┐      ┌─────────────────────────┐ │
│  │  HTTP Redirect  │      │   Dispatcher    │      │     Worker × N          │ │
│  │     Worker      │      │    (可选)       │      │                         │ │
│  │                 │      │                 │      │  • 处理实际业务请求      │ │
│  │  端口: 80/9980  │      │  端口: 443/9443 │      │  • 加载框架代码          │ │
│  │                 │      │                 │      │  • 端口: 10443+N        │ │
│  │  职责:          │      │  职责:          │      │                         │ │
│  │  • 监听 HTTP    │      │  • SSL 终结     │      │  reload 时:             │ │
│  │  • 返回 301     │      │  • 流量分发     │      │  • Worker 自行重载       │ │
│  │  • 重定向 HTTPS │      │  • 负载均衡     │      │                         │ │
│  │                 │      │                 │      │                         │ │
│  │  特点:          │      └────────┬────────┘      └─────────────────────────┘ │
│  │  ✗ 不加载框架   │               │                          ▲               │
│  │  ✓ 极低资源占用 │               │                          │               │
│  │  ✓ reload 时   │               └──────────────────────────┘               │
│  │    不需处理     │                        分发请求                           │
│  └─────────────────┘                                                           │
│                                                                                 │
└─────────────────────────────────────────────────────────────────────────────────┘
```

### 请求流向

```
  用户访问 HTTP:
  http://127.0.0.1:9980/catalog/...
           │
           ▼
  ┌─────────────────┐
  │  HTTP Redirect  │ ──────► HTTP 301 Moved Permanently
  │  Worker (:9980) │         Location: https://127.0.0.1:9443/catalog/...
  └─────────────────┘
           │
           ▼ (浏览器自动跳转)
  ┌─────────────────┐      ┌─────────────────┐      ┌─────────────────────────┐
  │  用户重新请求    │ ───► │   Dispatcher    │ ───► │    Worker (处理业务)    │
  │  HTTPS (:9443)  │      │   (:9443)       │      │    (:10443+N)           │
  └─────────────────┘      └─────────────────┘      └─────────────────────────┘
```

### 端口配置方案

| 协议模式 | HTTP Redirect | HTTPS 端口 | Worker 内部端口 |
|---------|---------------|-----------|----------------|
| 默认端口 | **80** → 443  | 443       | 10443 + N      |
| 备用端口 | **9980** → 9443 | 9443    | 19443 + N      |

### HTTP 模式简化架构

```
┌─────────────────────────────────────────────────────────────┐
│                      Master Process                          │
│                    (进程管理、信号处理)                        │
└─────────────────────────────────────────────────────────────┘
                              │
         ┌────────────────────┼────────────────────┐
         │                    │                    │
         ▼                    ▼                    ▼
┌─────────────────┐ ┌─────────────────┐ ┌─────────────────┐
│  Worker #1      │ │  Worker #2      │ │  Worker #N      │
│  Port: 9981     │ │  Port: 9982     │ │  Port: 998N     │
│  事件循环        │ │  事件循环        │ │  事件循环        │
│  连接管理        │ │  连接管理        │ │  连接管理        │
└─────────────────┘ └─────────────────┘ └─────────────────┘
```

### 内存缓存管理（智能模式）

WLS 内置智能内存缓存系统，采用冷热淘汰策略管理静态文件缓存。

```
┌──────────────────────────────────────────────────────────────────────┐
│                     Worker 内存缓存架构                               │
├──────────────────────────────────────────────────────────────────────┤
│                                                                       │
│   ┌─────────────────────────────────────────────────────────────┐   │
│   │                    静态文件缓存池                             │   │
│   │                                                               │   │
│   │   ┌─────────┐  ┌─────────┐  ┌─────────┐  ┌─────────┐       │   │
│   │   │ file.js │  │ app.css │  │ img.png │  │  ...    │       │   │
│   │   │ hits:50 │  │ hits:30 │  │ hits:5  │  │         │       │   │
│   │   │  HOT    │  │  WARM   │  │  COLD   │  │         │       │   │
│   │   └─────────┘  └─────────┘  └─────────┘  └─────────┘       │   │
│   │                                                               │   │
│   │   总容量: auto (系统内存 2%, 32MB-256MB)                       │   │
│   │   单文件上限: 1MB                                             │   │
│   │   淘汰阈值: 剩余 5MB 时开始淘汰冷数据                           │   │
│   └─────────────────────────────────────────────────────────────┘   │
│                                                                       │
│   淘汰策略: score = hits × 10 + recency_bonus                        │
│   recency_bonus = max(0, 100 - age_minutes)                          │
│                                                                       │
└──────────────────────────────────────────────────────────────────────┘
```

#### env.php 配置

```php
'server' => [
    'cache' => [
        'static_file_max_total' => 'auto',     // 'auto' 或 '100M' 或 数字
        'static_file_max_size' => '1M',        // 单文件上限
        'eviction_threshold' => 5242880,       // 5MB
    ],
],
```

#### 智能内存分配

| 系统内存 | 自动计算的缓存上限 |
|----------|-------------------|
| 2GB | 40MB |
| 4GB | 80MB |
| 8GB | 160MB |
| 16GB+ | 256MB（上限） |

#### 启动时内存检查

- 检查系统可用内存
- 不足时自动缩减缓存大小
- 严重不足（<50%需求）时拒绝启动

### 核心组件

| 组件 | 路径 | 说明 |
|-----|------|------|
| Worker | `Worker.php` | 核心 Worker 类 |
| Event Loop | `Event/Select.php` | 事件循环（stream_select） |
| Connection | `Connection/TcpConnection.php` | TCP 连接管理 |
| Protocol | `Protocol/Http.php` | HTTP 协议解析 |
| Timer | `Timer.php` | 定时器 |

### 共享内存服务（WLS 强一致）

本模块已支持统一共享内存服务（Session 与 Cache 统一接口），用于解决多 Worker 下状态不一致问题：

- 统一契约：`Shared/Contract/MemoryServiceInterface.php`
- 长连接复用：`Shared/Connection/ConnectionPoolManager.php`
- 协议客户端：`Shared/Client/SharedStateClient.php`
- 统一服务实现：`Shared/Service/SharedMemoryService.php`
- 领域包装：`Service/SessionMemoryService.php`、`Service/CacheMemoryService.php`

设计边界：

- 请求结束不主动断连，连接池在 Worker 进程级复用
- Session 不再走本地文件降级真值路径，统一以共享内存服务为准
- WLS 缓存主存储改为共享内存服务，避免跨 Worker 命中分裂

## 📝 开发指南

### 自定义 Worker

```php
use Weline\Server\Worker;

$worker = new Worker('http://0.0.0.0:8080');
$worker->count = 4;
$worker->name = 'MyHttpServer';

$worker->onMessage = function($connection, $request) {
    $connection->send('Hello World!');
};

Worker::runAll();
```

### 支持的协议

| 协议 | 类 | 说明 |
|-----|-----|------|
| HTTP | `Protocol\Http` | HTTP/1.1 协议 |
| WebSocket | `Protocol\WebSocket` | WebSocket 协议 |
| Text | `Protocol\Text` | 文本协议（换行符分隔） |

## 📁 目录结构

```
Weline/Server/
├── bin/                    # 可执行脚本
│   └── worker.php          # Worker 启动脚本
├── Connection/             # 连接管理
│   ├── ConnectionInterface.php
│   └── TcpConnection.php
├── Console/Server/         # CLI 命令
│   ├── Start.php           # server:start
│   ├── Stop.php            # server:stop
│   ├── Status.php          # server:status
│   ├── Benchmark.php       # server:benchmark
│   └── ...
├── Event/                  # 事件循环
│   ├── EventInterface.php
│   └── Select.php
├── Protocol/               # 协议解析
│   ├── Http.php
│   ├── WebSocket.php
│   └── ...
├── Service/                # 服务层
│   ├── HttpServer.php
│   └── ServerInstanceService.php
├── i18n/                   # 国际化
├── Test/                   # 测试
├── Worker.php              # 核心 Worker 类
├── Timer.php               # 定时器
└── register.php            # 模块注册
```

## 🔗 相关文档

- [Weline Framework 官方文档](https://weline.cc/docs)

## 📄 许可证

MIT License
