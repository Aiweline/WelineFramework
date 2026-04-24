# WLS Master 自愈默认开启 HA 设计稿 (2026-04-23)

> 关联任务：`dev/ai/codex/tasks/2026-04-23/2026-04-23-2330-wls-master-self-heal-default-on`
> 关联宏观蓝图：`WLS-HA-IPC-REDESIGN-2026-04-15.md`（长期重构方向，本设计稿**不冲突、不替代**）
> 状态：v2 — 按老板反馈"跨项目/多实例要支持"更新了 fencing 论证

## 0. 版本说明

- v1（2026-04-23 初稿）：假设跨主机多副本部署不支持，把 fencing 语义限制在"单机"。
- **v2（2026-04-23 修订）**：按实际部署需求，重新论证：
  - 跨主机部署相同 `instance_name`（多活水平扩展）→ **支持**：各节点 Master 独立管理本机进程，互不干涉，无需跨机 fencing。
  - 同机多 `instance_name` → **支持**：本地锁按实例名隔离。
  - 跨项目（同机不同 workspace）→ **支持**：每个项目独立 `BP`、独立 `var/server/instances` 目录、独立锁。
  - 默认值反转对以上三种部署**全部安全**。

## 1. 目标与非目标

### 1.1 目标

- 论证把 `wls.orchestrator.allow_child_resurrection` 的默认值从 `false` 反转为 `true` 是否满足 HA 安全底线。
- 输出默认值反转前必须闭环的**最小**监测与护栏清单。
- 给出灰度/回滚方案，让用户可分级接入。

### 1.2 非目标

- 不引入 leader 选举 / 多副本 Master（留给宏观 HA 蓝图）。
- 不改 IPC 协议、不加 `lease_id / generation`（同上）。
- 不做 P2 观测性面板（独立维护窗口）。

## 2. 系统现状（reference: `Weline\Server\IPC\MasterResurrector`）

### 2.1 触发条件

子进程控制会话（到 Master 控制端口的 TCP 连接）异常断开、未收到 `shutdown` 命令时，由 `ControlClient` 的重连路径触发 `MasterResurrector::attemptResurrect()`。

### 2.2 防并发：文件锁 + 端口探测双保险

1. `flock(LOCK_EX|LOCK_NB)` 独占锁 `var/server/instances/<instance>.resurrect.lock`：同一机器内只允许一个子进程执行复活逻辑。
2. 复活循环内每一轮 `startMaster()` 前后，都 `isMasterAlive()` 通过 `stream_socket_client("tcp://127.0.0.1:<control_port>", 1s)` 探测控制端口是否可连——若有人已经复活，当前进程让步。
3. 真正启动 Master 走 `php bin/w server:start <instance> --master-only`，子进程会再走一遍"已存在 Master → 退出"的幂等逻辑。

### 2.3 优先级与退避

| 角色 | `priority` 常量 | 复活初始延迟 | 说明 |
| --- | --- | --- | --- |
| HTTP Redirect Worker | `RESURRECTION_REDIRECT` | 1 s | 最快出手 |
| Dispatcher | `RESURRECTION_DISPATCHER` | 3 s | 次选 |
| Worker #1 | `RESURRECTION_WORKER` | 6 s | 兜底 |
| Worker #2+ / Session / Memory / Maintenance | `RESURRECTION_NONE` (0) | — | 不参与，只等重连 |

重试循环：`attempt=3`，失败时延迟 `min(prev * 2, 30s)`；三轮失败后落"服务异常"标志并派发事件，停止自动恢复。

### 2.4 权限门禁

`canResurrectWithCurrentPrivilege()` 判断：

- Windows 永远允许（无 setuid 模型）。
- Linux/macOS 下若实例使用特权端口（<1024）且进程非 root：
  - 先尝试 `stream_socket_server("tcp://0.0.0.0:<port>", STREAM_SERVER_BIND)` 探测 `cap_net_bind_service`。
  - 无能力则拒绝复活，防止"部分请求能通过、部分请求绑 80 失败"的链路污染。

### 2.5 失败观测

- `MasterProcess::setServiceException(<instance>)` 落盘 `.exception` 标志文件。
- 派发事件 `Weline_Server::service::master_resurrection_failed`。
- 已注册观察者 `Weline\Server\Observer\MasterResurrectionFailedObserver` 上报后台消息中心。

## 3. 威胁模型

把"可能触发子进程认为 Master 已死"的事件，按是否应当复活、是否能正确处理，分类如下。

### 3.1 威胁分类表

| # | 触发事件 | 当前分类 | 期望行为 | 现有护栏是否足够 |
| --- | --- | --- | --- | --- |
| T1 | Master 进程崩溃（OOM / segfault / fatal error） | 真故障 | 复活 | ✅ flock + 端口探测 + 优先级错峰 |
| T2 | Master 主动 `server:stop` 广播 shutdown | 正常 | 不复活 | ✅ `receivedShutdown=true` 短路 |
| T3 | Master 热重启中（短暂无监听） | 正常 | 不复活 | ✅ 延迟 1–6 s 期间 `isMasterAlive()` 会命中新 Master |
| T4 | Master 控制 socket 短暂阻塞（TCP 缓冲爆满） | 误报 | 不复活 | ⚠️ **Gap 1**：子进程断开仅靠 TCP 层感知，可能在重 GC 窗口误判。需要心跳保护。 |
| T5 | 子进程自身网络栈异常（本地 loopback 故障） | 误报 | 不复活 | ⚠️ **Gap 2**：当前没有"是否其他子进程也失联"的横向验证，本进程可能孤立决策。 |
| T6 | 机器内核 OOM killer 误杀 Master | 真故障 | 复活 | ✅ 与 T1 同链路 |
| T7 | 多个子进程"同时"判定 Master 已死 | 正常 | 只允许一个成功启动 | ✅ flock + `isMasterAlive()` 双保险 |
| T8 | 旧 Master 仍存活但控制端口阻塞，子进程尝试启动新 Master | 裂脑候选 | 新进程启动失败 → 退化为"复活成功"（`isMasterAlive()`true） | ✅ bind 冲突自然阻断，再次探测为 alive 后返回成功 |
| T9 | 非 root 用户 + 特权端口 | 不可恢复 | 不复活，等人工 | ✅ `canResurrectWithCurrentPrivilege()` |
| T10 | 三次复活失败（依赖外部：磁盘、配置、PHP 扩展） | 人工介入 | 停止自愈，发消息 | ✅ service_exception + 事件 |
| T11 | `server:stop` 正在进行、同时 Master 崩溃 | 冗余 | 即便复活也会被后续 stop 吞掉 | ✅ stop 会再次广播 shutdown |
| T12 | 跨主机多副本同名实例（水平扩展） | 合法多活部署 | 每节点各自管自己，互不干涉 | ✅ 本机自愈作用域仅限本机；跨机共享资源（session/cache/db）独立部署 |
| T13 | 跨项目（同机不同 workspace 跑 WLS） | 合法并列部署 | 各项目互不干涉 | ✅ `BP` 隔离 `var/server/instances` 目录与锁 |

### 3.2 关键结论

- T1、T6、T7、T8、T9、T10、T11、T12、T13 的现有护栏可以覆盖。
- **T4、T5 是默认值反转前的两个最小 Gap**：不处理会导致在 master 健康的情况下被误判为 dead。
- T12/T13 并非"裂脑"而是合法的水平扩展部署；本层 Master 自愈的作用域本就限制在"本机本项目本实例"，不存在跨节点冲突。

## 4. 裂脑 / Fencing / Leader 语义

### 4.1 WLS 的部署约束

WLS 当前定位：

- 一台主机一个实例名 → 一个 Master → 一套控制端口/实例目录。
- 同机多实例靠不同 `instance_name` 隔离，各自有独立目录：
  - `var/server/instances/<instance>.json`
  - `var/server/instances/<instance>.resurrect.lock`
  - 独立控制端口
- **跨主机运行相同实例名属于非法部署**（等同于两个 Master 共用配置），本设计稿不保证其行为。

### 4.2 单机场景的 Fencing 论证

目标：保证在"Master 真的死了"的情况下**只有一个新 Master 被启动**。

Fence 手段：

1. **文件锁唯一性**：
   - `flock(LOCK_EX|LOCK_NB)` 在单机 Linux（POSIX fcntl 语义）和 Windows（LockFileEx）下都保证互斥。
   - 锁文件按 `instance_name` 命名，天然隔离同机多实例。
2. **控制端口 rendezvous**：
   - `isMasterAlive()` 对固定控制端口做 TCP 握手 → 已被监听（不论是当前 attempter 还是别人） → 本 attempter 让步。
   - Master 启动流程本身会做端口绑定 → 两个 Master 同时启动只有一个能 bind 成功。
3. **启动命令幂等性**：
   - `server:start --master-only` 启动前会检查 `instance` 运行状态（`MasterProcess::isInstanceRunning`），已存在则直接退出。
   - 即使 attempter 在 flock 释放后立刻再次触发，第二次也会被 `isMasterAlive()` 拒绝。

**结论**：单机场景下，flock + 端口探测 + start 命令幂等 = 充分的 fencing。无需 leader 选举。

### 4.3 同机多实例场景

- 实例之间 `instance_name` 必须唯一，否则属于配置错误（不是 HA 问题）。
- 各自的 `resurrect.lock` 路径不同 → 互不影响。
- 各自的控制端口不同 → 探测不冲突。

**结论**：同机多实例场景继承单机结论，默认开启安全。

### 4.4 跨主机多副本场景（合法多活，水平扩展）

用户在 A、B 两台机器跑同一个 `instance_name`（共享下游数据库 / Redis / 对象存储）：

- 每台机器有**各自独立的 Master + Dispatcher + Workers**。
- 各台机器的 `resurrect.lock` 在本地文件系统 → 本机互斥仍然成立。
- 各台机器的控制端口在本机 loopback → 各自独立。
- 跨机共享的是**下游应用状态**（数据库、缓存、会话），这一层本来就由数据库/Redis 自己负责一致性；WLS Master 对它们是**消费者**，不是它们的 leader。

**结论**：跨机多活部署对"Master 自愈默认开启"是**完全安全**的：

1. A 机 Master 挂了 → A 机的子进程复活 A 机 Master。
2. B 机 Master 挂了 → B 机的子进程复活 B 机 Master。
3. 两者之间没有共享控制端口、没有共享锁、没有共享端口冲突。
4. 下游应用状态一致性由 DB / Redis / 外部 lock manager 保证（例如 cron 抢 DB 行锁、session 走 Redis），不是本层职责。

**部署侧建议**（写入文档）：

- 跨机部署时，确保每台机器的 `env.php` 都启用 `allow_child_resurrection=true`（默认即 true）。
- 机器级故障（整机宕机、网络隔离）的检测与切换由上层做（负载均衡器健康检查、k8s liveness probe、keepalived VIP 漂移等），WLS 本身不做。
- 跨机"谁是 leader"类业务需求（如只能一个节点跑某个 cron）继续由应用层抢 DB/Redis 锁实现，与本层无关。

### 4.5 跨项目（同机多 workspace）

同一台机器上跑多个 WelineFramework 项目（不同 `BP`、不同代码库、不同实例名）：

- 每个项目的 `var/server/instances/` 目录独立 → 锁文件路径不同。
- 每个项目有自己的 `instance_name` → 控制端口各异。
- `MasterResurrector::startMaster()` 内用 `BP . 'bin' . DS . 'w'` → 永远指向当前项目的 CLI。

**结论**：跨项目并列部署继承同机多实例的结论，默认开启安全。

### 4.6 Leader 语义

在"allow_child_resurrection=true"模式下：

- **作用域**：每次 fencing/leader 仲裁的边界都是"同一台机器、同一个项目、同一个 instance_name"。
- **没有显式 leader**：flock 持有者即为本轮复活的唯一 actor，作用域仅限于本次事件。
- **角色优先级**起到"谁更靠近用户、谁更先出手"的效果，但不是选举。
- 任何 worker/dispatcher/redirect 都可能成为本轮复活者，Master 启动后统一通过控制端口汇合。
- 跨机/跨项目"leader"是**业务层抽象**，由 DB/Redis/外部协调器提供，不在本设计覆盖范围内。

## 5. 默认值反转前必须闭环的 Gap

以下按"是否阻塞默认值反转"分级。

### 5.1 [阻塞] Gap 1：心跳/静默期保护（对应 T4）

**问题**：现有 `MasterResurrector` 类已存在但**未接线**；接线后需防止 Master 正在做耗时操作（大规模 reload、cache warmup）时被误判为死亡。

**方案**（本次已实施）：

- 保留 `shouldResurrect()` 为纯判断函数（便于单测）。
- 新增 `confirmAfterGrace(): bool`：在 `resurrect_grace_seconds`（默认 2s）窗内以 200 ms 为步长轮询 `isMasterAlive()`；任何一次探测成功 → 返回 false（让步）。
- 协调器编排：`shouldResurrect && confirmAfterGrace → attemptResurrect`。

### 5.2 [阻塞] Gap 2：横向验证（对应 T5）

**问题**：子进程孤立决策"Master 已死"，可能仅本进程网络栈出错。

**补强方案**：

- 复活前先问一声兄弟进程：通过 `var/server/instances/<instance>.runtime.json` 读取其他活跃子进程的 PID，`posix_kill($pid, 0)` 探测活跃数 ≥ 2 时，要求至少**两个**子进程都看到 Master 断线（通过 flock 前的 `resurrect.ballot` 文件累计计数）才继续。
- MVP：不实现 ballot，**先仅依赖 Gap 1 的等待窗口**，通过两次 `isMasterAlive()` 来横向"自证"：等待 `RESURRECT_GRACE_SEC` 后，如果控制端口仍不可连，则大概率不是本机网络栈问题。
- 长期：合入宏观 HA 蓝图里的 `FailureDetector`。

**决策**：MVP 用 Gap 1 的等待窗口兜底，不引入 ballot 文件（避免新的跨进程 IO 复杂度）。若 QA 环节出现误判则再补。

### 5.3 [非阻塞] Gap 3：`setServiceException` 签名与调用不一致（技术债）

- 当前 `MasterResurrector::attemptResurrect()` 用 3 个参数调用，而 `MasterProcess::setServiceException` 签名是 2 个参数。
- PHP 会丢掉多余参数，不报错，但 reason 会拼不上 `maxRetries`。
- **决策**：本专项随手修掉（一行改动），不列为阻塞项。

### 5.4 [非阻塞] Gap 4：CLI `server:status` 显示当前自愈模式

- 让运维可以一眼看到 `self_heal: on/off`，便于灰度过程确认。
- **决策**：放入下一轮实施 PR，不阻塞本专项设计稿评审。

### 5.5 [非阻塞] Gap 5：`MasterResurrector` 目前对"成功复活"没有 metrics 埋点

- 可接入 `Weline\Server\Observability`（若存在），或走 `w_log_info`。
- **决策**：与 P2 观测性一并做。

## 6. 灰度方案

### 6.1 切换路径

```
Phase A（当前）    allow_child_resurrection = false（默认），需用户手动开启
Phase B（PR 1）    修复 Gap 1 + Gap 3；默认值保持 false；文档指引用户按需开启
Phase C（PR 2）    一个发布周期稳定后，默认值翻转为 true；env.php 中显式 false 可禁用
Phase D（未来）    合入宏观 HA 蓝图后，本开关隐退
```

### 6.2 配置示例（保持兼容）

```php
// app/etc/env.php
return [
    'wls' => [
        'orchestrator' => [
            // Phase B 之后用户可显式开启
            'allow_child_resurrection' => true,
            // Gap 1 补强：允许覆盖默认静默期
            'resurrect_grace_seconds' => 2,
        ],
    ],
];
```

### 6.3 回滚

- 任何阶段出现误判导致的"Master 反复被拉起"：用户在 `env.php` 显式 `allow_child_resurrection = false`，不需重启即可在下次事件时生效（`MasterResurrector::isChildResurrectionEnabled()` 每次现读）。
- 若发现 Gap 1 的 grace 不够，用户可调大 `resurrect_grace_seconds`，同样现读。

## 7. 验收 checklist（默认值反转 PR 的 Acceptance）

> 下一轮实施 PR 必须逐项打勾才能合入。

- [ ] 代码：`MasterResurrector::shouldResurrect()` 在返回 true 前执行 grace 等待，等待期间若 `isMasterAlive()` 恢复则放弃。
- [ ] 代码：修复 `MasterProcess::setServiceException()` 调用参数不匹配（Gap 3）。
- [ ] 代码：默认值从 `false` 改为 `true`；`env.php` 显式 false 仍可关闭。
- [ ] 单测：新增 `MasterResurrectorGraceTest`，覆盖：
  - grace 期内 master 复活 → 不再启动。
  - grace 期内 master 一直失联 → 继续原流程。
  - grace 配置可被 env.php 覆盖。
- [ ] 单测：已有 `MasterResurrector` 相关测试全部通过（不被 grace 改坏语义）。
- [ ] 集成测（手工）：
  - 杀掉 Master → 5–10 s 内 Worker#1 或 Dispatcher 复活 Master，业务无 5xx。
  - `server:stop` 过程中 Master 崩溃 → stop 完成后仍为停止状态，不会被复活。
  - Linux 非 root 绑 80 → 复活拒绝，observer 收到异常告警。
- [ ] 文档：`README` / `doc/WLS-FEATURES.md`（若存在）注明默认开启 + 回滚方式 + 跨主机不支持。
- [ ] 文档：本设计稿评审意见回填到 result.md。

## 8. 风险登记

| 风险 | 等级 | 缓解 |
| --- | --- | --- |
| 误判导致 master 频繁被拉起 | 中 | Gap 1 grace 等待 + `service_exception` 三次硬止损 |
| 复活时新 Master 绑端口失败，锁被占 | 低 | `finally` 必然释放 flock；`startMaster` 失败下一轮 retry |
| 跨机节点对"自身 Master 挂了"反应不一致 | 低 | 每节点的 resurrector 独立工作；负载均衡器 health check 兜底整机失效 |
| 非 root + 特权端口用户被反复告警 | 低 | `canResurrectWithCurrentPrivilege` 直接返回 false，不走锁 |

## 9. 公开决策纪要

- **本专项 v2**：同一轮交付设计稿 + 实施（接线、Gap 1 grace、默认值反转、观测性）。
- **Gap 1（grace 等待）**：`MasterResurrector::confirmAfterGrace()` 独立方法，单测友好。
- **Gap 3（setServiceException 签名）**：已修复。
- **支持的部署形态**：
  1. 单机单实例（原始场景）
  2. 同机多实例（不同 `instance_name`）
  3. 跨项目（同机不同 `BP`）
  4. 跨主机多活（不同机器同 `instance_name`，DB/Redis 层做应用一致性）
- **不承诺的部署形态**：
  - "同一实例名的 Master 要在跨机范围内只有一个存活"——这是业务层 HA（主备切换），需要外部编排器（k8s/keepalived/etcd），不是本层职责。
- **宏观 HA 蓝图（Supervisor + lease）保留**，不因本专项而冻结或加速；两者并行推进。
