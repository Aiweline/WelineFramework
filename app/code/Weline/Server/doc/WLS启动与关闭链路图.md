# WLS 启动与关闭链路图

## 适用范围

- 本文描述默认 WLS Orchestrator 模式下的单实例链路，即 `php bin/w server:start [name]` 与 `php bin/w server:stop [name]`。
- `--cli` 和 `--strategy` 会在 `Start::execute()` 早期分流，不进入本文的 Master/Orchestrator 主链路。
- `server:stop --all` 只是外层枚举多个实例，单实例的关闭协议仍然复用本文的关闭链路。

## 启动链路图

```mermaid
flowchart TB
    A["CLI: php bin/w server:start [name]"] --> B["Start::execute()"]
    B --> C{"启动分支?"}
    C -->|--cli| C1["转到 CLI Server 链路<br/>不进入 WLS Master/Orchestrator"]
    C -->|--strategy| C2["executeWithStrategy()<br/>不进入默认 WLS Orchestrator"]
    C -->|--master-only| M1["runMasterOnly(instance)<br/>从 instance.json 恢复 Master 运行态"]
    C -->|默认 WLS| D["acquireStartLock(instance)"]
    D --> E["getServerConfig()<br/>解析 host/port/count/frontend/ssl"]
    E --> F{"是否需要先清旧实例?"}
    F -->|是: -r 或端口/实例冲突| G["stopExistingServer()<br/>内部委托 server:stop"]
    F -->|否| H["检查主端口 / worker 端口 / redirect 端口"]
    G --> H
    H --> I["saveInstanceInfo()<br/>预写 var/server/instances/{instance}.json"]
    I --> J["saveInstanceConfig()<br/>syncServerConfigToEnv()"]
    J --> K{"daemon?"}
    K -->|后台| L["startMasterInBackground()<br/>后台拉起 server:start --master-only<br/>并轮询 instance.json 等待 master_pid/control_port"]
    K -->|前台| M["runMasterProcess()<br/>当前进程直接进入 Master"]
    L --> M1
    M --> N["MasterProcess::run()"]
    M1 --> N
    N --> O["registerMasterPid()<br/>cleanupStaleInstanceFiles()<br/>分配 control_port"]
    O --> P["ServiceOrchestrator::bootstrapControlPlane()<br/>启动 IPC 控制面并预置 maintenance"]
    P --> Q["saveMasterInfo('bootstrapping')<br/>instance.json 写入 master_pid/control_port/startup_phase"]
    Q --> R["runLoopWithDeferredChildStartup()"]
    R --> S["startAllChildServices()<br/>并发拉起 Session/Memory/Dispatcher/Worker/Redirect/Maintenance"]
    S --> T["waitForStartupAcceptance()<br/>等待关键角色 READY 达标"]
    T --> U["persistServicesInfo()<br/>broadcastRoutingPolicyToWorkers()"]
    U --> V["armServerReadyNotification()<br/>startup_phase -> running"]
    V --> W["释放 start lock<br/>进入常驻主循环"]
```

## 关闭链路图

```mermaid
flowchart TB
    A["CLI: php bin/w server:stop [name]"] --> B["Stop::execute()"]
    B --> C{"--all?"}
    C -->|是| C1["stopAllInstances()<br/>枚举实例逐个 stop"]
    C -->|否| D["acquireStopLock(instance)"]
    D --> E["ServerInstanceManager::getInstanceInfo()"]
    E --> F{"实例记录存在?"}
    F -->|否| F1["按 recoverable 线索清理残留进程<br/>然后结束"]
    F -->|是| G{"是否跳过优雅停机?"}
    G -->|fast-local| G1["直接终止候选 PID<br/>然后做 residual cleanup"]
    G -->|startup_phase != running<br/>或仍有 pending service| G2["跳过 IPC 优雅停机<br/>本地 kill master + residual cleanup"]
    G -->|正常路径| H{"Master / control_port 是否可用?"}
    H -->|Master 缺失但 control_port 还在| H1["sendStopViaIpcAndWait()"]
    H -->|Master 与 control_port 正常| H1
    H -->|都不可用| H2["runResidualCleanupPairWithRetry()"]
    H1 --> I["IPC ACTION_STOP -> Master"]
    I --> J["MasterProcess::stopWithProgress()<br/>ServiceOrchestrator::requestStop()"]
    J --> K["主循环调度 stopAll()"]
    K --> L["阶段1: Dispatcher DRAIN<br/>停止派发新请求"]
    L --> M["阶段2: 等待 Dispatcher 排水完成"]
    M --> N["阶段3: releaseSharedStateConsumersForStopFlow()<br/>并发终止非共享进程"]
    N --> O["阶段4: verifyAndKillRemainingProcesses()"]
    O --> P["阶段5: closeIpcServer()<br/>Master 退出"]
    P --> Q["CLI 侧 waitForMasterExit()"]
    Q --> R["runResidualCleanupPairWithRetry()<br/>做最后兜底清理"]
    G1 --> R
    G2 --> R
    H2 --> R
    R --> S{"残留是否清理完?"}
    S -->|否| S1["保留 instance metadata<br/>等待后续继续清理"]
    S -->|是| T["releaseSharedStateConsumersForInstance()<br/>deleteInstance()<br/>cleanupPidFiles()<br/>releaseStartLock()"]
    T --> Z["stop 完成"]
    F1 --> Z
    C1 --> Z
```

## 关键分支说明

- `server:start -r` 会先通过 `stopExistingServer()` 复用 `server:stop` 链路清理旧实例，再进入新的启动链路。
- `server:start -r -f` 属于停机型切换，旧实例不会走平滑排水等待，而是更快进入本地清理。
- `server:stop -f` 仍然优先走 IPC STOP，但会把 Orchestrator 切到 `skipDrain=true`，也就是跳过关闭阶段 1/2，直接进入统一终止、校验和关闭 IPC。
- 如果 CLI 侧等待 IPC 进度超时，且判断停机流并未继续推进，`Stop` 会强杀 Master 并执行本地 residual cleanup。
- 如果本地 residual cleanup 后仍检测到残留进程，`Stop` 不会立刻删除 `var/server/instances/{instance}.json`，而是保留元数据，避免失去后续恢复和继续清理的控制线索。

## 关键代码锚点

- `app/code/Weline/Server/Console/Server/Start.php`
  - `execute()`
  - `runMasterOnly()`
  - `startMasterInBackground()`
  - `runMasterProcess()`
  - `saveInstanceInfo()`
- `app/code/Weline/Server/Service/MasterProcess.php`
  - `run()`
  - `saveMasterInfo()`
  - `stopWithProgress()`
- `app/code/Weline/Server/Service/ServiceOrchestrator.php`
  - `bootstrapControlPlane()`
  - `startAll()`
  - `runLoopWithDeferredChildStartup()`
  - `requestStop()`
  - `stopAll()`
- `app/code/Weline/Server/Console/Server/Stop.php`
  - `execute()`
  - `stopInstance()`
  - `sendStopViaIpcAndWait()`
  - `runResidualCleanupPairWithRetry()`
- `app/code/Weline/Server/Service/ServerInstanceManager.php`
  - `getInstanceInfo()`
  - `deleteInstance()`
  - `finalizeAfterMasterExit()`

## 读图建议

- 启动图里，`Start.php` 负责“参数固化、锁、端口/证书/实例快照”；`MasterProcess` 负责“控制面启动与主循环”；`ServiceOrchestrator` 负责“子服务并发启动、READY 验收和运行期调度”。
- 关闭图里，CLI `Stop.php` 既是停机发起方，也是最终兜底清理方；真正的统一停机协议在 `ServiceOrchestrator::stopAll()` 中完成。
