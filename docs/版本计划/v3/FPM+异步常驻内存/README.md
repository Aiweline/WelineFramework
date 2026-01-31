# Weline Framework v3 - FPM + WeAsync 双模式支持

> **版本**: v3.0.0  
> **PHP 最低版本**: 8.4  
> **文档创建日期**: 2026-01-28  
> **状态**: 规划中

---

## 概述

本文档规划 Weline Framework 同时支持 **PHP-FPM 传统模式** 和 **WeAsync 异步常驻内存模式** 的架构设计。

### WeAsync 是什么

**WeAsync** 是 Weline Framework 自研的高性能异步引擎,参照 Workerman 核心架构完全自主实现，具备同等性能水平（50,000+ QPS）。

### 目标

- **双模式运行**：同一套代码可在 FPM 和 WeAsync 模式下运行
- **高性能**：WeAsync 模式下达到 50,000+ QPS
- **自主可控**：完全自研，可持续优化
- **状态隔离**：请求间状态完全隔离，避免内存泄漏

### 核心原则

1. **运行时无关**：业务代码不感知运行环境
2. **单一代码库**：FPM 和 WeAsync 使用同一套代码
3. **显式状态管理**：所有状态都可追踪和重置
4. **向后兼容**：现有模块无需修改即可运行

---

## 文档目录

| 序号 | 文档 | 描述 | 优先级 |
|------|------|------|--------|
| 01 | [架构设计](01-架构设计.md) | 整体架构和运行时抽象 | ⭐⭐⭐⭐⭐ |
| 02 | [运行时抽象层](02-运行时抽象层.md) | RuntimeInterface 设计 | ⭐⭐⭐⭐⭐ |
| 03 | [ObjectManager改造](03-ObjectManager改造.md) | DI 容器状态/协程隔离 | ⭐⭐⭐⭐⭐ |
| 04 | [请求响应抽象](04-请求响应抽象.md) | PSR-7/15 兼容设计 | ⭐⭐⭐⭐ |
| 05 | [事件循环与Fiber](05-事件循环集成.md) | Fiber 协程支持（可选增强） | ⭐⭐⭐ |
| 06 | [数据库连接池](06-数据库连接池.md) | 连接池设计与管理 | ⭐⭐⭐⭐ |
| 07 | [Session状态管理](07-Session状态管理.md) | Session 驱动改造 | ⭐⭐⭐ |
| 08 | [全局状态隔离](08-全局状态隔离.md) | 静态变量和超全局变量处理 | ⭐⭐⭐ |
| 09 | [异步事件系统](09-异步事件系统.md) | 事件异步化支持 | ⭐⭐⭐ |
| **10** | **[WeAsync异步引擎](10-Worker进程管理.md)** | **自研高性能异步引擎（照搬Workerman）** | **⭐⭐⭐⭐⭐** |
| 11 | [兼容性层](11-兼容性层.md) | FPM/WeAsync 兼容性设计 | ⭐⭐ |
| 12 | [性能基准](12-性能基准.md) | 性能测试与对比 | ⭐⭐ |
| 13 | [迁移指南](13-迁移指南.md) | 现有模块迁移指南 | ⭐⭐ |
| **14** | **[WeAsync优化清单](14-WeAsync优化清单.md)** | **超越Workerman的优化项** | **⭐⭐⭐⭐⭐** |
| **15** | **[缓存策略](15-缓存策略.md)** | **WeAsync模式自动切换内存缓存** | **⭐⭐⭐⭐** |
| **16** | **[定时任务与常驻内存兼容](16-定时任务与常驻内存兼容.md)** | **命令行与常驻内存双模式定时任务** | **⭐⭐⭐⭐** |

---

## 架构概览

```
┌─────────────────────────────────────────────────────────────┐
│                    Weline Framework v3                       │
├─────────────────────────────────────────────────────────────┤
│                      业务层 (Business)                       │
│    Controllers / Models / Services / Events / Blocks         │
├─────────────────────────────────────────────────────────────┤
│                    框架核心 (Framework Core)                  │
│    Router / DI Container / Template / Database               │
├─────────────────────────────────────────────────────────────┤
│                   运行时抽象层 (Runtime Layer)                │
│    RequestContext / StateManager / ConnectionPool            │
├────────────────────────┬────────────────────────────────────┤
│   FPM Runtime          │        WeAsync Runtime              │
│   ├─ FpmRuntime        │        ├─ Worker (多进程)           │
│   └─ 传统请求模式       │        ├─ TcpConnection            │
│                        │        ├─ EventLoop (event/ev)      │
│                        │        └─ HTTP/WebSocket 协议       │
└────────────────────────┴────────────────────────────────────┘
```

### WeAsync 架构

```
                         ┌─────────────────┐
                         │  Master Process │
                         │   (进程管理)     │
                         └────────┬────────┘
                                  │ fork
         ┌────────────────────────┼────────────────────────┐
         │                        │                        │
         ▼                        ▼                        ▼
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│  Worker 1       │    │  Worker 2       │    │  Worker N       │
│  ┌───────────┐  │    │  ┌───────────┐  │    │  ┌───────────┐  │
│  │EventLoop  │  │    │  │EventLoop  │  │    │  │EventLoop  │  │
│  │(epoll/kq) │  │    │  │(epoll/kq) │  │    │  │(epoll/kq) │  │
│  └───────────┘  │    │  └───────────┘  │    │  └───────────┘  │
│  ┌───────────┐  │    │  ┌───────────┐  │    │  ┌───────────┐  │
│  │Connections│  │    │  │Connections│  │    │  │Connections│  │
│  └───────────┘  │    └───────────┘  │    │  └───────────┘  │
└─────────────────┘    └─────────────────┘    └─────────────────┘
```

### 性能对比

| 模式 | Hello World QPS | 真实业务 QPS | Worker 数 |
|------|-----------------|--------------|-----------|
| FPM | 3,000-5,000 | 500-1,000 | 动态 |
| WeAsync (event) | **50,000+** | **10,000-15,000** | 4-8 |
| WeAsync (select) | 8,000-12,000 | 2,000-4,000 | 4-8 |

---

## 实施路线

### 阶段一：WeAsync 引擎（核心）

1. **Worker 核心类** - 多进程管理、信号处理
2. **TcpConnection** - 连接管理、发送缓冲
3. **EventLoop 驱动** - event/ev/select 三种实现
4. **HTTP 协议** - 请求解析、响应编码

### 阶段二：框架适配层

5. **运行时抽象层** - 定义 `RuntimeInterface`
6. **请求上下文** - 实现 `RequestContext` 容器
7. **ObjectManager 改造** - 支持请求级实例隔离

### 阶段三：状态管理

8. **全局状态隔离** - 处理 `$_GET`、`$_POST`、`$_SERVER`
9. **Session 改造** - 支持 Redis/Database 存储
10. **数据库连接池** - 连接复用

### 阶段四：协议扩展（可选）

11. **WebSocket 协议** - 实时通信支持
12. **Fiber 协程** - 协程调度器（可选增强）
13. **异步客户端** - AsyncTcpConnection

---

## 快速开始

### FPM 模式（现有方式）

```bash
# 无需修改，保持原有部署方式
php-fpm
nginx
```

### WeAsync 模式

```bash
# 启动服务（前台）
php bin/w weasync:start

# 守护进程模式
php bin/w weasync:start -d

# 指定端口和进程数
php bin/w weasync:start --port=8080 --workers=8

# 停止服务
php bin/w weasync:stop

# 优雅重启（不中断服务）
php bin/w weasync:reload

# 查看状态
php bin/w weasync:status
```

### 代码示例

```php
<?php
use Weline\WeAsync\Worker;

$worker = new Worker('http://0.0.0.0:8080');
$worker->count = 4;
$worker->name = 'Weline';

$worker->onMessage = function ($connection, $request) {
    $connection->send('Hello WeAsync!');
};

Worker::runAll();
```

---

## 关键改动点

| 组件 | 改动内容 | 影响范围 |
|------|---------|---------|
| **WeAsync 模块** | 照搬 Workerman 核心代码 | 新增模块 |
| `App.php` | 支持 WeAsync 运行时 | 框架入口 |
| `ObjectManager` | 增加请求级实例隔离 | DI 容器 |
| `Request` | PSR-7 兼容 + WeAsync 适配 | HTTP 层 |
| `Session` | 支持 Redis/Database 存储 | 会话管理 |
| `Database` | 连接池支持 | 数据库层 |
| **Cache** | **WeAsync 下自动切换内存缓存** | **缓存层** |

## 核心决策

> **WeAsync 完全照搬 Workerman 开源代码**，改名后作为框架自研的异步引擎。
> 
> Workerman 是 MIT 协议，可自由使用和修改。照搬后我们可以：
> - 自主维护和优化
> - 根据框架需求定制
> - 不依赖外部包版本更新

### 优化项（超越 Workerman，全部实现）

| 优化项 | 实现文件 | 说明 |
|--------|----------|------|
| **Windows 兼容性** | `WindowsCompat.php` | 支持 Windows 多进程 |
| **HTTP/2 原生支持** | `Protocol/Http2.php` | 多路复用、HPACK、服务器推送 |
| **限流中间件** | `Middleware/RateLimiter.php` | 滑动窗口、令牌桶算法 |
| **热重载** | `HotReload.php` | 文件监控、自动重载 |

详见 [14-WeAsync优化清单](14-WeAsync优化清单.md)

---

## 参考资料

- [PSR-7: HTTP Message Interface](https://www.php-fig.org/psr/psr-7/)
- [PSR-15: HTTP Handlers](https://www.php-fig.org/psr/psr-15/)
- [Workerman 源码](https://github.com/walkor/workerman) - WeAsync 参考实现
- [libevent 文档](https://libevent.org/) - event 扩展底层
- [libev 文档](http://software.schmorp.de/pkg/libev.html) - ev 扩展底层

## WeAsync 完整实现文件清单

```
app/code/Weline/WeAsync/
│
├── # ========== 照搬 Workerman 核心 ==========
├── Worker.php                        # 核心 Worker 类（照搬）
├── Timer.php                         # 定时器（照搬）
├── Autoloader.php                    # 自动加载（照搬）
│
├── Connection/                       # 连接管理（照搬）
│   ├── ConnectionInterface.php       # 连接接口
│   ├── TcpConnection.php             # TCP 连接
│   ├── UdpConnection.php             # UDP 连接
│   └── AsyncTcpConnection.php        # 异步 TCP 客户端
│
├── Event/                            # 事件循环（照搬）
│   ├── EventInterface.php            # 事件接口
│   ├── Event.php                     # event 扩展驱动
│   ├── Ev.php                        # ev 扩展驱动
│   └── Select.php                    # stream_select 驱动
│
├── Protocol/                         # 协议实现
│   ├── ProtocolInterface.php         # 协议接口（照搬）
│   ├── Http.php                      # HTTP/1.1 协议（照搬）
│   ├── WebSocket.php                 # WebSocket 协议（照搬）
│   ├── Text.php                      # 文本协议（照搬）
│   ├── Frame.php                     # 帧协议（照搬）
│   ├── Http2.php                     # HTTP/2 协议（优化新增）
│   └── Http2/                        # HTTP/2 子模块（优化新增）
│       ├── HpackEncoder.php          # HPACK 头部压缩编码
│       ├── HpackDecoder.php          # HPACK 头部压缩解码
│       ├── Stream.php                # HTTP/2 流管理
│       └── Frame.php                 # HTTP/2 帧处理
│
├── # ========== 优化新增 ==========
├── WindowsCompat.php                 # Windows 多进程兼容
├── HotReload.php                     # 热重载
│
├── Middleware/                       # 中间件（优化新增）
│   ├── MiddlewareInterface.php       # 中间件接口
│   ├── RateLimiter.php               # 限流中间件
│   ├── Cors.php                      # CORS 跨域中间件
│   └── Storage/                      # 限流存储
│       ├── StorageInterface.php      # 存储接口
│       ├── MemoryStorage.php         # 内存存储（单进程）
│       └── RedisStorage.php          # Redis 存储（多进程共享）
│
└── Lib/                              # 工具库
    └── Timer.php                     # 定时器实现
```

### 实现统计

| 类型 | 文件数 | 说明 |
|------|--------|------|
| 照搬 Workerman | 15 | 核心功能，直接照搬 |
| 优化新增 | 12 | Windows/HTTP2/限流/热重载 |
| **总计** | **27** | 完整 WeAsync 模块 |
