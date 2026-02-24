---
name: weline-server
description: |
  WLS (Weline Server) 高性能常驻内存服务器的使用和管理。
  用于启动、停止、重启、热重载、状态查看、压力测试、缓存清理。
  
  触发词：WLS, Weline Server, wls, 服务器, server, 服务器命令,
  server:start, server:stop, server:restart, server:reload, server:status,
  启动服务, 停止服务, 重启服务, 热重载, reload, 代码重载,
  Worker, Dispatcher, Master, worker.php, worker_ssl.php,
  缓存清理, cache:clear, 进程管理, 端口, SSL
---

# WLS (Weline Server) 使用指南

## ⚠️ 代码变更后如何生效（最重要！）

**绝大多数代码修改只需热重载 Worker，不需要重启整个 WLS！**

```bash
# 热重载 Worker（推荐！适用于 99% 的代码修改）
php bin/w server:reload
```

| 修改了什么 | 该怎么做 | 命令 |
|-----------|---------|------|
| Controller / Model / Service / Observer 等业务代码 | **热重载** | `php bin/w server:reload` |
| 模板 (.phtml) / 配置 / 静态文件 | **热重载** | `php bin/w server:reload` |
| worker.php / worker_ssl.php | **热重载** | `php bin/w server:reload` |
| AI Provider / 框架服务 等 PHP 文件 | **热重载** | `php bin/w server:reload` |
| dispatcher.php / MasterProcess.php | **完全重启** | `php bin/w server:restart -r` |
| 启动参数（端口、Worker 数等） | **完全重启** | `php bin/w server:restart -r` |

**AI 助手须知**：
- 当告知用户代码变更后如何生效时，**默认说 `php bin/w server:reload`**
- **只有修改了 Master 或 Dispatcher 代码时才说 `server:restart -r`**
- **禁止笼统地说"重启 WLS 服务"** — 应该明确指出是 reload 还是 restart

## 核心概念

WLS 是 Weline Framework 的高性能常驻内存 HTTP 服务器，采用 Master-Dispatcher-Worker 架构。

| 组件 | 职责 |
|------|------|
| **Master** | 进程管理、健康检查、自动重启异常 Worker |
| **Dispatcher** | SSL 终结、流量分发、负载均衡 |
| **Worker** | 处理实际业务请求、加载框架代码 |

## 常用命令

### 启动服务器

```bash
# 默认启动（智能模式）
php bin/w server:start

# 前台运行（开发模式，可看日志）
php bin/w server:start -frontend

# 前台 + HTTP 重定向 + 强制重启
php bin/w server:start -frontend --http-redirect-port=9981 -r -f

# 指定端口和 Worker 数
php bin/w server:start -p 443 -c 4

# 命名实例
php bin/w server:start api-server

# 守护进程模式（仅 Linux/Mac）
php bin/w server:start -d
```

### 停止服务器

```bash
# 停止默认实例
php bin/w server:stop

# 停止指定实例
php bin/w server:stop api-server

# 停止所有实例
php bin/w server:stop --all

# 强制停止
php bin/w server:stop -f
```

### 查看状态

```bash
php bin/w server:status
php bin/w server:status api-server
```

### 压力测试

```bash
# 自动探测运行中的服务器
php bin/w server:benchmark

# 自定义参数
php bin/w server:benchmark -c 500 -n 50000
```

### 热重载（推荐）

```bash
# 代码重载（Worker 优雅重启，加载新代码）
php bin/w server:reload

# 重载指定实例
php bin/w server:reload api-server

# 清理缓存（已集成 WLS 缓存重载事件，无需 server:reload）
php bin/w cache:clear
```

**适用场景**：
- 修改了 `worker.php` / `worker_ssl.php`
- 修改了业务代码（Controller、Model、Service 等）
- 修改了模板、配置等

**不适用场景**（需用 `server:restart -r`）：
- 修改了 `dispatcher.php`
- 修改了 `MasterProcess.php`
- 修改了启动参数（端口、Worker 数等）

**缓存清理**：使用 `cache:clear` 命令即可，已集成 WLS 缓存重载事件，无需单独 reload。

### 其他命令

```bash
# 重启服务器（包括 Master、Dispatcher、Worker）
php bin/w server:restart -r

# 列出所有实例
php bin/w server:listing

# 结束占用端口的进程
php bin/w server:kill-port -p 443

# SSL 证书自动申请
php bin/w server:ssl:auto
```

## 重要：CLI 命令与热重载

### 触发代码重载的命令

**大部分 `php bin/w` 命令执行后会自动通知 WLS 进行代码级别重载**（Worker 重启加载新代码）。

触发重载的命令包括但不限于：
- `setup:upgrade` / `s:up` - 模块升级
- `module:*` - 模块相关命令
- `taglib:*` - 标签库命令
- `route:*` - 路由命令
- `config:*` - 配置命令
- 其他业务相关命令

### 不触发重载的命令

以下前缀的命令**不会触发重载**：
- `server:*` - 服务器命令本身
- `cron:*` - 定时任务
- `http:*` - HTTP 测试命令
- `queue:*` - 队列命令
- `rpc:*` - RPC 命令

### 仅清理缓存的命令

以 `cache:` 开头的命令只清理缓存，不重启 Worker：

```bash
# 清理缓存（不重启 Worker，仅清 opcache + ObjectManager）
php bin/w cache:clear
```

## 缓存清理

WLS 模式下清理缓存同样使用 `php bin/w` 命令：

```bash
# 清理所有缓存
php bin/w cache:clear

# 清理框架缓存
php bin/w cache:clear framework

# 清理模块缓存
php bin/w cache:clear module
```

执行后 WLS 会收到通知并清理 opcache 和 ObjectManager 缓存。

## 配置参数

### 命令行参数

| 参数 | 简写 | 说明 | 默认值 |
|-----|------|------|--------|
| `--port` | `-p` | 监听端口 | 443 (HTTPS) / 80 (HTTP) |
| `--host` | `-h` | 监听地址 | 127.0.0.1 |
| `--count` | `-c` | Worker 进程数 | auto |
| `--daemon` | `-d` | 守护进程模式 | false |
| `--frontend` | - | 前台运行模式 | false |
| `--http-redirect-port` | - | HTTP 重定向端口 | 自动计算 |
| `-r` | - | 重启已运行实例 | false |
| `-f` | - | 强制模式 | false |

### env.php 配置

```php
'server' => [
    'host' => '127.0.0.1',
    'port' => 443,
    'worker_count' => 'auto',  // 'auto' 或具体数字
    'mode' => 'io',            // 'io' 或 'cpu'
    'https' => true,
    'ssl_cert' => 'var/ssl/cert.pem',
    'ssl_key' => 'var/ssl/key.pem',
    'http_redirect_port' => 80,
],
```

### Session 托管配置

WLS 模式下可以配置是否由 WLS 托管 Session。在 `env.php` 的 `session` 配置中添加 `wls_managed` 配置项：

```php
'session' => [
    'default' => 'file',
    
    // WLS 模式 Session 托管配置
    // - true（默认）：使用 WlsMemorySession，内存 + 文件双写，性能最佳
    // - false：使用原生 PHP Session 机制（session_start）
    'wls_managed' => true,  // 开发阶段建议设为 false，避免频繁登录
    
    'drivers' => [
        'file' => [
            'path' => 'var/session/',
            'class' => 'Weline\\Framework\\Session\\Driver\\File',
        ],
    ],
],
```

| 配置值 | 说明 | 适用场景 |
|--------|------|----------|
| `true`（默认） | WLS 内存 Session + 文件双写 | 生产环境、性能测试 |
| `false` | 原生 PHP Session 机制 | 开发调试（避免频繁重新登录） |

**开发模式推荐**：设置 `wls_managed => false`，这样 Worker 重启后 Session 仍然有效，避免频繁重新登录。

## 架构说明

### 双模式架构（自动选择）

WLS 根据操作系统自动选择最优架构：

#### 直连模式（Linux 3.9+ 推荐）

```
客户端 → Worker1(直接SSL处理)
      → Worker2(直接SSL处理)
      → Worker3(直接SSL处理)
      (多进程直接监听同一端口，内核负载均衡)
```

- **原理**：使用 `SO_REUSEPORT`，多 Worker 直接监听同一端口
- **优势**：无单点瓶颈，性能最佳
- **要求**：Linux 3.9+ 内核

#### Dispatcher 模式（Windows 降级）

```
客户端 → Dispatcher(单进程SSL) → Worker1(HTTP)
                              → Worker2(HTTP)
                              → Worker3(HTTP)
```

- **原理**：单进程 Dispatcher 接收所有请求，分发给多 Worker
- **适用**：Windows（不支持 SO_REUSEPORT）

### 架构控制参数

| 参数 | 作用 |
|------|------|
| `--no-dispatcher` | 强制禁用 Dispatcher（多端口模式） |
| `--dispatcher` / `--force-dispatcher` | 强制使用 Dispatcher 模式 |

### HTTPS 模式端口分配

| 组件 | 直连模式 | Dispatcher 模式 |
|------|----------------|-----------------|
| Worker | 443（共享） | 10443+N（内网） |
| Dispatcher | 无 | 443 |
| HTTP Redirect | 80/9980 | 80/9980 |

### 请求流向

**Linux (直连模式)**：
```
HTTPS 请求 → Worker(直接处理SSL，内核负载均衡)
```

**Windows (Dispatcher 模式)**：
```
HTTPS 请求 → Dispatcher (443) → 负载均衡 → Worker (10443+N)
```

## 热重载机制

### 触发方式

1. **手动命令（推荐）**：`php bin/w server:reload` 手动触发热重载
2. **CLI 命令自动**：执行 `php bin/w` 命令（如 `s:up`）后自动通知
3. **文件变更**：开发模式下监控 `app/code`、`app/etc` 目录
4. **手动标记**：写入 `var/server/reload.flag` 文件

### 重载类型

| 类型 | 说明 | 触发命令 |
|------|------|---------|
| `code` | 代码重载，Worker 优雅重启 | `server:reload` 或大部分 CLI 命令 |
| `cache` | 仅清缓存，不重启 | `cache:clear`（已集成 WLS 事件） |

### 重载 vs 重启 vs 缓存清理

| 操作 | 命令 | 影响范围 | 适用场景 |
|------|------|----------|----------|
| 热重载 | `server:reload` | 仅 Worker | 修改业务代码、Worker 代码 |
| 完全重启 | `server:restart -r` | Master + Dispatcher + Worker | 修改 Dispatcher、Master 或启动参数 |
| 缓存清理 | `cache:clear` | opcache + ObjectManager + WLS内存缓存 | 配置变更、模板变更、静态文件变更等 |

### 缓存清理详情

执行 `cache:clear` 时，WLS 会自动清理以下缓存：

| 缓存类型 | 说明 |
|----------|------|
| opcache | PHP 字节码缓存 |
| ObjectManager | 对象实例缓存 |
| 静态文件内存缓存 | WLS 内置的 CSS/JS/图片等静态文件缓存 |
| clearstatcache | 文件状态缓存 |

## 内存缓存管理（智能模式）

WLS 采用**智能内存分配 + 冷热淘汰策略**管理静态文件缓存。

### 配置项（env.php）

```php
'server' => [
    'cache' => [
        // 静态文件缓存总上限
        // 'auto'：智能模式，系统内存的 2%，最小 32MB，最大 256MB
        // '100M'：指定大小（支持 K/KB/M/MB/G/GB 单位）
        'static_file_max_total' => 'auto',
        
        // 单文件最大缓存（超过则不缓存，直接从磁盘读取）
        'static_file_max_size' => '1M',
        
        // 淘汰阈值：剩余空间低于此值时开始淘汰
        'eviction_threshold' => 5242880,  // 5MB
    ],
],
```

### 智能内存分配

| 系统内存 | 自动计算的缓存上限 |
|----------|-------------------|
| 2GB | 40MB |
| 4GB | 80MB |
| 8GB | 160MB |
| 16GB+ | 256MB（上限） |

### 冷热淘汰策略

当缓存空间不足时，WLS 会根据以下公式计算"冷热分数"，优先淘汰最冷的缓存项：

```
score = hits × 10 + recency_bonus
recency_bonus = max(0, 100 - age_minutes)
```

- **hits**：访问次数（越多越热）
- **recency_bonus**：最近访问加分（最近 100 分钟内访问过的项获得额外分数）

### 启动时内存检查

Worker 启动时会检查系统可用内存：

| 情况 | 处理方式 |
|------|----------|
| 内存充足 | 正常启动 |
| 内存不足但可用 | 警告并自动缩减缓存大小 |
| 内存严重不足（<50%需求） | 拒绝启动，提示用户增加内存或减少配置 |

## 性能优化

### 推荐扩展

```bash
# 安装 event 扩展（性能提升 100-200%）
pecl install event
```

### php.ini 配置

```ini
opcache.enable=1
opcache.enable_cli=1
opcache.jit=tracing
memory_limit=256M
```

## Worker 防重复启动（2026-02-06）

Master 进程有 3 条路径可能触发同一 Worker 重启，若无互斥保护会启动两个相同 ID 的 Worker：

| 竞态 | 路径 A | 路径 B |
|------|--------|--------|
| 1 | 健康检查（每5秒） | 滚动重启排水 |
| 2 | 健康检查 | IPC handleWorkerDisconnect |
| 3 | handleWorkerDisconnect 两次触发 | （时序依赖） |

**解决方案**：`MasterProcess` 使用 `$restartingWorkers` per-Worker 内存互斥锁。
详见 `process-management` 技能的"Worker 重启互斥机制"章节。

**开发注意**：修改 Master 重启逻辑时，所有新增的重启入口都必须经过 `restartWorker()` 或 `restartWorkerLinuxDirect()` 方法，它们内部已有互斥守卫。

## Master 复活与在役进程采纳（2026-02-14）

Master 被 Worker/Dispatcher/HTTP Redirect 复活后，必须**采纳仍在运行的所有子进程**（Worker、Dispatcher、HTTP Redirect），不能盲目再起一套，否则旧进程会变成僵尸。

**机制**：
1. **复活路径**：`runMasterOnly()` 从实例文件读取 `worker_pids`、`dispatcher_pid`、`http_redirect_pid`，调用 `setWorkerPids()` + `setDispatcherPid()` + `setHttpRedirectPid()` + `setResurrectionMode(true)`，再 `run()`。
2. **等待重连**：复活模式下，阶段 0（IPC 启动）后会等待约 6 秒，让在役 Worker/Dispatcher/HTTP Redirect 通过 IPC 重连并发送 `register`，Master 的 `handleControlMessage(TYPE_REGISTER)` 会更新 `$this->workers[$wid]`、`$this->dispatcher['pid']`、`$this->httpRedirectWorker['pid']`，实现相互认识。
3. **采纳逻辑**：
   - **Worker**：`startAllWorkers()` 中，若 `pid > 0` 且 `Processer::isRunningByPid(pid)`，则置 `state=RUNNING` 并跳过启动。
   - **Dispatcher**：阶段 2 前若 `dispatcher['pid'] > 0` 且进程存活，则采纳并跳过启动。
   - **HTTP Redirect**：阶段 3 前若 `httpRedirectWorker['pid'] > 0` 且进程存活，则采纳并跳过启动。
4. **健康检查**：Dispatcher 与 HTTP Redirect 均优先用 PID 判断存活，采纳的在役进程不会被误重启。

**修改注意**：动到 `startAllWorkers()`、阶段 2/3、`run()`、`runMasterOnly` 或复活相关逻辑时，须保持“先通信检查/采纳，再按需启动”。

## WLS 状态管理（2026-02-06）

WLS 常驻内存模式下，请求间的状态泄漏是最常见的 bug 来源。**所有持有请求级数据的 static 变量，必须注册到 StateManager 重置。**

### 判断标准

新增 `static` 变量时问：**「该变量在不同请求间是否可能不同？」**

- **是**（请求 URI、当前用户、Session、递归保护标志等）→ **必须注册重置**
- **否**（反射信息、模块配置、编译期元数据等）→ 不需重置，加注释 `// 进程级缓存，无需跨请求重置`

### 必须注册的类型

1. **URL/路由解析缓存** — 每次请求 URL 不同  
2. **递归保护标志**（如 `$isCreating`）— 异常中断后可能残留 `true`  
3. **请求上下文** — 连接、回调、请求 ID 等  
4. **用户/会话状态** — 当前用户、货币、语言等  

### 注册方式

在 `StateManager::registerFrameworkResets()` 中：

```php
StateManager::registerStaticReset(MyClass::class, 'myStaticVar', defaultValue);
StateManager::registerResetCallback('my_reset', function () {
    MyClass::reset();
});
```

### 禁止事项

- **禁止**在 WLS 下依赖 static 在请求间传递数据  
- **禁止**不注册重置就用 static 缓存请求级数据  
- **禁止**清除进程级缓存（ObjectManager 反射缓存等），这些是 WLS 性能优势  

### 已注册的重置项（参考）

URL/路由（Url、Request、ProcessUrlCache）、递归保护（SessionManager、CacheFactory）、请求上下文（RequestContext、SseContext）、用户/会话（Env::$user、CurrencyCache）、Response/控制器、Template 单例、State/State::$is_backend、ConnectionPool::requestEndCleanup、Env::$maintenanceCached。

## 故障排查

### 查看日志

```bash
# WLS 运行日志
cat var/log/wls.log

# 错误日志
cat var/log/error.log

# Master 健康检查日志
cat var/log/master_health_debug.log
```

### 常见问题

1. **端口被占用**：
   - 非显式端口（未传 `-p`）被**非框架进程**占用时，会自动跳到下一个可用端口继续启动（不误杀外部进程）
   - Dispatcher 模式下，若 Worker 连续端口段存在非框架占用，会自动切换到下一段可用连续端口
   - 自动计算的 HTTP Redirect 端口被非框架进程占用时，也会自动切换到可用端口
   - 显式指定端口（如 `-p 443`）时保持严格模式：占用则直接报错，避免与用户明确配置不一致
   - 如需释放框架进程占用端口，可用：`php bin/w server:kill-port -p 443`
2. **Worker 崩溃**：检查 `var/log/error.log`、`var/log/wls-worker-crash.log`（致命错误与 die/exit 均会写入后者，标签为 [FATAL]/[EXIT]）。
3. **Worker 内 die/exit**：会触发 `register_shutdown_function`，记录到 `var/log/wls-worker-crash.log`（[EXIT]）及 WLS 运行日志，便于排查业务误用 exit/die。
4. **缓存不生效**：`php bin/w cache:clear`
5. **热重载无效**：检查 `var/server/reload.flag` 文件
6. **Mac/Linux 非 root 绑定 80/443 失败**：`server:start` 会在命令入口检测特权端口（<1024），自动触发 `sudo` 重新执行并提示输入密码；若非交互终端则提示手动 `sudo` 启动。
7. **直连模式端口语义**：SO_REUSEPORT 直连下多个 Worker 共用主端口（不是 `port + i`）；若出现 Worker 绑定到 444/445 等递增端口，说明模式实现异常，应检查 `MasterProcess` 初始化与启动路径。
