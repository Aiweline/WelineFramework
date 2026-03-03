# WLS IPC 控制通道架构

## 概述

WLS（Weline Server）使用 TCP 控制通道实现 Master、Dispatcher、Worker、HTTP Redirect Worker 之间的进程间通信（IPC）。所有进程控制信号（重载、停止、排水、缓存清理等）均通过此通道传递，不使用文件信号。

## 架构图

```
┌──────────────────────────────────────────────────────────────────────┐
│                     Control Plane (TCP 控制通道)                      │
│                                                                      │
│  ┌─────────────────────────────┐                                     │
│  │  Master Control Server      │◄──── CLI (server:stop/reload/...)   │
│  │  port: control_port         │                                     │
│  └──┬──────┬──────┬──────┬─────┘                                     │
│     │      │      │      │                                           │
│     ▼      ▼      ▼      ▼                                           │
│  Worker1 Worker2 Disp  Redirect                                      │
│                                                                      │
└──────────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────────┐
│                     Data Plane (用户流量)                             │
│                                                                      │
│  Client ──► Dispatcher :443 ──┬──► Worker1 :19981                    │
│                               └──► Worker2 :19982                    │
│  Client ──► HTTP Redirect :80 ──► 301 → https://...                  │
│                                                                      │
└──────────────────────────────────────────────────────────────────────┘
```

## 通信协议

### 格式：NDJSON（Newline-Delimited JSON）

每条消息为一行 JSON 字符串 + `\n` 换行符。示例：

```
{"type":"register","role":"worker","pid":12345,"port":19981,"worker_id":1}\n
{"type":"ack","resurrection_priority":3}\n
```

### 消息类型

| 类型 | 方向 | 说明 |
|------|------|------|
| `register` | 子进程 → Master | 进程启动后注册身份（角色、PID、端口） |
| `ack` | Master → 子进程 | 注册确认，附带复活优先级 |
| `ready` | 子进程 → Master | 框架初始化 + 端口监听完成，可接收流量 |
| `shutdown` | Master → 子进程 | 通知优雅退出（主动终结） |
| `reload` | Master → Worker | 通知代码重载（Worker 需优雅退出后重启） |
| `cache_clear` | Master → Worker | 通知清缓存（原地执行，不重启） |
| `drain` | Master → Dispatcher | 将指定端口加入黑名单，不再路由新流量 |
| `undrain` | Master → Dispatcher | 将指定端口从黑名单移除，恢复路由 |
| `add_worker` | Master → Dispatcher | 动态添加 Worker 端口到负载均衡池 |
| `remove_worker` | Master → Dispatcher | 从负载均衡池移除端口 |
| `draining_complete` | Worker → Master | Worker 处理完所有请求，准备退出 |
| `status_report` | 子进程 → Master | 上报运行状态（连接数、内存、请求量） |
| `command` | CLI → Master | CLI 命令（stop/reload/cache_clear/status） |
| `command_result` | Master → CLI | CLI 命令执行结果 |

### 消息格式详情

```json
// register
{"type":"register","role":"worker|dispatcher|redirect|maintenance","pid":123,"port":19981,"worker_id":2}

// ack
{"type":"ack","resurrection_priority":0}
// resurrection_priority: 0=不参与复活, 1=HTTP Redirect(1秒), 2=Dispatcher(3秒), 3=Worker#1(6秒)

// ready
{"type":"ready","role":"worker","worker_id":2,"port":19981}

// shutdown
{"type":"shutdown","reason":"server:stop"}

// reload
{"type":"reload","reload_type":"code"}

// cache_clear
{"type":"cache_clear"}

// drain / undrain
{"type":"drain","ports":[19981]}
{"type":"undrain","ports":[19981]}

// add_worker / remove_worker
{"type":"add_worker","ports":[19990]}
{"type":"remove_worker","ports":[19990]}

// draining_complete
{"type":"draining_complete","worker_id":2,"port":19981}

// status_report
{"type":"status_report","connections":5,"memory":1234567,"requests":100}

// command (CLI)
{"type":"command","action":"stop|reload|cache_clear|status","reload_type":"code|cache"}

// command_result
{"type":"command_result","success":true,"data":{}}
```

## 控制端口

- 默认值：`main_port + 10000`（如主端口 443 → 控制端口 10443）
- 可通过 `env.php` 的 `server.control_port` 覆盖
- 控制端口写入 `var/server/instances/{name}.json`，供 CLI 命令和子进程读取
- 仅监听 `127.0.0.1`（本机回环），不暴露到外部

## Worker 生命周期状态

```
进程启动
   │
   ▼
[registered] ── connect Master + 发送 register
   │
   ▼
框架初始化 + 端口监听
   │
   ▼
[ready] ── 发送 ready → Master 通知 Dispatcher undrain → 接收流量
   │
   ▼ (收到 reload)
[draining] ── 关闭监听 socket，处理剩余连接
   │
   ▼ (所有连接处理完)
[draining_complete] ── 发送 draining_complete → 优雅退出
```

## 重载策略

### 缓存重载（cache）

原地清理，零停机，不重启：
1. Master 广播 `cache_clear` 给所有 Worker
2. Worker 执行 `opcache_reset()` + 清静态缓存 + 清对象缓存
3. 完成

### 代码重载（code）—— 滚动重启

逐个重启 Worker，保证始终有 Worker 在线：

1. Master 收到 `reload(code)` 命令
2. 对每个 Worker 依次执行：
   a. 发送 `drain(port)` 给 Dispatcher → 停止向该 Worker 路由新流量
   b. 发送 `reload` 给 Worker → Worker 关闭监听 socket
   c. Worker 处理完所有进行中的请求
   d. Worker 发送 `draining_complete` → 优雅退出
   e. Master 启动新 Worker
   f. 新 Worker 发送 `register` + `ready`
   g. Master 发送 `undrain(port)` 给 Dispatcher → 恢复路由
3. 所有 Worker 重启完成

### 单 Worker / 全 Worker 不可用 —— 维护 Worker 兜底

当没有可用 Worker 时，Master 启动维护 Worker：
- 维护 Worker 数量 = `ceil(正常 Worker 数 / 10)`，至少 1 个
- 维护 Worker 启动后自动开启框架维护模式
- 正常 Worker 恢复后，Master 关闭维护 Worker

### 三级降级链

```
正常 Worker（业务响应）
    │ 不可用
    ▼
维护 Worker（框架维护页 503）
    │ 也不可用
    ▼
Dispatcher 硬编码维护页（纯内存 503）
```

只要 Dispatcher 活着，用户就能看到维护页而不是浏览器连接拒绝错误。

## Master 复活机制

### 区分主动终结和意外死亡

- **主动终结**：Master 先发送 `shutdown` 消息，再断开 TCP → 子进程收到 shutdown 后优雅退出，不复活
- **意外死亡**：Master 进程崩溃/被杀 → TCP 连接异常断开，子进程未收到 shutdown → 触发复活

### 复活优先级

| 优先级 | 角色 | 延迟 | 说明 |
|--------|------|------|------|
| 1 | HTTP Redirect Worker | 1 秒 | 最轻量，不处理业务流量 |
| 2 | Dispatcher | 3 秒 | 次选，有全局视角 |
| 3 | Worker #1 | 6 秒 | 兜底 |
| 0 | Worker #2+ | 不参与 | 只等待新 Master 上线后重连 |

### 复活流程

1. 子进程检测到控制连接断开且未收到 `shutdown`
2. 按优先级等待指定秒数
3. 检查控制端口是否已有人在监听（Master 已被更高优先级进程复活）
4. 如果无人监听 → 启动新 Master 进程
5. 所有子进程重新连接新 Master 并 register

## 日志系统

所有进程使用 `WlsLogger` 统一管理日志：
- **自动日志级别**：支持 DEBUG、INFO、NOTICE、WARNING、ERROR、FATAL
- **缓冲输出**：缓冲到内存，每 5 秒批量写磁盘
- **即时刷新**：ERROR/FATAL 级别立即刷新
- **进程退出**：自动 flush 所有缓冲日志
- **配置**：通过 `env.php` 的 `wls.log` 配置

### env.php 日志配置示例

```php
return [
    'wls' => [
        'log' => [
            'enabled' => true,               // 是否启用日志
            'path' => 'var/log/wls/',        // 日志目录
            'level' => 'INFO',               // 最小日志级别
            // 开发环境：DEBUG（记录所有）
            // 生产环境：WARNING（只记录警告和错误）
            'stdout' => 'auto',              // 终端输出：auto | true | false
            'rotate' => 'daily',             // 日志轮转：daily | size | none
            'max_files' => 7,                // 保留文件数
            'max_size' => 52428800,          // 单文件最大 50MB
        ],
    ],
];
```

### 日志级别说明

| 级别 | 环境 | 说明 |
|------|------|------|
| DEBUG | 开发 | 详细调试信息，包括 IPC 消息、连接状态 |
| INFO | 开发 | 一般信息，启动/停止/重载等事件 |
| WARNING | 生产 | 警告，非致命问题但需关注 |
| ERROR | 生产 | 错误，需要处理的问题 |
| FATAL | 生产 | 致命错误，进程崩溃 |

**生产环境推荐配置**：`'level' => 'WARNING'`，只记录警告和错误，减少磁盘 I/O

## 文件结构

```
app/code/Weline/Server/
├── Log/
│   ├── WlsLogger.php           # 统一日志器（单例）
│   ├── LogLevel.php            # 日志级别定义
│   ├── LogConfig.php           # 日志配置读取
│   ├── Error/                  # 错误捕获层
│   │   ├── ErrorBootstrap.php  # 错误捕获初始化
│   │   ├── ErrorHandler.php    # Layer 1: set_error_handler
│   │   ├── ExceptionHandler.php# Layer 2: set_exception_handler
│   │   ├── ShutdownHandler.php # Layer 3: register_shutdown_function
│   │   ├── ErrorContext.php    # 进程上下文
│   │   └── ErrorCollector.php  # 错误收集与格式化
│   └── Master/                 # Master 层捕获
│       ├── PipeCapture.php     # Layer 4: 子进程输出捕获
│       ├── ProcessMonitor.php  # Layer 5: 进程异常退出检测
│       └── LogAggregator.php   # 日志聚合
├── IPC/
│   ├── ControlMessage.php      # NDJSON 协议编解码 + 消息类型常量
│   ├── ControlClient.php       # 子进程端 TCP 控制客户端
│   ├── MasterControlServer.php # Master 端 TCP 控制服务器
│   └── MasterResurrector.php   # Master 复活逻辑
├── bin/
│   ├── worker.php              # HTTP Worker（集成 ControlClient）
│   ├── worker_ssl.php          # HTTPS Worker（集成 ControlClient）
│   ├── http_redirect_worker.php# HTTP→HTTPS 重定向（集成 ControlClient）
│   └── dispatcher.php          # Dispatcher 入口
├── Dispatcher/
│   └── Dispatcher.php          # Dispatcher（集成 ControlClient）
└── Service/
    └── MasterProcess.php       # Master 进程（集成 MasterControlServer）
```
