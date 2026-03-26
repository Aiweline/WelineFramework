---
name: WLS 异步控制面与帝王指令重构计划
overview: 将 WLS 的启动、重载、停机与控制面命令从“IPC 回调里同步执行 + 多层 while/poll/usleep 阻塞等待”重构为事件驱动的主循环调度器，解决主控响应慢、命令重叠、广播阻塞和状态热路径放大等问题。
status: proposed
isProject: true
todos:
  - id: baseline-and-instrumentation
    content: 增加控制面阶段耗时、队列长度、广播耗时、每轮主循环预算等观测指标
    status: pending
  - id: imperial-arbiter
    content: 引入帝王指令仲裁器与操作队列，禁止重操作继续在 IPC 回调里同步执行
    status: pending
  - id: startup-state-machine
    content: 将 startAll 拆为可分步推进的启动状态机
    status: pending
  - id: reload-stop-state-machines
    content: 将 reload / rolling_restart / stop 拆为可取消、可抢占的状态机
    status: pending
  - id: nonblocking-ipc-send
    content: 将 MasterControlServer 发送链路改为非阻塞写队列与背压模型
    status: pending
  - id: hotpath-coalescing
    content: 将 persistServicesInfo、routing-policy broadcast、dispatcher full-sync 改为脏标记 + 合并刷写
    status: pending
  - id: verification
    content: 建立单测、集成验证、真实启动/重载/停机性能回归基线
    status: pending
---

# WLS 异步控制面与帝王指令重构计划

## 1. 现状诊断

当前 WLS 的核心问题不是“单个 sleep 太长”，而是控制面架构仍然以**同步阻塞式编排**为主，导致 master 主循环经常失去及时响应能力。

### 1.1 IPC 回调里直接执行长流程

在 [ServiceOrchestrator.php](e:/WelineFramework/DEV-workspace/app/code/Weline/Server/Service/ServiceOrchestrator.php) 的 `handleCommand()` 中，以下重操作仍直接在消息回调里同步执行：

- `ACTION_RELOAD` 直接调用 `reloadAll()`。
- `ACTION_RELOAD_WAIT` 在回调里直接跑完整 `reloadAll()`。
- `ACTION_ROLLING_RESTART` 在回调里直接跑 `startRollingRestart()`。
- `ACTION_MAINTENANCE_ENABLE` / `ACTION_MAINTENANCE_DISABLE` 也直接同步执行。

这意味着 master 的“消息泵”会在处理命令时把自己堵住。虽然很多内部等待循环会间歇 `poll()`，但这不是异步调度，只是“在一个大阻塞函数里手动让出一点点机会”。

### 1.2 启动 / 重载 / 停机大量使用嵌套等待循环

当前关键链路普遍采用：

- `while (...) {`
- `controlServer->poll(0, 100000);`
- `SchedulerSystem::usleep(...)`
- `状态轮询`
- `}`

典型位置包括：

- `waitForStartupAcceptance()`
- `waitForDrain()`
- `waitForInstanceReady()`
- `waitForAllDrained()`
- `waitForAllDisconnectedWithProgress()`
- `reloadAll()` 内部的 maintenance 切换等待
- `restartWorkerBatchDispatcherAware()` 的 drain / exit / ready 多阶段等待

问题不只是“慢”，而是这些局部循环**吞掉了主循环调度权**。结果是：

- worker 早已 register/ready，上层流程还在本地等待窗口里慢慢转；
- stop/reload/rolling restart 期间，master 虽然没完全死，但响应明显迟钝；
- 多条命令会在各种局部 while 里以“不完整抢占”的方式互相干扰。

### 1.3 Master 出站 IPC 是同步阻塞写

在 [MasterControlServer.php](e:/WelineFramework/DEV-workspace/app/code/Weline/Server/IPC/MasterControlServer.php) 中：

- `sendTo()` 调用 `writeFully()`
- `writeFully()` 通过 `stream_select + fwrite` 循环写完整条消息
- 每个 client 默认最多可阻塞到 `1.0s`

这会带来一个很危险的放大效应：

- 广播给 `N` 个 worker/dispatcher 时，最坏会串行放大到 `N * 1s`
- routing policy、drain、shutdown、reload、dispatcher pool sync 等都可能触发多目标发送
- master 会在“发消息”这件事上卡住，进而延迟读取新的 register/ready/ack/disconnect

这也是“worker 明明已经上报了，master 还是反应慢”的高概率根因之一。

### 1.4 热路径副作用太多

实例 register/ready/disconnect 的热路径里，master 还会同步做不少额外工作：

- `notifyDispatcherWorkerReady()`
- `notifyDispatcherRemoveWorker()`
- `syncDispatcherFullWorkerPoolFromRegistry()`
- `broadcastRoutingPolicyToWorkers()`
- `persistServicesInfo()`

其中两个尤其值得警惕：

- `persistServicesInfo()` 会把全量实例状态转成 `ServiceInfo` 再写入实例文件。
- `broadcastRoutingPolicyToWorkers()` 会逐个 worker 同步发送。

这些动作如果不做脏标记/合并刷写，就会在高频状态上报阶段不断放大 master 负担。

### 1.5 帝王指令语义不统一

当前帝王指令模型只有“半套”：

- `stop` 走 `requestStop()`，进入 `pendingStopReason`，由主循环消费，方向是对的。
- `reload_wait` / `rolling_restart` 会占用 `ipcExclusiveCommand`，但仍在命令回调里同步执行。
- 普通 `reload` 是长流程，却不是 imperial，也不会先排队再由主循环调度。

因此现在会出现几类问题：

- `reload` 自己就是长流程，但没有统一仲裁语义；
- `stop` 想抢占时，经常只能等当前长流程在内部 while 里“偶尔检查一下”；
- 一部分命令是排队式，一部分是回调内同步式，导致并发时边界非常模糊；
- “帝王指令要清场”这个目标，在现有实现里并没有成为统一状态机规则。

## 2. 设计目标

本次重构的目标不是“把 sleep 调短”，而是把 WLS 控制面改成真正的**事件驱动主循环**：

1. 所有长流程都不能继续在 IPC 回调里同步执行。
2. master 主循环只做“poll -> drain inbox -> step operations -> flush outbound -> do periodic jobs”。
3. 所有等待都改成“deadline + 状态检查”，不再用局部 while 独占控制权。
4. 出站 IPC 必须支持非阻塞写队列和背压，不能再一条条同步写到完成。
5. 帝王指令必须统一仲裁、明确抢占规则、明确取消语义。
6. 热路径只更新内存态；持久化、全量同步、广播改成可合并、可延后、可去重。

## 3. 目标架构

### 3.1 Control Plane Scheduler

在 `ServiceOrchestrator` 内引入一个明确的调度层，建议新增：

- `pendingCommands` 或 `operationQueue`
- `activeExclusiveOperation`
- `pendingExclusiveOperation`
- `dirtyFlags`
- `timers / deadlines`

核心约束先明确下来：

- `Master` 任一时刻最多只允许 `1` 个 active control operation；其他控制命令一律只能入队，不能再直接插入执行。
- `register` / `ready` / `draining_complete` / `disconnect` / `exited` 这类子进程协议消息不属于 control operation，必须继续实时处理，否则 active operation 自己也无法推进。
- operation 需要统一信封结构，至少包含：`operationId`、`kind`、`clientId`、`state`、`queuedAt`、`startedAt`、`deadline`、`epochSnapshot`、`payload`、`coalescedFrom`。
- operation 生命周期建议统一为：`queued -> starting -> running -> aborting -> completed|failed|cancelled`。
- 每个 tick 对 active operation 只允许做一次有限 step；不允许 operation 内部再套新的 `while + poll + usleep` 独占主循环。

`handleCommand()` 只做三件事：

1. 解析命令
2. 交给仲裁器判定是否可受理
3. 入队并立刻回 ACK / operation_id

禁止在这里直接调用：

- `reloadAll()`
- `startRollingRestart()`
- `enableMaintenanceMode()`
- `disableMaintenanceMode()`
- 任何 stop/restart 的完整流程

CLI 收到的同步回包也需要统一：

- `accepted=true/false`
- `operation_id`
- `state=queued|coalesced|rejected|running`
- `queue_position`
- `active_operation`
- `message`

这样 CLI、日志和后续观测面板才能说同一种语言。

### 3.2 Imperial Command Arbiter

增加统一帝王仲裁器，命令按语义分组：

#### A. `preemptive-exclusive`

- `stop`

规则：

- 可抢占所有非 stop 操作
- 抢占时 bump `imperialEpoch`
- 取消队列中的其他 exclusive/normal 操作
- 把当前活动操作置为 `aborting`

#### B. `exclusive-serialized`

- `reload_wait`
- `rolling_restart`
- `maintenance_enable`
- `maintenance_disable`

规则：

- 同一时刻只能有一个运行
- 若已有 exclusive 在跑，则返回“busy”或排队，二选一但要全局一致
- 必须支持被 `stop` 抢占

#### C. `normal-queued`

- `reload`
- `status`
- `telemetry_query`
- `fiber_*`

规则：

- 轻量命令可直接执行
- 长命令也要入队，不允许继续在 callback 内跑完整流程

#### D. `fanout-fast`

- `cache_clear`
- `ssl_cert_reload`
- `pagebuilder_page_invalidate`

规则：

- 默认不抢占 exclusive
- 进入 outbound queue 即可完成
- 如果 exclusive 已锁控制面，可配置为拒绝或延后

仲裁器输出不应只有“能不能执行”，而应统一返回四种动作之一：

- `enqueue`: 正常入队，等待 active operation 结束
- `coalesce`: 与已有同类操作合并，避免队列膨胀
- `preempt`: 抢占当前 active operation，并清场/清队
- `reject`: 明确拒绝，并给出统一原因

建议的首轮命令准入矩阵：

- `stop`：`preempt` 当前 active operation，清空待执行队列，只保留自己；这是唯一允许清场抢占的命令。
- `reload` / `reload_wait` / `rolling_restart` / `maintenance_enable` / `maintenance_disable`：默认 `enqueue`，第一阶段宁可保守串行，也不要再保留半并发特例。
- `cache_clear` / `ssl_cert_reload` / `pagebuilder_page_invalidate`：优先 `coalesce`，若当前有 active mutating operation，则延后到队尾统一发。
- `status`：如果只是本地快照可直接 inline；一旦需要等待异步聚合结果，则也走 operation queue。
- `telemetry_query` / `fiber_*`：凡是需要等 Worker 回包的请求，都按 operation 处理，避免与 stop/reload 交错。

这一步的目标不是追求“最少排队”，而是先把控制面语义压平：

- 所有变更型命令先统一变成“单活 + 排队 + 可观察”
- 先消灭重叠执行，再逐步恢复必要的快速路径

### 3.3 Startup Coordinator

把 `startAll()` 拆成状态机，而不是一口气执行完整启动链：

- `phase1_submit`
- `phase1_acceptance`
- `worker_submit`
- `worker_acceptance`
- `ready_arm`
- `complete`

每个 tick 只推进一步，不做长 while：

- 提交 phase-1 后立即返回主循环
- 后续由 register/ready 上报驱动 acceptance 进度
- 达到阈值则推进到下一 phase
- 全部满足后 arm ready notification

这样 master 在启动期间仍能实时处理：

- worker 上报
- 新命令
- 中断/stop
- 状态查询

### 3.4 Reload / Stop / Rolling Restart Coordinator

为以下流程各做显式 operation state machine：

- `StartupOperation`
- `ReloadOperation`
- `RollingRestartOperation`
- `StopOperation`

每个 operation 具备：

- `state`
- `startedAt`
- `deadline`
- `epochSnapshot`
- `operationId`
- `progress`

示例：`StopOperation`

- `request_drain`
- `wait_drain`
- `request_shutdown`
- `wait_disconnect`
- `verify_kill`
- `close_ipc`
- `done`

示例：`ReloadOperation`

- `enable_maintenance_if_needed`
- `prepare_batch`
- `remove_from_dispatcher`
- `request_drain`
- `wait_drain`
- `request_stop`
- `wait_exit`
- `submit_start`
- `wait_ready`
- `rejoin_dispatcher`
- `next_batch`
- `disable_maintenance_if_needed`
- `done`

这些状态都只允许在 `runLoop()` 中 step 一次，绝不允许内部再套一个长期 while。

### 3.5 Non-blocking Outbound IPC

`MasterControlServer` 需要从“同步 send”升级为“异步 flush”：

- 每个 client 增加 `outboundQueue`
- `sendTo()` 变成 enqueue，而不是 `writeFully()`
- `poll()` 同时监听 read/write
- write-ready 时尽可能 flush 一部分缓冲
- 支持 partial write / backpressure / slow-client timeout

建议新增字段：

- `clients[$id]['outbound_buffer']`
- `clients[$id]['outbound_queue']`
- `clients[$id]['last_write_at']`
- `clients[$id]['blocked_since']`

新规则：

- 普通消息入队即可返回
- 重要控制消息可标记 `priority`
- 单个 client 长时间写不出去时，不应拖住整个 master
- 对失速 client 触发断开或降级，而不是让全局控制面跟着阻塞

### 3.6 热路径合并与去重

以下动作改为“脏标记 + 合并刷写”：

- `persistServicesInfo()`
- `syncDispatcherFullWorkerPoolFromRegistry()`
- `broadcastRoutingPolicyToWorkers()`

建议规则：

- registry 脏了只记 `services_dirty=true`
- 每 `100~250ms` 最多刷一次持久化
- worker pool signature 变更才发 dispatcher full sync
- routing policy hash 变化才广播
- 广播任务进入 outbound queue，不在热路径同步写

## 4. 分阶段实施

### Phase 0. 观测先行

先补指标，否则只会继续靠感觉修：

- master loop 单轮耗时
- `poll` 耗时
- command queue 长度
- active operation 状态与停留时长
- 单次 broadcast 的 fanout 数量和耗时
- 每 client outbound backlog
- startup / reload / stop 各阶段耗时

建议输出：

- `telemetry_query` 增补 orchestrator metrics
- 关键阶段写入结构化日志

### Phase 1. 先把命令回调瘦身

最优先实施：

1. `handleCommand()` 只做入队和 ACK
2. 加 `ImperialCommandArbiter`
3. `runLoop()` 每轮只 step 当前 operation

这是整个重构的地基。没有这一步，后面所有优化都会继续被“回调里同步跑长流程”拖垮。

这一阶段再拆成两个很务实的小步：

- `Phase 1A`: 先把“Master 同时只执行一个操作，其他操作存着”落地，解决命令打架、清场混乱和维护复杂度问题。
- `Phase 1B`: 再把现有 `reload_wait` / `rolling_restart` / `maintenance_*` 从 callback 挪到 operation wrapper 下运行，先统一入口，再继续拆状态机。

要明确一个工程事实：

- 只做 `Phase 1A/1B` 还不能彻底解决“启动很慢 / worker 早上报但 master 很晚才响应”。
- 它解决的是“控制操作互相踩踏”。
- 真正解决响应慢，要继续推进 `Phase 2` 和 `Phase 3` 的状态机化，以及 `Phase 4` 的非阻塞 IPC。

### Phase 2. 启动链路状态机化

把 `startAll()` 改为 non-blocking coordinator：

- 不再本地 while 等 acceptance
- phase 提交后立即回主循环
- worker ready/register 自己上报，master 只改 registry
- 满足阶段条件后在下一个 tick 推进状态

这一步会直接改善你当前最痛的“worker 明明好了，master 还是慢”的问题。

### Phase 3. 重载/停机状态机化

优先重构：

- `reload_wait`
- `rolling_restart`
- `stop`

理由：

- 这三类流程最长
- 帝王指令冲突最严重
- 当前局部 while 最多

### Phase 4. Master 出站 IPC 非阻塞化

把 `writeFully()` 从热路径剥离出去，改成队列 flush。

这是另一个高收益点，因为一旦存在慢 client 或广播 fanout，当前实现会明显拖慢 master。

### Phase 5. 热路径副作用削峰

最后处理：

- persistence debounce
- routing policy hash / broadcast debounce
- dispatcher pool sync coalescing

## 5. 验收标准

### 5.0 控制面一致性

- 任意时刻最多只有 `1` 个 active control operation。
- 当存在 active control operation 时，其他变更型命令只能得到 `queued` / `coalesced` / `rejected` 结果之一，不能继续直接执行。
- `stop` 到达后，会在一个主循环 tick 内把当前 active operation 置为 `aborting`，并清空后续待执行队列。
- 子进程 `register` / `ready` / `disconnect` / `exited` 事件在 active operation 存在时仍持续入库和驱动状态推进。
- 日志、CLI 回包、后续 telemetry 都能看到同一个 `operation_id` 与 `operation state`。

### 5.1 启动

- `server:start` 期间 master 仍能实时响应 `status`
- worker register/ready 到最终 registry 更新的延迟显著下降
- “服务器已就绪”只在最后阶段输出
- 启动过程中不再出现长时间停留在 phase-local wait loop 的表现

### 5.2 重载

- `reload` / `reload_wait` 期间 master 仍可处理新的 `status`
- `stop` 可以抢占当前 reload，并进入统一清场
- 不再出现 reload 和 stop 部分并发交织

### 5.3 停机

- `stop` 期间 master 不会因为某个慢 client 的 socket write 卡住
- stage 进度由 operation 状态机推进
- 所有清场动作可追踪、可中断、可观测

### 5.4 IPC

- 多 worker 广播不再线性拖慢 master 事件处理
- 慢 client 只影响自己，不拖全局

## 6. 测试与验证计划

### 单元测试

- imperial arbiter 抢占/拒绝/排队规则
- operation queue 状态流转
- startup coordinator phase 推进
- reload/stop coordinator 在 epoch 变化下的 abort
- outbound IPC partial write / backpressure / timeout

### 集成验证

- 启动中途发 `status`
- `reload_wait` 过程中插入 `stop`
- `rolling_restart` 过程中插入 `stop`
- 多 worker ready 同时上报
- 广播 routing policy / drain / shutdown 时模拟慢 client

### 性能基线

至少记录：

- 3 worker / 12 worker / 24 worker 启动总耗时
- 首个 worker ready 到所有 worker ready 到 final ready banner 的时间差
- `reload_wait` 端到端耗时
- `stop` 端到端耗时
- master loop p95 / p99 tick latency

## 7. 推荐实施顺序

1. 先做 `Phase 1: 命令回调瘦身 + imperial arbiter`
2. 再做 `Phase 2: startup state machine`
3. 再做 `Phase 3: reload/stop state machine`
4. 然后做 `Phase 4: non-blocking outbound IPC`
5. 最后做 `Phase 5: 热路径削峰`

原因：

- 前三步先解决“主循环不是主循环”的根问题
- 第四步解决“广播和发送拖住 master”的放大器
- 第五步再做状态持久化和同步优化，收益最大也最稳

## 8. 本轮结论

这不是“IPC 架构完全不合适”，而是**当前控制面把异步服务器写成了同步编排器**：

- IPC 读是非阻塞的
- 但命令执行、等待策略、广播发送、状态副作用仍大量是同步阻塞的

所以方向不是推翻 IPC，而是把它补全为真正的：

- 非阻塞消息泵
- 操作队列
- 状态机调度器
- 非阻塞写队列
- 严格帝王仲裁

只要这五个点落地，WLS 的启动、重载、停机、清场和命令一致性都会明显改善。
