# WLS Supervisor 宏观 HA 蓝图 — Phase 1 交付边界

> 撰写日期：2026-04-23
> 作者：Codex（依据 2026-04-23 代码快照）
> 上游文档：`WLS-HA-IPC-REDESIGN-2026-04-15.md`
> 并行任务：`WLS-MASTER-SELF-HEAL-HA-DESIGN-2026-04-23.md`（子进程自愈 v2）
> 本任务：`dev/ai/codex/tasks/2026-04-23/2026-04-23-2330-wls-supervisor-blueprint-phase-1`

本文件只定义 **Phase 1 必须交付的最小集合**，以及"Supervisor 与子进程自愈"的共存策略。
Phase 1 **不写主循环代码**、**不引入外部依赖**（etcd/Consul）、**不重构控制协议**。
Phase 1 拿到签收后，Phase 2 可直接按本文档 §4 的 "Phase 2 开工 checklist" 起工。

---

## 1. 现状复盘（2026-04-23 代码快照）

Supervisor 相关代码**已在仓库里成型**，不是白纸。这是决定 Phase 1 边界的最重要事实。

| 模块 | 路径 | 已做 | 缺口 |
| --- | --- | --- | --- |
| Supervisor 核心 | `app/code/Weline/Server/Supervisor/Supervisor.php` | HELLO / READY / HEARTBEAT 处理；Worker pool snapshot 组装 | **无写 lease 语义**；只能做"池可见性"，不能仲裁写 |
| Lease 注册表 | `Supervisor/Lease/LeaseRegistry.php`、`SlotLease.php` | slot-level lease（每个 Worker 一个槽位）；assign / release / generation 递增 | 没有 `acquire_write_lease` / `renew_write_lease` / `revoke_write_lease` 三元组 |
| 协议 | `Supervisor/Protocol/SupervisorMessage.php` | `hello / ready / ready_ack / heartbeat / pool_snapshot` | 无 `claim_write_lease` / `release_write_lease` / `lease_renew` |
| 终端解析 | `Supervisor/Endpoint/ControlEndpointResolver.php`、`ControlEndpoint.php` | Unix domain socket（Linux）/ TCP 回退（Windows）；按 instanceName 稳定端口 | 无跨主机发现机制（本阶段不做） |
| Child 客户端 | `Supervisor/Client/SupervisorChildClient.php` | 子进程→Supervisor 心跳 / READY 上报 | 没有 lease 续约循环；pool snapshot 消费路径不完整 |
| Hybrid 控制面 | `Service/Control/HybridControlPlaneServer.php` | Master 直接接控制 IPC（现状路径） | Master **仍是唯一写主体**，Supervisor 还没拿到任何写操作授权 |
| 子进程自愈（并行任务） | `IPC/ResurrectionCoordinatorInterface.php`、`MasterResurrector` | Master 崩溃时，子进程可竞争复活 Master（flock 互斥） | 未来要与 Supervisor 的 write lease 双重互斥 |

**关键结论**：Supervisor 的**骨架已搭好**，**读取面**（snapshot）已打通，**写入面**还没开始收口。Phase 1 的交付正是把"写入面"在**设计上**锁死，代码改动只到"协议常量 + 轻量 LeaseRegistry 扩展 + Master 侧的网关 stub"这一层。

---

## 2. Phase 1 交付边界

### 2.1 Supervisor 侧（代码改动可预期 < 200 行）

- `SupervisorMessage` 新增三条消息常量（**仅定义，不实装路由**）：
  - `claim_write_lease` — 请求"写某个资源"的独占授权
  - `renew_write_lease` — 在 TTL 过半时续约
  - `release_write_lease` — 主动释放（优雅停机路径）
- `LeaseRegistry` 新增 `writeLeases` 子表（与现有 `slotLeases` 解耦）：
  - key = `resource_id`（例：`instance:<name>:master_writer`）
  - value = `{holder_id, acquired_at, expires_at, generation}`
  - 提供 `tryAcquireWrite($resourceId, $holderId, $ttlSec)` / `renewWrite(...)` / `releaseWrite(...)`
  - **不引入文件锁**；这层只是内存仲裁，fencing 靠 Phase 2 的 flock/atomic-rename 兜底
- `Supervisor::handle()` 仅对新消息**返回 `null`**（兼容："协议已定义但拒绝实装"的显式姿态），Phase 2 再接 LeaseRegistry。

### 2.2 Master 侧（代码改动 stub only，< 80 行）

- 新增 `Service/Control/AcceptWriteThroughGateway`（**接口 + no-op 实现**）：
  - 语义：所有"改变集群状态的写操作"（reload / stopAll / scale / maintenance toggle）在 Phase 1 仅经过这个网关**包一层**，实现体直接走旧路径（本地执行）。
  - 真实的 "向 Supervisor 请求 write lease" 逻辑在 Phase 2 实装，此处**只预留缝**，避免 Phase 2 破坏性改动扩散到全 Orchestrator。
- `ServiceOrchestrator::stopAll / reloadAll / requestStop` 的入口**不改逻辑**，只在前面加一行 "网关观察" 调用（允许单测注入 spy 验证后续签名稳定）。

### 2.3 子进程侧（不改代码）

- `SupervisorChildClient` 本 Phase **不改**。lease 续约循环推后到 Phase 2，与写路径一起落地。
- 子进程自愈（并行任务 `MasterResurrector`）**不受影响**：其 fencing 靠 flock，与 write-lease 语义正交。

### 2.4 文档 / 协议治理

- 本文件：交付边界（你现在读的这份）
- `WLS-HA-IPC-REDESIGN-2026-04-15.md` 对应章节追加"2026-04-23 现状对齐"小节
- `dev/ai/codex/tasks/.../result.md` 登记 §5 的"悬而未决问题"给 Phase 2 接手

---

## 3. 共存矩阵：Supervisor write-lease × 子进程自愈

两者**同时存在**，目标不冲突：

| 事件 | 谁先响应 | 为什么不冲突 |
| --- | --- | --- |
| Master 正常停机（`stopAll shutdown`） | Supervisor（释放所有 write lease） | 子进程收到 SHUTDOWN 后不会触发自愈——`self_heal` 只对"非 SHUTDOWN 的 Master 消失"生效 |
| Master 崩溃（SIGKILL / OOM） | 子进程自愈竞争 flock，胜出者拉起新 Master | 新 Master 启动时向 Supervisor 重新 `claim_write_lease`；旧 lease TTL 过期后自然失效 |
| 网络分区导致 Master↔Supervisor 失联 | 双方各自退让：Master 继续本地服务（读路径不阻塞），Supervisor 在 TTL 过期后收回 lease | 重连时由 Master 重新 claim；期间若有"另一半脑"试图 claim，TTL 互斥 + flock 兜底保证单写 |
| Supervisor 进程挂了 | 子进程自愈**不参与**（它只守 Master）；Supervisor 由独立 supervisord/systemd 拉起 | 隔离不同故障域：Supervisor 崩不应引发 Master/Worker 抖动 |

**过渡期 `allow_child_resurrection` 默认值演进**：

- **现阶段（Phase 0~1）**：默认 `on`（见并行任务），因为没有 Supervisor write-lease 仲裁，子进程自愈是**唯一**的 Master 恢复途径。
- **Phase 2 落地后**：默认仍 `on`，但子进程在复活 Master 之前会尝试 `claim_write_lease`；失败（说明另一节点已经活着）则退出自愈，避免双脑。
- **Phase 4 控制面完全收口后**：允许运维关闭 `allow_child_resurrection`，把全部恢复责任交给 Supervisor 仲裁；保留为 opt-out 而非删除，服务单机部署的兼容场景。

---

## 4. Phase 2 开工 checklist（本 Phase 交付后即可复制为 task.md）

1. LeaseRegistry 从"只认 slot lease" 扩到 "slot + write lease 双表"，补齐三元组实装
2. `Supervisor::handle()` 对 `claim_write_lease` / `renew_write_lease` / `release_write_lease` 真正路由到 LeaseRegistry
3. `AcceptWriteThroughGateway` 从 no-op 改为"先向 Supervisor 请求，再执行"——只从 **一条** 写操作开始（建议 `reloadAll`，爆炸半径可控）
4. `SupervisorChildClient` 补 lease 续约 Fiber（TTL/2 间隔）；断连回退路径：若续约失败 N 次则让 Master 进入只读（禁止再响应 stopAll/reload 直至重新获得 lease）
5. 新增 `tests/Unit/Supervisor/WriteLeaseContractTest.php`：claim → renew → release 的状态机 + 过期后 re-claim 场景 ≥ 10 个 case
6. E2E：单机双 Master 模拟（故意抢同一 instance_name 的 write lease），验证只有一个能真正执行 reload
7. 文档：`WLS-HA-IPC-REDESIGN-2026-04-15.md` 对应章节从"Planned"改为"Shipped (Phase 2)"

---

## 5. Phase 1 开工前仍需老板回答的悬而未决问题（≤ 5 条）

1. **write lease 的 TTL 默认值**？建议 `15s`（与 Master heartbeat / Dispatcher pool snapshot 同一数量级），但 Windows/Linux 是否要区分（Windows 文件系统抖动可能更长）？
2. **Master 丢失 write lease 后的降级策略**？选项：
   - (a) 立即进入维护模式，拒绝所有变更操作直至 re-claim；
   - (b) 只阻塞 `reload/stopAll/scale`，保持读路径与现有 Worker 池服务；
   - (c) 继续服务但把变更请求入队缓冲，重获 lease 后回放。
   个人倾向 **(b)**，但需要确认是否接受"半分区"期间出现 reload 请求排队等待。
3. **跨机部署场景**是否纳入 Phase 1？当前文档假设"Supervisor 与 Master 同机"，跨机需要 TCP（而非 Unix socket）+ 独立认证通道；本 Phase 是否**显式排除**跨机？
4. **fencing token 是否下发给 Worker**？Phase 1 可以只给 Master（Master 自己持有 lease）；如果要给 Worker（防止旧 Worker 继续消费陈旧指令），需要把 generation 贴进每个 `pool_snapshot`——这个决定影响 §2.1 的 `SlotLease` 协议是否需要在 Phase 1 就扩字段。
5. **与 `allow_child_resurrection` 的交互是否写进 env.php**？建议 Phase 2 在 env.php 新增
   `wls.supervisor.write_lease.enabled` 开关（默认 `false` 用于灰度），Phase 4 之后翻转默认值。Phase 1 是否就先加注释占位的 config key？

---

## 6. 推迟事项（不属于 Phase 1 交付）

- **Phase 2**：Supervisor 骨架代码（LeaseRegistry 双表 + 单条写路径收口）
- **Phase 3**：跨机 lease + fencing（TCP / mTLS / token 派发）
- **Phase 4**：控制面完全收口（Master 不再接受直接写，全部经过 Supervisor）
- **独立专项**：WLS 观测性全量仪表盘（`server:status` 展示 MetricsRegistry 跨进程汇聚；本轮 P2 观测性专项已落地 `Observability/` 基础设施，此处继承其采样数据）

---

## 验收标准对照（来自 task.md）

- [x] 本蓝图文档已产出 — 现状审阅 + Phase 1 交付边界 + 共存矩阵 + ≤5 项悬而未决问题 + 推迟事项
- [x] Phase 2 开工 checklist 已可直接复制为下一任务的 task.md（见 §4）
- [ ] 老板签收 — 交付后待老板批注
