# Master 进程启动 Dispatcher 的完整流程分析

## 概述

Master 进程通过 **ServiceOrchestrator** 编排系统启动 Dispatcher 作为 **独立进程**。Dispatcher 通过 IPC 通道注册到 Master，并定期上报心跳。

---

## 1. Master 主类代码位置

### MasterProcess.php
- **文件路径**: `app/code/Weline/Server/Service/MasterProcess.php`
- **职责**:
  - 解析启动参数
  - 构建 ServiceContext
  - 创建 ServiceOrchestrator 并委托服务管理
  - 信号处理

### ServiceOrchestrator.php
- **文件路径**: `app/code/Weline/Server/Service/ServiceOrchestrator.php`
- **职责**:
  - 加载所有 ServiceProvider（内置 + 模块扫描）
  - 按优先级启动/停止服务（包括 Dispatcher）
  - 统一健康检查循环
  - 统一 IPC 消息处理

---

## 2. Dispatcher 启动调用链

### 2.1 DispatcherProvider 负责启动命令构建

**文件**: `app/code/Weline/Server/Service/Provider/DispatcherProvider.php`

```php
public function buildCommand(int $instanceId, ServiceContext $context): ServiceCommand
{
    // dispatcher.php 脚本路径
    $script = $scriptDir . DS . 'dispatcher.php';
    
    // 获取监听端口
    $port = $this->getPort($instanceId, $context);
    $processName = MasterProcess::buildScopedProcessName(self::PROCESS_NAME_PREFIX, $context->instanceName);
    
    // Worker 数量与基端口
    $workerCount = $context->getWorkerCount();
    $workerBasePort = $context->getWorkerBasePort();

    // 构建启动参数
    $arguments = [
        '127.0.0.1',                              // Worker 主机（内网）
        (string) $port,                           // Dispatcher 监听端口
        (string) $workerBasePort,                 // Worker 基端口
        (string) $workerCount,                    // Worker 数量
        $context->instanceName,                   // 实例名称
        '--control-port=' . $context->controlPort,// IPC 控制端口
        '--master-pid=' . $context->masterPid,    // Master PID（孤儿检测）
    ];

    if ($context->frontend) {
        $arguments[] = '--frontend';
    }

    return new ServiceCommand(
        script: $script,
        arguments: $arguments,
        processName: $processName,
    );
}
```

---

## 3. Dispatcher.php 启动脚本

**文件**: `app/code/Weline/Server/bin/dispatcher.php`

### 3.1 参数解析

```php
$host = $argv[1] ?? '127.0.0.1';              // Worker 主机
$port = (int) ($argv[2] ?? 443);              // Dispatcher 监听端口
$workerBasePort = (int) ($argv[3] ?? 10000);  // Worker 基端口
$workerCount = (int) ($argv[4] ?? 2);         // Worker 数量
$instanceName = $argv[5] ?? 'default';        // 实例名称

// 解析可选参数
foreach ($argv as $arg) {
    if (\str_starts_with($arg, '--name=')) {
        $processName = \substr($arg, 7);
    } elseif ($arg === '--frontend') {
        $isFrontend = true;
    } elseif (\str_starts_with($arg, '--control-port=')) {
        $controlPort = (int)\substr($arg, 15);  // IPC 控制端口
    } elseif (\str_starts_with($arg, '--master-pid=')) {
        $masterPid = (int)\substr($arg, 13);    // Master PID
    } elseif (\str_starts_with($arg, '--epoch=')) {
        $orchestratorEpoch = (int)\substr($arg, 8);
    } elseif (\str_starts_with($arg, '--launch-id=')) {
        $orchestratorLaunchId = (string)\substr($arg, 12);
    }
}
```

### 3.2 创建 Dispatcher 实例并启动

```php
// 1. 创建 TCP socket（非阻塞模式）
$socket = @\socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
\socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
\socket_bind($socket, $host, $port);
\socket_listen($socket, 1024);
\socket_set_nonblock($socket);

// 2. 创建 Dispatcher 实例（独立进程）
$dispatcher = new \Weline\Server\Dispatcher\Dispatcher(
    $socket,
    '127.0.0.1',      // Worker 主机地址（内网）
    $workerBasePort,
    $workerCount,
    $instanceName,
    $processName,
    $port
);

// 3. 配置参数
$dispatcher->configure([
    'sni_routing_enabled' => true,
    'learning_mode_enabled' => true,
    'connection_timeout' => 300,
    // ... 其他配置
]);

// 4. 连接 IPC 控制通道
$dispatcher->connectIpc($controlPort);

// 5. 设置 Master PID（孤儿检测）
if ($masterPid > 0) {
    $dispatcher->setMasterPid($masterPid);
}

// 6. 设置生命周期令牌
$dispatcher->setLifecycleTokens($orchestratorEpoch, $orchestratorLaunchId);

// 7. 启动事件循环
$dispatcher->run();
```

---

## 4. Dispatcher 是独立进程

### 确认点：

1. ✅ **启动方式**：通过 `dispatcher.php` 脚本作为独立 PHP-CLI 进程启动
2. ✅ **不在主进程中实例化**：Master 的 ServiceOrchestrator 通过 Processer::spawn() 或相似机制启动独立进程
3. ✅ **资源隔离**：
   - 独立 TCP socket（监听端口，如 443）
   - 独立内存空间
   - 独立事件循环（Dispatcher::run()）
4. ✅ **生命周期独立**：可独立重启、停止、监控

---

## 5. Dispatcher IPC 注册机制

Dispatcher **通过 IPC 连接 Master 控制端口** 进行注册。

### 5.1 IPC 连接建立流程

**代码文件**: `app/code/Weline/Server/Dispatcher/Dispatcher.php` - `connectIpc()` 方法

```php
public function connectIpc(int $controlPort = 0): void
{
    $this->controlPort = $controlPort;
    
    // 实例化 IPC 客户端
    $this->ipcClient = new ControlClient();
    $this->ipcClient->setSelfTag('Dispatcher');
    $this->ipcClient->setVerboseLog($this->isDevMode);
    
    // 步骤 1: 记忆注册信息（后续重连使用）
    $this->ipcClient->rememberRegistration(
        ControlMessage::ROLE_DISPATCHER,
        \getmypid(),
        $this->port,
        0,
        $this->orchestratorEpoch,
        $this->orchestratorLaunchId,
        ControlMessage::PROCESS_KIND_FRAMEWORK,
        '',
        $this->instanceName
    );
    
    // 标记为就绪状态
    $this->ipcClient->markReadyState(true);
    
    // 注册消息处理器
    $this->ipcClient->onMessage(function (array $msg, ControlClient $client) {
        $this->handleIpcMessage($msg);
    });
    
    // 注册断开处理器（自动重连逻辑）
    $this->ipcClient->onDisconnect(function (bool $receivedShutdown, ControlClient $client) {
        if ($receivedShutdown || $this->ipcReceivedShutdown || !$this->running) {
            $this->log('Master 连接断开（已收到 shutdown，不复活）', 'INFO');
            return;
        }
        $this->log('Master 连接意外断开，自动重连...', 'WARN');
        $client->tryReconnect();
    });
    
    // 步骤 2: 建立 TCP 连接到 Master IPC 端口
    if (!$this->ipcClient->connect('127.0.0.1', $this->controlPort)) {
        $this->log("IPC 控制通道初次连接失败，将自动重连", 'WARN');
        return;
    }
    
    // 步骤 3: 发送 register 消息
    $this->ipcClient->register(
        ControlMessage::ROLE_DISPATCHER,
        \getmypid(),
        $this->port,                      // Dispatcher 监听端口
        0,                                // workerId（Dispatcher 为 0）
        $this->orchestratorEpoch,
        $this->orchestratorLaunchId,
        ControlMessage::PROCESS_KIND_FRAMEWORK,
        '',
        $this->instanceName
    );
    
    // 步骤 4: 发送 ready 消息（上报就绪）
    $this->ipcClient->sendReady(
        ControlMessage::ROLE_DISPATCHER,
        0,
        $this->port,
        $this->orchestratorEpoch,
        $this->orchestratorLaunchId
    );
    
    $this->log('已向 Master 上报就绪状态 (WORKER_READY)', 'INFO');
}
```

### 5.2 IPC ControlClient 的关键方法

**文件**: `app/code/Weline/Server/IPC/ControlClient.php`

#### rememberRegistration() - 记忆注册信息

```php
public function rememberRegistration(
    string $role,          // 'dispatcher'
    int $pid,              // getmypid()
    int $port = 0,         // Dispatcher 监听端口
    int $workerId = 0,     // 0 for Dispatcher
    int $epoch = 0,
    string $launchId = '',
    string $processKind = 'framework',
    string $moduleCode = '',
    string $instanceCode = 'instance-name'
): void
{
    // 保存这些信息用于重连后自动重新注册
    $this->registerInfo = [
        'role'          => $role,
        'pid'           => $pid,
        'port'          => $port,
        'worker_id'     => $workerId,
        'epoch'         => $epoch,
        'launch_id'     => $launchId,
        'process_kind'  => $processKind,
        'module_code'   => $moduleCode,
        'instance_code' => $instanceCode,
    ];
}
```

#### register() - 发送注册消息

```php
public function register(
    string $role,
    int $pid,
    int $port = 0,
    int $workerId = 0,
    int $epoch = 0,
    string $launchId = '',
    string $processKind = 'framework',
    string $moduleCode = '',
    string $instanceCode = ''
): bool
{
    // 保存注册信息（用于重连后自动重新注册）
    $this->registerInfo = [
        'role'          => $role,
        'pid'           => $pid,
        'port'          => $port,
        'worker_id'     => $workerId,
        'epoch'         => $epoch,
        'launch_id'     => $launchId,
        'process_kind'  => $processKind,
        'module_code'   => $moduleCode,
        'instance_code' => $instanceCode,
    ];

    // 发送 register 消息到 Master
    return $this->send(ControlMessage::register(
        $role, $pid, $port, $workerId, $epoch, $launchId, $processKind, $moduleCode, $instanceCode
    ));
}
```

#### connect() - 建立连接

```php
public function connect(string $host, int $port): bool
{
    $this->host = $host;
    $this->port = $port;
    
    // 创建 TCP socket 连接到 Master IPC 控制端口
    try {
        $socket = @\stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            5.0
        );
        if (!$socket) {
            return false;
        }
        \stream_set_blocking($socket, false);
        $this->socket = $socket;
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}
```

---

## 6. Dispatcher 运行循环

**代码文件**: `app/code/Weline/Server/Dispatcher/Dispatcher.php` - `run()` 方法

```php
public function run(): void
{
    $this->log("Started on tcp://0.0.0.0:{$this->port}", 'INFO');
    
    // 禁用协作式调度（Dispatcher 无 Fiber 请求）
    SchedulerSystem::disableScheduler();
    
    while ($this->running) {
        try {
            // 1. 信号处理
            if (\function_exists('pcntl_signal_dispatch')) {
                \pcntl_signal_dispatch();
            }
            
            // 2. IPC 控制通道处理（非阻塞）
            $this->pumpIpcOnce();
            
            // 3. Master 心跳检查（IPC 断开时使用文件方式作为兜底）
            if (!$this->ipcClient || !$this->ipcClient->isConnected()) {
                $this->checkMasterHeartbeat();
            }
            
            // 4. 孤儿检测：检查 Master PID 是否存活
            $this->checkMasterPidAlive();
            
            // 5. Worker 健康探活
            $this->probeWorkerHealth();
            
            // 6. Worker 入池预热 / 黑名单探活（Fiber 分片）
            $this->pumpDeferredWorkerPoolJobs();
            
            // 7. 连接超时清理
            $this->cleanupExpiredConnections();
            
            // 8. 事件处理（accept 新连接、透传数据）
            $this->selectAndProcess();
            
            // 9. 定期统计
            $this->printStats();
            
        } catch (\Throwable $e) {
            // 错误处理与日志
            $this->log("事件循环异常: " . $e->getMessage(), 'ERROR');
        }
    }
    
    $this->shutdown();
}
```

### 6.1 pumpIpcOnce() - IPC 一次轮询

```php
private function pumpIpcOnce(): void
{
    if (!$this->ipcClient) {
        return;
    }

    // 非阻塞读取 Master 消息
    try {
        $this->ipcClient->poll();
    } catch (\Throwable $e) {
        $this->log('IPC poll 异常: ' . $e->getMessage(), 'ERROR');
    }
}
```

### 6.2 IPC 消息处理

Dispatcher 接收来自 Master 的 IPC 消息，并更新 Worker 端口列表、处理维护模式等命令。

---

## 7. Dispatcher 与 Master 通信流程图

```
┌─────────────┐                    ┌──────────────┐
│   Master    │                    │ Dispatcher   │
│  (主进程)   │                    │  (子进程)    │
└──────┬──────┘                    └──────┬───────┘
       │                                   │
       │ 1. 判断是否启用 Dispatcher         │
       │ (MasterProcess -> DispatcherProvider)
       │                                   │
       │ 2. buildCommand() 构建启动参数     │
       │    - host='127.0.0.1'             │
       │    - port=主监听端口               │
       │    - workerBasePort=10000          │
       │    - workerCount=2-N              │
       │    - --control-port=9500          │
       │    - --master-pid=$$$             │
       │                                   │
       │ 3. spawn dispatcher.php           │
       │───────────────────────────────────>
       │    (独立 PHP-CLI 进程)             │
       │                                   │
       │                 4. 创建 TCP socket │
       │                    绑定 0.0.0.0:$port
       │                    listen()        │
       │                                   │
       │                 5. connectIpc()   │
       │                    TCP 连接到    │
       │                    127.0.0.1:9500 │
       │<───────────── register msg ────────
       │                                   │
       │ 6. Master 记录 Dispatcher         │
       │    到 ServiceRegistry              │
       │ 7. ACK_READY                     │
       │───────────────────>               │
       │                                   │
       │                 8. sendReady()    │
       │                    已就绪          │
       │<───────────── ready ack ──────────
       │                                   │
       │ 9. 动态下发 Worker 端口列表       │
       │───────────────────>               │
       │    (via IPC: update_worker_ports) │
       │                                   │
       │                10. run()          │
       │                    主事件循环 ────│
       │                                   │
       │ 11. 周期性心跳 (pumpIpcOnce)    │
       │<────────────────────────────────│
       │                                   │
       │ 12. 若 Master 退出，Dispatcher   │
       │     检测孤儿状态并自动停止        │
       │                                   │
       │ 13. shutdown()                    │
       │     清理资源，退出                 │
       │<────────────────────────────────│
```

---

## 8. 关键代码片段总结

### 8.1 Dispatcher 启动顺序

```plaintext
dispatcher.php 脚本启动
  ↓
参数解析 (host, port, workerBasePort, workerCount, instanceName, --control-port, --master-pid)
  ↓
LongRunningPhpRuntime 应用 (优化 PHP 运行配置)
  ↓
框架初始化 (WlsRuntime)
  ↓
创建 TCP socket（非阻塞）
  ↓
实例化 Dispatcher 类
  ↓
配置参数 (sni_routing_enabled, learning_mode_enabled 等)
  ↓
connectIpc() 连接 Master IPC
  ├─ 记忆注册信息 (rememberRegistration)
  ├─ 建立 TCP 连接到 Master (connect)
  ├─ 发送 register 消息
  └─ 发送 ready 消息
  ↓
setMasterPid() 设置 Master PID（孤儿检测）
  ↓
setLifecycleTokens() 设置生命周期令牌
  ↓
run() 启动主事件循环
  ├─ pumpIpcOnce() - IPC 消息轮询
  ├─ checkMasterHeartbeat() - Master 心跳检查
  ├─ checkMasterPidAlive() - PID 孤儿检测
  ├─ probeWorkerHealth() - Worker 健康探活
  ├─ selectAndProcess() - 事件处理（accept、透传）
  └─ printStats() - 统计输出
```

### 8.2 IPC 注册的完整调用链

```plaintext
connectIpc()
  ├─ new ControlClient()
  ├─ rememberRegistration()
  │  └─ 保存注册信息 $registerInfo
  ├─ markReadyState(true)
  ├─ onMessage(callback)
  ├─ onDisconnect(callback)
  ├─ connect('127.0.0.1', controlPort)
  │  └─ stream_socket_client() 建立 TCP 连接
  ├─ register($role, $pid, $port, ...)
  │  └─ send(ControlMessage::register(...))
  │     └─ 序列化为 NDJSON 格式发送
  └─ sendReady($role, $workerId, $port, ...)
     └─ send(ControlMessage::ready(...))
```

---

## 9. 答案汇总

| 问题 | 答案 |
|------|------|
| **1. Master 主类位置** | `app/code/Weline/Server/Service/MasterProcess.php` 和 `ServiceOrchestrator.php` |
| **2. 调用 Dispatcher 位置** | `app/code/Weline/Server/Service/Provider/DispatcherProvider.php#buildCommand()` |
| **3. Dispatcher 进程类型** | ✅ 独立进程，通过 `dispatcher.php` 脚本启动 |
| **4. IPC 注册机制** | ✅ 通过 `connectIpc()` → `register()` → `sendReady()` 三步注册 |
| **5. 是否直接实例化** | ❌ 否，Master 通过 Processer::spawn() 启动独立子进程 |
| **6. 注册方法名** | `ControlClient::register()` 和 `ControlClient::rememberRegistration()` |

---

## 10. 补充说明

### 孤儿检测机制

```php
// Dispatcher 定期检查 Master 是否存活
private function checkMasterPidAlive(): void
{
    if ($this->masterPid <= 0) {
        return;
    }
    
    $now = \time();
    if (($now - $this->lastMasterPidCheck) < 5) {
        return;
    }
    $this->lastMasterPidCheck = $now;
    
    // IPC 连接正常 → Master 存活
    if ($this->ipcClient && $this->ipcClient->isConnected()) {
        $this->masterDeadCount = 0;
        return;
    }
    
    // IPC 断开，用 PID 检测确认 Master 是否真的死了
    $alive = Processer::isRunningByPid($this->masterPid);
    if (!$alive) {
        $this->masterDeadCount++;
        if ($this->masterDeadCount >= 3) {
            $this->log('Master 已退出，Dispatcher 自动停止', 'ERROR');
            $this->running = false;
        }
    }
}
```

### Worker 端口动态更新

Master 通过 IPC 消息动态下发 Worker 端口列表给 Dispatcher，Dispatcher 在处理新连接时通过 `PassthroughCore` 查询当前可用的 Worker 端口进行轮询转发。

