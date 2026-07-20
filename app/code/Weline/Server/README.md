# Weline Server 模块

高性能异步常驻内存 HTTP 服务器，支持跨平台多进程架构。

## 📦 模块信息

- **模块名**: `Weline_Server`
- **类型**: 基础设施模块
- **协议支持**: HTTP/1.1、HTTP/2、HTTP/3（可选原生组件）、WebSocket、TCP、UDP

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
| 高性能 | 常驻内存、多 Worker 和可选 libevent 事件循环；实际 QPS 以本机 `server:benchmark` 为准 |
| 跨平台 | 支持 Windows/Linux/Mac |

#### 启动命令

```bash
# 默认启动（智能模式）
php bin/w server:start

# Linux/macOS 显式切换 Dispatcher 对照/兼容
php bin/w server:start --dispatcher

# Linux/macOS 显式 direct（auto 已默认直连）
php bin/w server:start --direct

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
| `--port` | `-p` | 监听端口 | 80（HTTP）/443（HTTPS）；9981 为常用自定义回退端口 |
| `--host` | `-h` | 监听地址 | 127.0.0.1 |
| `--count` | `-c` | Worker 进程数 | 智能推算 |
| `--daemon` | `-d` | 守护进程模式 | false |
| `--direct` | - | 显式 direct（Linux 使用 SO_REUSEPORT，macOS 使用 Master 共享监听 FD） | POSIX `auto` 已选择 |
| `--dispatcher` | - | 显式 Dispatcher TCP 透传 | Windows `auto` 已选择 |

#### 平台拓扑

| 平台 | `auto` 结果 | 说明 |
|---|---|---|
| Windows | Dispatcher | Windows 只支持 Dispatcher 透传；`auto` 与显式 `--dispatcher` 可用，`--direct` 在启动前拒绝 |
| Linux | Direct | 通过 ext-event、SO_REUSEPORT 真实双监听 accept 分布和策略能力检查后，客户端直达 Worker |
| macOS | Direct | 通过共享 listener FD 与真实 accept 分布探测后，Master 只 bind 一个公开 listener 并交给全部 Worker |
| 其他系统 | 不启动 | 没有受支持的平台驱动时，在创建 Master/Worker 前明确失败，不回退到兼容拓扑 |

Direct 时不启动 Dispatcher，不创建第二条 Worker 后端连接。两种拓扑都在 Worker 加载同一 RuntimePolicyBundle，因此 Host、后台 Key、Origin Token、安全规则、限流、Static/FPC 和维护模式不会因 direct 而失效。POSIX direct 能力检查失败时停止启动，需运维明确使用 `--dispatcher`，不静默降级。

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
    'port' => 9443,             // 显式示例端口；未配置时 HTTP 80 / HTTPS 443
    'worker_count' => 'auto',   // 'auto' 或具体数字
    'mode' => 'io',             // 'io' 或 'cpu'
    'https' => true,            // 启用 HTTPS（默认 true）
    'http_redirect_port' => 9980, // HTTP 重定向端口（可选，默认 = HTTPS端口 - 463）
],

'wls' => [
    'runtime' => [
        'topology' => 'auto',  // auto/direct/dispatcher；其他值启动前拒绝
    ],
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

# 仅在运维明确允许本次安装副作用时使用
php bin/w server:start [name] --install-deps
```

普通 `server:start` 只探测当前 PHP 与平台能力，不安装或编译。`--install-deps` 可能联网、运行平台包管理器/PECL 并修改 PHP 配置。

### server:http3:build

显式准备 macOS/Linux 可选 HTTP/3 原生组件；当前 PHP 必须预装并启用 FFI，本命令不会安装 FFI 或修改 `ffi.enable`，普通启动也不会调用此命令。

```bash
php bin/w server:http3:build
php bin/w server:http3:build --ssl-cert /path/to/cert.pem --ssl-key /path/to/key.pem
```

Linux 产物把固定摘要依赖封装为私有 PIC-static transport。macOS 产物会把所有非系统 dylib 快照到 owner-only、内容寻址目录，改写为 `@loader_path` 闭包、删除 `LC_RPATH` 并逐个 ad-hoc codesign；新 PHP 子进程还会核对实际 dyld loaded-image 路径并完成真实 QUIC/TLS/Ticket 自检。schema-3 运行证据同时绑定自检/编译器和生产 Worker、响应批处理、Alt-Svc、Ticket ring、READY、SSL Worker、Orchestrator 代码；Darwin 还绑定 Router 与运行身份代码。任一集成文件变化都会使旧证据失效。候选只有在这些检查全部成功后才会原子发布为 active，普通 `server:start` 始终只读复用。

### TLS Session 恢复边界

PHP 8.4 的 Stream SSL 服务端没有可供纯 PHP 使用的外部 Session Cache 回调；该版本已验证的是同一连接上的 HTTP/1.1 Keep-Alive、HTTP/2 多路复用和连接级 TLS 复用，不能描述为跨连接或跨 Worker Session 恢复。WLS 已接入 PHP 8.6 OpenSSL Stream 回调的纯 PHP 外部有状态 Session Cache：独立有界 RAM 存储、SNI/证书/context 隔离、fail-fast 预连接客户端、reload 连续性和 sidecar 自愈；它不同于 HTTP/3 原生数据面的无状态 Ticket Key Ring，也不要求另行编译 WLS 原生协议组件。这里的“纯 PHP”只描述 WLS 实现层；PHP/ext-openssl 本身仍是预编译运行时，并且必须暴露 PHP 8.6 的 Session Cache 回调 API。该 TCP 能力默认关闭，显式配置在不支持的 PHP 上会于监听前拒绝。Windows 11 ARM64 上的 PHP 8.6.0alpha2 x64 仿真已通过同 Worker、跨 Worker、reload 与 sidecar 恢复功能证据；最新绑定证据的恢复握手 P95 为 156.236ms，未通过固定生产门禁 P95 ≤ 50ms。该二进制不是 Windows ARM64 原生执行，PHP 又是预发布版本，稳定 macOS/Linux/Windows 原生矩阵仍未完成，因此不得标记 `active_runtime_verified` 或 `production_ready`。

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

指定实例的详细状态只接受 endpoint schema v4，并从嵌套 `runtime_selection` 显示 requested/effective topology、listener mode、event loop、SSL engine、policy compatibility 与完整 digest。旧 endpoint schema、缺失 `runtime_selection` 或根级 topology/listener/event/SSL 投影都会 fail closed；状态命令不重新推导或补写拓扑。

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
# 仅有一个可验证的运行实例时自动选择
php bin/w server:benchmark

# 推荐：精确指定实例，安全归因运行时元数据
php bin/w server:benchmark --instance api-server

# 自定义参数
php bin/w server:benchmark --instance api-server -c 500 -n 50000
```

参数说明：

| 参数 | 简写 | 说明 | 默认值 |
|-----|------|------|--------|
| `--concurrency` | `-c` | 并发数 | 100 |
| `--requests` | `-n` | 总请求数 | 10000 |
| `--path` | - | 请求路径 | `/_wls/health` |
| `--instance` | - | 精确指定运行实例；读取 endpoint schema v4 归因到 Benchmark report schema v4 | - |
| `--port` | `-p` | 指定端口（可选） | 自动探测 |
| `--no-keepalive` | - | 强制 fresh connection；HTTPS 时同时代表 fresh TLS | false |

压测开始后会立即输出首批请求的真实状态，不必等到 10% 才看到进度；运行中约每 0.5 秒刷新一次完成数、活动请求句柄、已发送数、耗时和实时 QPS，最后一次完成会强制刷新。进度只统计实际完成/失败的请求，不按时间模拟。

报告保存到 `var/log/wls/benchmark_report_*.json`。这里的 **Benchmark report schema v4** 仅是压测报告格式，不是实例 endpoint 版本；实例归因必须先通过 **endpoint schema v4** 的嵌套 `runtime_selection` 校验。报告还记录 `target_attribution`、endpoint/runtime selection 校验结果、requested/effective topology、listener、event loop、SSL engine、Worker 数、policy compatibility/digest、keep-alive/fresh TLS 和响应观测到的 cache source。`qps`/“完成 QPS”按所有已完成请求（成功和失败）计算，`success_qps`/“成功 QPS”单独表示成功吞吐；`latency_ms` 默认覆盖所有已完成请求，因此 HTTP 错误和 curl 超时也会有真实耗时，不再在全失败时显示为 0。手动 `-p` 只有在 host/port（以及显式 SSL 要求）唯一匹配运行中的本地 endpoint 时才归因实例；零匹配或多匹配仍可压明确端口，但运行时字段保持 `null`。有多个运行实例且未指定目标时，命令直接拒绝自动选择，避免误压生产实例。自动唯一选择、显式 `--instance` 和唯一端口归因都会先校验 Master、全部 Worker 和登记服务的健康状态；实例未就绪时直接拒绝压测。

## 🔧 性能优化

### 事件循环（最重要！）

Weline Server 支持多种事件循环。普通 `server:start` 只检查当前 PHP，绝不下载、安装、编译或修改 PHP 配置。Linux/macOS Direct 缺少必需的 `sockets` / `ext-event` 时会在创建 Master/Worker 前停止；只有运维显式传入 `--install-deps`，本次启动才允许调用 `env:install` 并用新 PHP 进程复验。

| 事件循环 | 性能 | 安装方式 | 说明 |
|---------|------|---------|------|
| **Event 扩展** | libevent 驱动，收益取决于路由与业务负载 | 预装，或显式运行 `server:start --install-deps` | Linux/macOS Direct 默认要求；安装后会使用当前 PHP 验证 |
| stream_select | 兼容性基线 | 无需安装 | Dispatcher 缺少 ext-event 时使用；Direct 不会静默改写为 select |

#### 检测与优雅降级

```
启动依赖决策：
┌─────────────────────────────────────────────────────────────┐
│ 1. 普通启动 → 只读探测，不安装、不编译、不修改 PHP       │
│ 2. Direct 依赖齐全 → 直接使用 libevent                    │
│ 3. Direct 依赖缺失 → 停止并给出缺失项                     │
│ 4. 显式 --install-deps → 安装、用新 PHP 复验、继续启动    │
│ 5. Dispatcher 缺 event → 保持 Dispatcher + bounded select │
└─────────────────────────────────────────────────────────────┘
```

#### 安装 Event 扩展

**Linux/macOS（手动预装，适合镜像构建）:**
```bash
php bin/w env:install event -y
```

**Windows:**
1. 只使用与当前 PHP 版本、架构、TS/NTS 和编译器 ABI 全部匹配的 `php_event.dll`。
2. 普通启动只探测当前 PHP 已加载的扩展；只有显式 `--install-deps` 才会尝试启用并用新 PHP 子进程验证 `extension_dir` 中已有的匹配 DLL。
3. 没有可验证 DLL 时使用 Windows 稳定兼容运行时；框架不会自动下载不明 ABI 的二进制文件。

生产镜像建议在构建阶段执行 `env:install` 并预装依赖。普通启动默认已经禁止安装；`--no-auto-deps` 仅保留给旧脚本表达同一默认行为，不能与 `--install-deps` 同时使用。HTTP/3 不属于普通 Direct 依赖；当前 PHP 必须预装并启用 FFI，显式 `php bin/w server:http3:build` 只准备原生工具链和可信组件，不安装或注入 FFI。

### 推荐配置

| 配置项 | 推荐值 | 说明 |
|-------|--------|------|
| `opcache` 扩展 | 启用 | 提升 PHP 执行速度 50%+ |
| `opcache.enable_cli` | 原生运行时为 1 | Windows ARM64 + x64 PHP 仿真由 WLS 托管档案自动设为 0；其他平台启用字节码缓存 |
| `opcache.jit` | 按同机基准决定 | 原生受支持运行时可测试 tracing；Windows ARM64 + x64 PHP 仿真必须关闭 |
| `proc_open` 函数 | 启用 | 精确的进程管理 |
| `memory_limit` | 256M+ | 内存限制 |

### php.ini 配置示例

```ini
; OPCache 配置
opcache.enable=1
opcache.enable_cli=1

; JIT 仅在原生受支持运行时按同机 benchmark 决定是否启用。
; Windows ARM64 + x64 PHP 仿真下，WLS 托管档案会对后续 PHP 进程使用
; opcache.enable_cli=0、opcache.jit=off、opcache.jit_buffer_size=0；不改写全局 php.ini。
; opcache.jit=tracing
; opcache.jit_buffer_size=64M

; 内存限制
memory_limit=256M

; 移除禁用函数
; 从 disable_functions 中移除: proc_open, proc_close, proc_get_status
```

## 🏗️ 架构说明

### 当前跨平台数据面

```mermaid
flowchart LR
  MASTER["Master / Registry\n生命周期 + policy publish"] --> WORKER["Worker x N\nWorkerPolicyKernel + Runtime"]
  MASTER --> DISP["Dispatcher\nWindows 默认 / POSIX 显式"]
  WIN["Windows Client"] --> DISP
  DISP -->|"PROXY v2 + TCP/TLS 字节透传"| WORKER
  LINUX["Linux Client"] -->|"SO_REUSEPORT direct"| WORKER
  MAC["macOS Client"] -->|"shared listener FD direct"| WORKER
  WORKER --> CACHE["Static L1 / FPC Process L1 + Shared L2"]
  WORKER --> APP["Router / Controller / Response"]
```

- Windows 只使用 Dispatcher 透传。Dispatcher 负责 L4 准入、READY Worker 选择、背压和 failover，TLS/HTTP 语义由 Worker 处理。
- Linux `auto` 由 SO_REUSEPORT Worker 共享公开端口；macOS `auto` 由 Master 绑定一个 listener 并将同一 FD 继承给 Worker。两者都没有 Dispatcher、后端连接或字节透传；可用 `--dispatcher` 显式切换对照。
- Worker 在两种拓扑中都先执行 mandatory guard，再命中 Static/FPC，最后才进入 Session、Router 和 Controller。
- 策略、缓存 epoch 和维护 epoch 由 Master 版本化发布；Worker active digest 不匹配时不得 READY。

完整组件、时序与请求顺序见 [WLS 运行时架构](doc/WLS架构图.md) 和 [WLS 安全与规则配置推演](doc/WLS安全与规则配置推演.md)。


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
| HTTP/1.1 | `Protocol\Http` | HTTP/1.1，作为 HTTP/2/HTTP/3 不可用时的自动回退 |
| HTTP/2 | `Protocol\Http2\ConnectionAdapter` | HTTPS 默认目标，支持 Keep-Alive 与多路复用 |
| HTTP/3 | `Protocol\Http3\WorkerQuicRuntime` | 可选原生 QUIC 数据面；仅显式构建且运行证据就绪时自动协商 |
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
│   ├── Http3/Build.php     # server:http3:build（显式可选构建）
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

- [WLS 实例隔离机制（核验版）](doc/WLS实例隔离机制.md)
- [Weline Framework 官方文档](https://weline.cc/docs)

## 📄 许可证

MIT License
