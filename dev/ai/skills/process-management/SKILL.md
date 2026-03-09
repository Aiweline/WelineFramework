---
name: process-management
description: |
  Weline Framework 进程管理。只要涉及进程相关操作就必须命中本技能！
  
  MUST use when:
  - 创建、启动、停止、杀死进程
  - fork、子进程、多进程、Worker 进程
  - PID 管理、进程检测、进程状态
  - proc_open、pcntl_fork、exec 启动进程
  - 后台进程、守护进程、daemon
  - 进程注册、Processer、进程名 --name
  
  Keywords: 进程, process, 多进程, 子进程, fork, 后台进程, daemon, 守护进程, PID, proc_open, pcntl_fork, exec, Worker, 进程创建, 进程管理, 进程检测, 杀死进程, kill, Processer, --name, 进程名, 端口管理, tasklist, ps, nohup
globs:
  - "**/Process/**/*.php"
  - "**/Processer.php"
  - "**/Worker*.php"
  - "**/Server/**/Start*.php"
  - "**/Server/**/Stop*.php"
  - "**/bin/worker*.php"
  - "**/bin/file_watcher.php"
alwaysApply: false
---

# 进程管理技能

## 核心原则（必须遵循！）

**进程管理器创建的，必须进程管理器结束！**

```php
// ✅ 正确：使用 destroy() 杀死并清理 PID 文件
Processer::destroy($processName);

// ❌ 错误：直接杀端口/PID，会导致 var/process/pid 累积文件
// Processer::killProcessByPort($port);
// Processer::killByPid($pid);
```

### 关键场景

1. **进程退出时**：Worker/Dispatcher 退出必须调用 `Processer::destroy()`
2. **重载时**：热重载后需要重新注册 PID（`Processer::setPid()`）
3. **停止服务时**：遇到空 PID 文件要等待重试（可能正在重载）
4. **信号处理**：SIGTERM/SIGINT 处理函数中使用优雅退出

### Worker/Dispatcher 优雅退出模式

```php
// 优雅退出函数（统一使用进程管理器清理）
$gracefulExit = function (string $reason = '') use ($socket, &$connections, $processName) {
    if ($reason) {
        \error_log("[WLS Worker] 退出原因: {$reason}");
    }
    
    // 关闭所有连接
    foreach ($connections as $conn) {
        @\fclose($conn);
    }
    @\fclose($socket);
    
    // 使用进程管理器清理 PID 文件
    if ($processName) {
        \Weline\Framework\System\Process\Processer::destroy('--name=' . $processName);
    }
    
    exit(0);
};

// 信号处理中使用
\pcntl_signal(SIGTERM, function () use ($gracefulExit) {
    $gracefulExit('收到 SIGTERM 信号');
});
```

### 停止进程时处理空 PID 文件

```php
// 如果 PID 为空但端口被占用，可能是进程正在重载，等待重试
$pid = (int) Processer::getData($processName, 'pid');

if ($pid <= 0 && Processer::isPortInUse($port)) {
    // 等待最多 3 秒，每 500ms 检查一次
    for ($retry = 0; $retry < 6; $retry++) {
        \usleep(500000); // 500ms
        $pid = (int) Processer::getData($processName, 'pid');
        if ($pid > 0) {
            break;
        }
    }
}
```

## 概述

Weline Framework 提供了统一的进程管理器 `Weline\Framework\System\Process\Processer`，用于跨平台创建、管理和监控进程。

## 服务器命令

### WLS (Weline Server) 高性能服务器

```bash
# 启动服务器（智能模式：自动检测 CPU 核心数）
php bin/w server:start

# 启动命名实例
php bin/w server:start api-server -p 9000 -c 4

# 查看状态（树形展示进程）
php bin/w server:status

# 停止服务器
php bin/w server:stop

# 压力测试（自动探测运行中的服务器）
php bin/w server:benchmark
php bin/w server:benchmark -c 500 -n 50000  # 高并发测试
```

### 服务器类型对比

| 特性 | WLS (Weline Server) | CLI Server |
|-----|---------------------|------------|
| 适用场景 | 生产环境、高并发 | 开发调试 |
| 进程模式 | 多进程 | 单进程 |
| 性能 | 15,000+ QPS/进程 | 500-1000 QPS |
| 常驻内存 | ✅ | ❌ |
| 配置复杂度 | 智能配置 | 零配置 |

### env.php 服务器配置

```php
'server' => [
    'host' => '127.0.0.1',      // 监听地址
    'port' => 9981,             // 监听端口
    'worker_count' => 'auto',   // 'auto'=智能推算，或具体数字
    'mode' => 'io',             // 'io'=I/O密集型，'cpu'=CPU密集型
],
```

详细文档：`app/code/Weline/Server/README.md`

## 核心类

```php
use Weline\Framework\System\Process\Processer;
```

## 主要功能

### 1. 创建进程

```php
// 创建异步进程（非阻塞，立即返回）
$pid = Processer::create('php bin/w some:command --name=myprocess', false);

// 阻塞模式创建（等待完成）
$pid = Processer::create('php bin/w some:command --name=myprocess', true);
```

**重要**: 进程名必须包含 `--name=xxx` 参数用于标识进程。

### 2. 检查进程状态（性能关键！）

**检测优先级（快→慢）**：

```php
// ========== 最快：从文件获取 PID + 检查存活 ==========
// 1. getData() 读取本地文件映射（毫秒级，O(1)）
// 2. isRunningByPid() 检查 PID 存活（tasklist 精确匹配，快速）
$pid = (int) Processer::getData($processName, 'pid');
if ($pid > 0 && Processer::isRunningByPid($pid)) {
    echo '进程运行中';
}

// ========== 其次：端口检测（服务是否可用） ==========
if (Processer::isPortInUse($port)) {
    echo '端口在监听';
}

// ========== 避免！getPid() 在 Windows 上很慢 ==========
// getPid() 如果文件中的 PID 无效，会调用 findPhpProcessPid() 系统搜索
// $pid = Processer::getPid($processName); // Windows 上可能 5-30 秒！
```

**进程名的作用**：
- ✅ 用于：注册进程、判断是否可以安全杀死（避免误杀非框架进程）
- ❌ 不用于：检测进程是否存活（Windows 上进程名搜索太慢且不可靠）

### 3. 获取进程信息

```php
// 🚀 快速：直接从文件读取 PID（推荐！）
$pid = (int) Processer::getData($processName, 'pid');

// ⚠️ 慢：getPid() 会尝试系统搜索
// $pid = Processer::getPid($processName);

// 获取完整进程数据
$data = Processer::getData($processName);
// 返回: ['pid', 'time', 'date', 'pname', 'task_name']

// 获取进程详细信息（需要先有 PID）
$info = Processer::getProcessInfo($pid);
// 返回: ['pid', 'exists', 'name', 'command', 'memory', 'cpu', 'start_time']
```

### 4. 停止进程（关键！）

```php
// ✅ 推荐：销毁进程（杀死 + 清理 PID/日志文件）
// 进程管理器创建的，必须用 destroy() 结束！
Processer::destroy($processName);

// ⚠️ kill() 也会清理 PID 文件，但建议用 destroy()
Processer::kill($processName);

// ❌ 避免：直接杀 PID/端口会导致 PID 文件残留
// Processer::killByPid($pid);
// Processer::killProcessByPort($port);

// 如果必须杀端口（兼容旧进程），手动清理 PID 文件
Processer::killProcessByPort($port);
Processer::removePidFile($processName);  // 清理残留
```

**重要**：`var/process/pid` 目录累积文件的原因就是直接 `killByPid`/`killProcessByPort` 而没有清理 PID 文件！

### 5. 优雅停止与批量管理（2026-03 新增）

WLS 场景下，Worker 需要更长时间处理完当前请求再退出。以下方法提供可配置超时的优雅停止：

```php
// ✅ 优雅停止单个进程（带超时）
// 先发 SIGTERM，等待 $timeout 秒，仍存活则 SIGKILL
$success = Processer::gracefulKill($pid, timeout: 5.0);

// ✅ 批量优雅停止多个进程
// 同时向所有进程发 SIGTERM，统一等待，超时后统一 SIGKILL
// 比逐个停止更高效，因为所有进程可以并行处理退出逻辑
$result = Processer::batchGracefulKill($pids, timeout: 5.0);
// 返回: ['killed' => 3, 'failed' => 0, 'remaining' => []]

// ✅ 批量检查进程状态
$statuses = Processer::batchCheckRunning([1234, 5678, 9012]);
// 返回: [1234 => true, 5678 => false, 9012 => true]

// ✅ 等待多个进程退出
$result = Processer::waitForExit($pids, timeout: 5.0);
// 返回: ['exited' => [1234, 5678], 'remaining' => [9012]]

// ✅ 按进程名前缀优雅停止（适合停止某实例的所有 Worker）
$result = Processer::gracefulKillByPrefix('weline-master-default-', timeout: 5.0);
// 返回: ['killed' => 4, 'failed' => 0]
```

**场景对比**：

| 场景 | 推荐方法 | 说明 |
|-----|---------|------|
| 停止单个已知进程 | `destroy($pname)` | 最常用，自动清理 PID 文件 |
| 需要更长等待时间 | `gracefulKill($pid, 10.0)` | 如 Worker 处理大请求 |
| 停止多个进程 | `batchGracefulKill($pids)` | 并行发送信号更高效 |
| 停止实例所有 Worker | `gracefulKillByPrefix('weline-master-default-')` | 按前缀匹配并批量停止 |
| 检查多个进程状态 | `batchCheckRunning($pids)` | 批量返回状态 |
| 等待进程自然退出 | `waitForExit($pids)` | 不发信号，仅等待 |

#### 按前缀杀逃逸进程（var/process + killByProcessNamePrefix）

进程名在 `var/process/pid/name_index.json` 中有索引。杀逃逸 Master 时：先用 **getProcessNamesByPrefix** 从 var/process 按前缀枚举匹配的进程名，再调用 **killByProcessNamePrefix** 按前缀杀（仅杀框架己方进程）。

```php
// 从 var/process 找到匹配前缀的进程名（如 weline-wls-master-default）
$pnames = Processer::getProcessNamesByPrefix('weline-wls-master-default');
// 按前缀杀（内部读 name_index，校验己方进程后杀）
$killed = Processer::killByProcessNamePrefix('weline-wls-master-default');
```

Server 的 Start/Stop 在「三轮仍杀不死、按 Master 前缀清理逃逸」时即采用上述流程：先 `getProcessNamesByPrefix($masterPrefix)` 再 `killByProcessNamePrefix($masterPrefix)`。

### 5. 日志管理

```php
// 获取日志文件路径
$logFile = Processer::getLogFile('php bin/w some:command --name=myprocess');

// 获取输出内容
$output = Processer::output('php bin/w some:command --name=myprocess');

// 写入日志
Processer::setOutput('php bin/w some:command --name=myprocess', '新日志内容');
```

### 6. 端口管理

```php
// 检查端口是否被占用
if (Processer::isPortInUse(8080)) {
    echo '端口被占用';
}

// 查找可用端口
$port = Processer::findAvailablePort(8080);

// 终止占用端口的进程
Processer::killProcessByPort(8080);
```

### 7. 执行命令

```php
// 执行命令并获取输出
$output = [];
$returnCode = 0;
$success = Processer::execute('php -v', $output, $returnCode);
```

## 内置 HTTP 服务器

```php
// 启动 PHP 内置服务器
$pid = Processer::startBuiltInServer(
    BP . 'pub',    // 文档根目录
    9980,          // 端口
    BP . 'var/log/server.log'  // 日志文件
);
```

## Server 进程名规范

WLS (Weline Server) 使用以下进程名格式（Master 统一前缀便于按前缀清理逃逸进程）：

```
# Master 进程（统一前缀 weline-wls-master-，便于按前缀杀逃逸 Master）
--name=weline-wls-master-{instanceName}

# Dispatcher（流量分发器）
--name=weline-dispatcher-{instanceName}

# Worker 进程
--name=weline-master-{instanceName}-worker-{workerId}

# HTTP 重定向进程
--name=weline-http-redirect-{instanceName}

# 示例（实例名 default，4 个 Worker）
--name=weline-wls-master-default
--name=weline-dispatcher-default
--name=weline-master-default-worker-1
--name=weline-master-default-worker-2
--name=weline-master-default-worker-3
--name=weline-master-default-worker-4
```

## 进程文件结构（核心！快速检测的基础）

```
var/
├── process/
│   ├── myprocess.log           # 进程日志
│   └── pid/
│       ├── myprocess-pid.json  # 进程信息 JSON（快速检测的数据源）
│       └── 12345.pid           # PID 反向映射（进程名 → PID）
```

### PID 文件内容示例

```json
// var/process/pid/weline-dispatcher-default-pid.json
{
    "pid": 12345,
    "time": 1700000000,
    "date": "2024-11-14 12:00:00",
    "pname": "php bin/w server:start --name=weline-dispatcher-default",
    "task_name": "weline-dispatcher-default"
}
```

### 快速获取 PID 的机制

```php
// Processer::getData() 直接读取上述 JSON 文件
$pid = (int) Processer::getData('--name=weline-dispatcher-default', 'pid');
// 耗时：< 1ms（文件读取）

// 然后验证 PID 是否存活
$alive = Processer::isRunningByPid($pid);
// 耗时：Windows ~10-50ms（tasklist 精确匹配），Linux ~5ms（/proc 检测）
```

**总耗时**：< 100ms，比进程名搜索快 100 倍以上！

## 进程生命周期管理（完整示例）

```php
use Weline\Framework\System\Process\Processer;

class MultiProcessServer
{
    private string $instanceName = 'default';
    
    /**
     * 启动进程（使用进程管理器创建）
     */
    public function start(int $basePort, int $count): array
    {
        $pids = [];
        
        for ($i = 1; $i <= $count; $i++) {
            $port = $basePort + $i - 1;
            // 标准进程名格式：--name=weline-master-{实例名}-worker-{编号}
            $processName = '--name=weline-master-' . $this->instanceName . '-worker-' . $i;
            
            // ✅ 快速检测：从文件获取 PID + 验证存活
            $pid = (int) Processer::getData($processName, 'pid');
            if ($pid > 0 && Processer::isRunningByPid($pid)) {
                $pids[] = $pid;
                continue;
            }
            
            // 创建进程（自动注册 PID）
            $cmd = "php bin/w server:worker --port={$port} {$processName}";
            $pid = Processer::create($cmd, false);
            if ($pid > 0) {
                $pids[] = $pid;
            }
        }
        
        return $pids;
    }
    
    /**
     * 停止进程（必须使用进程管理器结束！）
     */
    public function stop(int $basePort, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $port = $basePort + $i - 1;
            $processName = '--name=weline-master-' . $this->instanceName . '-worker-' . $i;
            
            // ✅ 正确：使用 destroy() 杀死并清理 PID 文件
            $pid = (int) Processer::getData($processName, 'pid');
            if ($pid > 0 && Processer::isRunningByPid($pid)) {
                Processer::destroy($processName);
            } elseif (Processer::isPortInUse($port)) {
                // 兼容：进程存在但无 PID 记录，杀端口后清理文件
                Processer::killProcessByPort($port);
                Processer::removePidFile($processName);
            } else {
                // 清理可能残留的 PID 文件
                Processer::removePidFile($processName);
            }
        }
    }
}
```

### Worker 重启互斥机制（2026-02-06 架构更新）

多个路径（健康检查、滚动重启、IPC 断开回调）可能同时触发同一 Worker 的重启，
导致出现两个相同 ID 的 Worker。`MasterProcess` 使用 per-Worker 内存互斥锁解决：

```
健康检查 ──┐
            ├─→ acquireWorkerRestartLock(workerId) ──→ doRestartWorker()
IPC 断开 ──┤                                            ↓
            │                                    releaseWorkerRestartLock()
滚动重启 ──┘
```

```php
// MasterProcess 属性
protected array $restartingWorkers = []; // [workerId => timestamp]

// restartWorker() 和 restartWorkerLinuxDirect() 入口守卫
protected function restartWorker(int $workerId, int $port): int
{
    if (!$this->acquireWorkerRestartLock($workerId)) {
        return 0; // 已被锁定，跳过
    }
    try {
        return $this->doRestartWorker($workerId, $port);
    } finally {
        $this->releaseWorkerRestartLock($workerId);
    }
}
```

**关键规则**：
- 健康检查必须跳过 `rollingRestart` 中排水的 Worker（`drainingWorkerId`）
- 健康检查必须跳过 `isWorkerRestarting()` 返回 true 的 Worker
- 锁带 30 秒过期保护，防止异常中断后死锁
- Master 是单进程单线程，内存级标志就够，不需要文件锁

### 参考实现

- **Queue 队列**：正确使用进程管理器控制进程
- **Cron 定时任务**：正确使用进程管理器控制进程
- **Server 服务器**：`Stop.php` 示例、`MasterProcess.php` Worker 互斥重启

## Windows vs Linux 差异

| 功能 | Windows | Linux/Mac |
|-----|---------|-----------|
| 进程创建 | PowerShell/cmd | nohup/fork |
| 进程检测 | tasklist | ps |
| 多进程 | 模拟（多个独立进程） | 真正 fork |
| 端口检测 | netstat | netstat/ss |

## macOS 兼容提示（posix_killpg 缺失）

部分 macOS PHP 构建即使启用了 `posix` 扩展，也可能不提供 `posix_killpg()`。
因此，**不要硬编码直接调用 `posix_killpg()`**。

推荐统一写法：

```php
if (\function_exists('posix_kill')) {
    // 先尝试按进程组发送（负 PID）
    if (!@\posix_kill(-$pid, \SIGTERM)) {
        // 回退：只终止主进程
        @\posix_kill($pid, \SIGTERM);
    }
}
```

说明：
- `-$pid` 表示向 PGID 发送信号（与 killpg 语义一致）
- 失败回退到 `posix_kill($pid, ...)`，避免跨平台 Fatal
- 相关规则：见 `error-tracking/DEVELOPMENT_NOTES.md`

## 最佳实践

1. **始终使用 `--name` 参数** - 便于进程标识和管理
2. **PID 优先检测** - 有了 PID 直接用 `isRunningByPid($pid)` 检测，**绝不用进程名检测**（Windows 下 5-30 秒）
3. **快速获取 PID** - 用 `getData($pname, 'pid')` 从文件读取（< 1ms），**避免 `getPid()`**
4. **进程名只用于** - 注册进程、判断是否可以安全杀死（避免误杀非框架进程）
5. **端口检测** - 启动服务前检查端口可用性
6. **使用 `destroy()` 清理** - 确保清理 PID 和日志文件

### WLS 高可用控制面（2026-03 更新）

当遇到“子进程窗口累积/孤儿进程增多/PID 不可信”时，优先采用以下策略：

1. **Single Writer**：仅 Master/Orchestrator 控制进程生命周期，子进程禁止复活 Master。
2. **代际隔离**：进程身份以 `process_name + epoch + launch_id` 为主，PID 只作观测值。
3. **持续收敛**：周期执行 reconcile（补齐缺失、回收超额、清理旧 epoch）。
4. **周期扫尾**：按前缀执行 orphan sweeper + stale pid file 清理。

常见误区：
- 误区1：把 Windows 非阻塞启动返回的瞬时 PID 当真实 PID。
- 误区2：让 Worker/Dispatcher/Redirect 进程在断连时各自复活 Master。
- 误区3：仅依赖一次 stop/restart 操作，不做持续状态收敛。
- 误区4：把重型 orphan sweeper（按前缀 kill）放到主循环高频执行，导致 IPC 轮询被阻塞、`register_timeout` 误判。
- 误区5：把“PID 索引已删除”等同于“进程已退出”。退出判定必须联合 `processExists/isRunningByPid` 做双确认，避免假退出。

## 检测流程（必须遵循！）

```php
// ✅ 正确：快速检测流程
$pid = (int) Processer::getData($processName, 'pid');  // < 1ms 读文件
if ($pid > 0 && Processer::isRunningByPid($pid)) {     // 10-50ms tasklist 精确匹配
    // 进程在运行
}

// ❌ 错误：慢！Windows 下 5-30 秒
// if (Processer::running($processName)) { ... }
// if (Processer::isProcessRunningByName($processName)) { ... }
```

## 触发条件（只要涉及以下任一即命中）

- 进程、process、子进程、多进程、Worker
- fork、pcntl_fork、proc_open、exec 启动
- PID、kill、停止进程、销毁进程
- 后台进程、daemon、守护进程
- Processer、进程管理、进程检测
- **检测进程是否存活、进程状态**
