# changanhanfu.com WLS 稳定性诊断与修复增强方案

- 诊断时间：2026-10-02 01:00–01:15 CST
- 目标机：`ssh changanhanfu` → 199.102.216.204（Ubuntu 24.04 / kernel 6.8 / 2 vCPU / 3.5 GiB RAM / 无 swap）
- 站点根：`/www/wwwroot/changanhanfu.com`（运行用户 `weline`）
- WLS 版本：本仓 `dev` 分支，HEAD `084722273`，工作区 691 个未提交改动
- 本次仅做**只读取证**，未修改服务器与本仓任何文件

---

## 一、结论摘要

WLS "不稳定" 不是单点故障，而是**四条缺陷链叠加**：

1. **DB 连接池饥饿**：单进程池上限 10，并发 Fiber 默认 12，池满后每请求最长等待 **30 秒**；30 秒超时还是从 150ms 被改成 30s 的。
2. **Worker 内存耗尽**：512M 上限反复被打爆，worker 反复崩溃；当前 worker#2 已 494M/512M，处于随时再崩状态。
3. **进程监管失效**：bundled PHP **没有编译 FFI**，Linux 下 pidfd 终止路径整体不可用，强杀拿不到退出证明 → 复活屏障 3 次耗尽 → 升级为**整服重启**（worker 属 critical role）。
4. **健康检查形同虚设**：三个探针里唯一通过的是 `http://127.0.0.1/` 的 308（nginx 重定向，根本没过 WLS），所以 45 秒超时、502 刷屏时健康检查依然判定"健康"，**永不触发自愈**。

用户可见影响（实测，非推断）：

| 指标 | 实测值 |
|---|---|
| 首页 HTTPS 实测 | **502，耗时 45.8 / 47.2 / 48.1 秒** |
| 单请求最坏 `router_start_ms` | **190 302 ms（190 秒）** |
| 超过 10 秒的请求占比 | 09-27 **28.3%**、09-28 **51.0%**、09-29 41.7%、10-01 **33.2%** |
| nginx `upstream prematurely closed connection` | 1 086+ 条，且每小时 244→426 递增 |
| nginx `connect() failed (111: Connection refused)` | 632 条 |
| Worker 崩溃（crash-*.log fatal） | 63 次 / 5 天 |
| DB 连接池耗尽异常 | Oct-1 单日 28 次 |

---

## 二、根因链

```
fiber.max_active=12（env.php 无 wls 段 → 默认值）
        │
        ├─ 每 worker 最多 12 个并发请求
        │
pool_size=10（master 未配置 → ConfigProvider 默认 10）
        │
        └─ 12 > 10 ⇒ 至少 2 个 Fiber 必然排队
                    ↓
   ConnectionPool::DEFAULT_ACQUIRE_TIMEOUT_SECONDS = 30.0
   （commit d9fa5427ea 从 0.15 改成 30.0，架构文档仍写 150ms）
                    ↓
   每请求最长干等 30 000 ms（1ms 切片自旋），随后抛
   ConnectionPoolExhaustedException
                    ↓
   页面里多处取连接（如 theme_layout 查询）叠加
   ⇒ 30s × N + 4~23s 正常 SSR = 34~53s（实测完全吻合）
                    ↓
   FPC 构建锁 LOCK_TTL_SECONDS=15 < 30s 的构建耗时
   ⇒ 锁在构建中途过期，第二个 worker 拿到锁重复全量 SSR
   ⇒ wait_miss 惊群：拿不到锁→等待→穿透→整页渲染，且
      publishResponse() 要求持有锁 ⇒ wait_miss 请求永不回填缓存
                    ↓
   nginx proxy_read_timeout 先到 ⇒ upstream prematurely closed
   ⇒ 502 / 503
                    ↓
   重负载 + 3.5G 无 swap ⇒ worker 触顶 512M ⇒ fatal 崩溃
                    ↓
   Master 尝试复活，但 FFI 缺失 ⇒ 无退出证明
   ⇒ 老进程/端口未释放 ⇒ fence 3 次耗尽（固定 1s，无退避）
   ⇒ worker 属 critical role ⇒ requestFullRestart（10s 冷却）
   ⇒ 整服重启 ⇒ 更多 502
```

---

## 三、缺陷清单

> 证据格式：`线上路径` 或 `仓内文件:行号`

### D1【致命】DB 连接池饥饿，单请求最长 30 秒等待

| 项 | 值 | 证据 |
|---|---|---|
| 池上限 | 10 | `app/code/Weline/Framework/Database/DbManager/ConfigProvider.php:345` `return (int)($this->getData('pool_size') ?? 10);` |
| 生产是否覆盖 | **否**（master 段无 `pool_size`，`env.php` 仅 sandbox_db 有 5） | 线上 `app/etc/env.php` |
| 获取超时 | **30.0 秒** | `ConnectionPool.php:23` `DEFAULT_ACQUIRE_TIMEOUT_SECONDS = 30.0` |
| 历史值 | 0.15 秒 | `git show d9fa5427ea` diff：`-0.15` → `+30.0` |
| 等待方式 | 1ms 切片轮询 | `ConnectionPool.php:24` `POOL_FULL_WAIT_SLICE_US = 1_000` |

**线上直接证据**：

```
[WlsRuntime] Request error: 系统错误：Database connection pool exhausted after 30000ms
  (max_size=10, current_size=10, in_use=10, owners=10, leases=95)
```
（`var/log/wls/default/error-2026-10-01.log`，单日 28 条）

**两个加重项**（必须一起看）：

- **`leases=95` 对 `owners=10`**：单个 owner（请求/Fiber）叠加了极深的**逻辑租约**，意味着一个请求会重复取连接 9~10 次。一个请求就能吃掉整个池。
- **`fiber.max_active` 未配置 → 默认 12**（`app/code/Weline/Server/bin/worker_runtime_common.php:338-341`）。生产 `env.php` **完全没有 `wls` / `performance` 段**（`grep -c` 结果为 0），所以并发 12 > 池 10 是**配置上的必然**。

**文档与实现已脱节**：`app/code/Weline/Framework/doc/architecture/04-performance-budget.md:89` 仍写"默认获取等待预算仍为 `150ms`"，而代码是 30 000 ms，且有测试锁死新行为（`Test/Unit/Database/Connection/Pool/ConnectionPoolExhaustionTest.php:66-73` 断言 `>= 30.0`）。

**修复方案**

1. **（止血，最优先）** 把获取超时降回有界值，例如 1.5~3 秒，或按"页面渲染预算"分级；30 秒的 HTTP 同步等待在 2 vCPU 机器上没有可用性意义。
   - 位置：`ConnectionPool.php:23`，建议改为可配置 `db.pool.acquire_timeout_ms`，默认 ≤ 3000。
   - 同步修正 `04-performance-budget.md`，并改掉那个断言 30.0 的测试（它把一次性能事故固化成了规格）。
2. **消除 12 > 10 的结构性矛盾**：显式配置，保证 `pool_size ≥ fiber.max_active`。在 2 vCPU / 3.5G 机器上建议 `max_active=6`、`pool_size=8`（或反过来按实测调）。
   - 在 `env.php` 增加 `wls.fiber.max_active`；或给 master 显式写 `pool_size`。
   - **长期**：在启动时校验 `pool_size >= max_active`，不满足直接启动失败（fail fast），而不是运行期互相饿死。
3. **查清 `leases=95`**：加一次性诊断，打印单个 owner 的租约获取栈，定位是哪个服务在循环取连接。这是"一个请求吃光池"的元凶，不修则调大池也只是推迟崩溃。
4. **降低池满等待的 CPU 代价**：`POOL_FULL_WAIT_SLICE_US=1000` 在 30 秒预算是 3 万次自旋；配合 Fiber 无法安全挂起时会退化成**阻塞 `usleep`** 冻结整个 worker（见 `SchedulerSystem.php:596-614`）。建议改为指数退避（1ms→2→4…→100ms 封顶）。

---

### D2【致命】Worker 内存耗尽反复崩溃

**线上直接证据**（`var/log/wls/default/crash-*.log`，5 天 63 条 fatal，全部为 `Allowed memory size of 536870912 bytes exhausted`）：

| 崩溃热点 | 次数 |
|---|---|
| `Weline/Server/Session/Server/SessionProtocol.php:185` | 15+ |
| `Framework/Database/Connection/Api/Sql/QueryAst.php:922 / 1207` | 22+ |
| `generated/framework/query_providers.php:6` | 10 |
| `Weline/I18n/Api/Localization/GlobalDictionaryProvider.php:114` | 4 |
| `Framework/Service/Query/QueryProviderRegistry.php:191`、`Eav/Service/AttributeMetadataCatalog.php`、`Framework/Runtime/SchedulerSystem.php:631` 等 | 各 1~3 |

**当前实时状态**：worker#2 RSS **494 612 KB / 512M 上限**（96%），worker#1 243 904 KB。

**并发放大**：`[WlsRuntime] WLS output capture exceeded safe memory limits; request output was discarded`（reason=`memory_headroom`，`max_capture_bytes=16777216`）——Oct-1 单日 115 次。输出缓冲达到 16MiB 上限后整份响应被丢弃，直接对应 `[UnexpectedEmptyResponse] status=200 body_len=0`（175 次）——**对爬虫和用户都是白页 200**。

**另一个独立缺陷**：bundled PHP 默认 `memory_limit => 128M`，而 CLI 任务（cron）未叠加 `-d memory_limit`，于是产生大量 `generated/language/ar_SA.php` 在 **134 217 728 字节（128M）** 处爆掉的 fatal（`var/log/crash.log` 中 09-28 至 09-29 高频，每小时一次）。

**根本原因（非工具问题）**：`query_providers.php`、`GlobalDictionaryProvider`、`QueryProviderRegistry`、`AttributeMetadataCatalog`、`SessionProtocol` 都是**整表/整字典加载进内存**的模式。512M 只是掩盖，不是修复。

**修复方案**

1. 短期：`--worker-memory-limit` 提到 768M **并同时**给机器加 2~4G swap（当前 `Swap: 0`，一旦触顶就是硬崩）。注意这是掩盖，必须配套第 2 条。
2. 治本：逐个处理热点。
   - `query_providers.php` / `QueryProviderRegistry`：改按需 `require`。
   - `GlobalDictionaryProvider`：改按语言/命名空间分片加载 + 缓存。
   - `AttributeMetadataCatalog`、`Eav`：改游标迭代（`fetchIterator`）而非全量 `fetchAll`。
   - `SessionProtocol.php:185`：审查会话消息体上限——15 次崩溃说明存在超大会话帧，需要硬性帧长上限与背压。
3. `output capture` 16MiB 上限命中时**不应静默产出 200 白页**，应返回 503 并计指标；同时把 `UnexpectedEmptyResponse` 与它关联起来（当前是两个独立错误）。
4. 给所有 CLI/cron 入口统一加 `-d memory_limit=`（不要依赖 bundled php.ini 的 128M）。
5. 加 worker 内存水位告警（如 RSS > 80% 告警、> 90% 主动优雅重启该 worker），把"崩溃恢复"变成"计划内替换"。

---

### D3【严重】bundled PHP 缺 FFI → 进程终止拿不到退出证明 → 复活屏障耗尽 → 整服重启

**线上证据**：

```
bundled PHP: PHP 8.4.20 (cli) (NTS)
configure: ... --with-zlib --with-xsl --enable-exif ...     ← 无 --enable-ffi
php -m: bcmath Core ctype curl ... pcntl ... posix ...      ← 无 ffi
```

`var/log/wls/default/error-2026-10-01.log`：

| 错误 | Oct-1 次数 |
|---|---|
| `[Orchestrator] worker#N 强制终止未获退出证明：owner_state=unknown, reason=linux_pidfd_ffi_unavailable` | **345** |
| `[Orchestrator] worker#N 复活前置 fence 已耗尽 (old_process_or_port_not_released, attempts=3)` | **183** |
| `[Orchestrator]` ERROR 合计 | 3 281 |

**代码路径（仓内，已逐行确认）**：

- `ServiceOrchestrator.php:9387` → `MasterLeaseRuntimeIdentity::terminateExactProcessIdentity()` → `:328 match(PHP_OS_FAMILY)` → `:451 terminateLinuxProcessIdentity()` → **`:457 if (!\extension_loaded('FFI') || !\class_exists(\FFI::class))`** → `:462` 返回 `linux_pidfd_ffi_unavailable`。
- 依赖 `FFI::cdef` 绑定 libc 后调用裸系统调用 434（`pidfd_open`）/ 424（`pidfd_send_signal`）+ `poll`（`:477-488`、`:534-561`）。
- **Linux 没有非 FFI 兜底**：Darwin 有 `posix_kill` 兜底（`:394-447`），Linux 分支没有。
- **设计好的兜底是死代码**：`stableProcessTerminator` 注入点（`:66/:74/:287-326`）在生产**从未被注入**——所有生产构造都是 `new MasterLeaseRuntimeIdentity()` 无参（`ServerLifecycleOperationLock.php:69`、`ProtocolEdgeRuntime.php:626/681/729`、`ServiceOrchestrator.php:5691/9364/9387`、`ManagedNginxProcessManager.php:428/1789`、`Start.php:502/596` 等），只有测试注入。
- **状态机不一致**：`killInstanceProcess()` 返回 `false`（未释放）后，调用方仍无条件推进状态——`:9290` 置 `state = STOPPED`，`:14628-14633` 置 `setProcessTreePids(0,0,0)` + `FAILED` + 隔离。**注册表说"已停"，OS 里进程可能还在 LISTEN**，槽位被判定可重新拉起 → 端口冲突 / 重复代次。
- **fence 无退避**：`max_fence_attempts = 3`（`:32570`），延迟固定 **1.0 秒**（`:7724-7725`，`max(0.05,$delay)`，调用方不传 delay），每次尝试再等端口 **1.5 秒**（`:16255`、`:16277`）⇒ 约 6~8 秒即耗尽。
- **升级为整服重启**：`escalateRecoveryFailureOrQuarantine`（`:14589`）→ `worker`/`dispatcher` 属 critical role（`:14571-14583`）→ `requestFullRestart`（`:14947`），冷却仅 **10 秒**（`:224`）。这就是"总是不稳定"的机制。
- **只杀树根**：fence 终止时 `terminateManagedProcessLease(..., $tree=false)`（`:16218`），`tracking_pid` 取自 `getTrackingPid()` 优先 `rootPid`（`Contract/ServiceInstance.php:175-187`）。根进程死了但子进程仍持有 socket ⇒ 端口永不释放。
- **孤儿清理是 no-op**：`cleanupOrphanChildProcesses()` 无条件 `$killed = 0;`（`:15320`），周期清扫默认关闭（`:228`、`:2347`）。
- **端口释放判定不含属主信息**：Linux 先做 TCP connect 探测（`AbstractProcessDriver.php:190-228`），失败再 `ss -tln ... LISTEN`（`LinuxProcessDriver.php:440-451`），**没有 /proc 扫描、没有 PID / launch_id 归属校验**，无法区分"老进程"与"新一代 SO_REUSEPORT 兄弟进程"。
- **`old_process_or_port_not_released` 是原因混称**：同一个字符串覆盖了四种不同失败（`:16177-16184` UNKNOWN、`:16202-16211` 租约不完整、`:16226-16234` 终止未释放、`:16236` 端口未释放），**取证价值极低**。

**修复方案**

1. **给 bundled PHP 加 `--enable-ffi`**（并确保 `ffi.enable` 不为 off）。这是最直接的一刀：kernel 6.8 早已支持两个系统调用，唯一阻塞就是扩展没编。编译脚本在 `extend/server/php` 的构建配置里。
2. 若短期不能重编 PHP：**在 Linux 上实现非 FFI 兜底**，与 Darwin 对齐——`posix_kill`（`pcntl`/`posix` 已就绪）+ 重新观测出生元组 + `waitpid` 证明。**并给 `stableProcessTerminator` 接上生产实现**（现在只有测试用）。
3. **修正状态机**：`killInstanceProcess()` 返回 `false` 时**禁止**推进 `STOPPED` / 清 PID / 置 `FAILED`；应进入显式的 `UNKNOWN` 态并保留 PID 与端口占用证据，避免"注册表说停、OS 还在跑"。
4. **fence 增加退避与抖动**（1s→2→4→8→…封顶 30s），并把四种失败拆成**四个不同 reason 码**，否则线上无法定位。
5. **终止必须带进程树**：`$tree=true`；且 `tracking_pid` 的选择要与"谁持有 listen socket"一致。
6. **端口释放判定加入属主校验**：按 `launch_id` / 出生元组核对监听者是否为本代次；并把 SO_REUSEPORT 兄弟代次识别为"已释放"。
7. **critical role 升级到整服重启应有熔断**：10 秒冷却 + 每次 worker fence 耗尽就整服重启，在故障持续时会变成重启风暴。建议：单位时间窗内最多 N 次整服重启，超过则进入降级（保留可用 worker、拒绝新任务）而不是继续重启。
8. `cleanupOrphanChildProcesses()` 的 no-op 与默认关闭的周期清扫需要重新评估——它是唯一能回收"非法残留"的路径。

---

### D4【严重】用户可见 502/503 持续发生

**线上证据**（`var/server/nginx-linux-x86_64/logs/error.log`，4.7 MB，09-27 17:42 → 10-02 01:09）：

| 签名 | 次数 |
|---|---|
| `upstream prematurely closed connection while reading response header` | 539 + 529 = **1 068** |
| `connect() failed (111: Connection refused) while connecting to upstream` | 320 + 312 = **632** |
| `upstream timed out (110: Connection timed out)` | 多条 |

按小时统计（10-01 14:00 起）：136 → 155 → 70 → 36 → 62 → 106 → 193 → **327 → 426 → 415** → 244 → 56，**明显恶化趋势**。

**实测复现**（本次诊断期间）：

```
https://www.changanhanfu.com/            → code=502  total=47.17s
https://www.changanhanfu.com/            → code=502  total=45.80s
https://www.changanhanfu.com/            → code=502  total=48.13s
/category/hanfu?af_material=Embroidery   → code=502  total=29.60s
/category/hanfu?...                      → code=503  total=0.017s
```

**修复方案**：D1/D2/D3 修好后此项自然消失。但需独立补两项：

1. **当前 nginx 与 worker 的读超时配置未取证**（`proxy_read_timeout` 等），应显式设定并让 WLS 侧的应用预算**小于** nginx 超时，由应用主动返回 503 而不是让 nginx 报 upstream 关闭。
2. 在 Cloudflare（客户端 IP 均为 CF 段：172.6x/104.2x）侧对 5xx 加告警；当前 502 刷屏却无告警，是监控缺失。

---

### D5【高】FPC `wait_miss` 惊群且永不回填缓存

**线上证据**：`[RouterPerf] route` 中 `"fpc":"wait_miss"` 大量出现，且 `elapsed_ms` 与 `fpc_probe_ms` 不成比例（如 `fpc_probe_ms=36.6` 但 `elapsed_ms=47428`）。

**代码事实**：

- `wait_miss` 语义 = FPC 未命中 **且** 拿不到 per-URI 构建锁 **且** 有界等待也没等到发布（`Router/Core.php:1711-1725`）。
- **`wait_miss` 请求不会回填缓存**：`publishResponse()` 要求 `$fpcBuildLock !== null`（`Core.php:1882-1892`），而 `wait_miss` 恰恰是没拿到锁。⇒ 每个 `wait_miss` 都是**一次不回填的重复整页 SSR**，自我放大。
- 锁 TTL **15 秒**（`FullPageCacheCoordinator.php:47` `LOCK_TTL_SECONDS = 15`），**无心跳续期**（全仓仅 `:270` 一处使用该常量）。构建若卡在 30 秒 DB 等待上，**锁先过期** ⇒ 第二个 worker 拿到锁再起一次重复 SSR。
- 生产 `env.php` **无 `wls.performance.fpc_build_wait_timeout_ms`** ⇒ 取持久化默认 **0 ms**（`FullPageCacheCoordinator.php:330-369`）⇒ 立刻穿透，等于**关闭了 single-flight 保护**。
- 释放点在 `finally`（`Core.php:1919-1923`），但 Fiber 被强制取消时 PHP 不会执行 `finally`（`SchedulerSystem.php:627-638` 只吞 `FiberError`）。

**修复方案**

1. 生产显式设置 `wls.performance.fpc_build_wait_timeout_ms`（如 8000~15000），恢复 single-flight。
2. **锁 TTL 必须大于最坏构建时间**，或引入心跳续期（推荐后者）。
3. **让 `wait_miss` 能回填**：抢到锁的路径才是唯一发布者这一设计本身合理，但应让 `wait_miss` 在拿到"等待到的响应"时返回缓存（现在 `wait_hit` 分支就是这么做的），而不是穿透后重复构建。
4. 加 `fpc=wait_miss` 命中率与惊群度指标。

---

### D6【高】MySQL "server has gone away" 未被 MySQL 路径标记为坏连接

**线上证据**（`var/log/php_error.log`，156 MB）：

| 签名 | 次数 |
|---|---|
| `php_error:.../QueryAst.php:922 - [E_WARNING] PDOStatement::execute(): ...` | **38 917** |
| `PHP Warning: PDOStatement::execute(): SQLSTATE[HY000]: General error: 2006 MySQL server has gone away` | **15 870** |

**时间分布（UTC）**：全部集中在 **30-Sep-2026 22 时（11 912）与 23 时（4 345）**，即 CST 10-01 06:00–08:00，**2 小时爆发**。而 `var/log/mysql/error.log` 中该时段**没有任何 mysqld 重启记录**（该时段日志为空），所以不是服务端重启导致。

**代码事实（这是一个明确的不对称缺陷）**：

- `ConnectionPool::isDisconnectException()` 已经识别 `'server has gone away'`（`ConnectionPool.php:98`）。
- 但 `markConnectionUnhealthy()` 的调用方只有：`TransactionCoordinator.php:567`、**`Adapter/Pgsql/Query.php:1664` 与 `:1690`**、`DataTable/Helper/TransactionManager.php:293/314`。
- **`Adapter/Mysql/` 目录（`Connector.php`、`Query.php`、`Table/`）里完全没有 `markConnectionUnhealthy` / 健康判断**。

⇒ 一条已经死掉的 MySQL 连接会**留在连接池里被反复取出复用**，于是同一条坏连接持续产生 2006。这正好解释了为什么 15 870 条集中在短时段、而不是均匀分布。

另外 `env.php` 中 master 连接 `'persistent' => true`，长活 worker + 持久连接 + 无重连兜底，是同一问题的放大项。

**修复方案**

1. **对称补齐 MySQL 路径**：在 `Adapter/Mysql/Query.php` 的语句执行失败处，用 `ConnectionPool::isDisconnectException()` 判定后调用 `markConnectionUnhealthy($pdo)`（照抄 Pgsql 的实现）。
2. **加一次重试**：仅对断连类错误（2006/2013/2002）做"换连接重试 1 次"，非幂等语句需谨慎或要求显式开启。
3. **取出时校验**：`IDLE_VALIDATE_SECONDS = 30`（`ConnectionPool.php:20`）对 8 小时 `wait_timeout` 偏长，长空闲连接应强制 `SELECT 1` 或直接淘汰。
4. `persistent => true` 在 WLS 常驻进程下收益有限、风险明确，建议评估关闭。
5. 查清 10-01 06:00–08:00 这两小时发生了什么（`var/log/cron.log` 87 MB 可查），当时是否有长事务/大导入导致服务端杀连接。

---

### D7【中】会话被内存服务 LRU 驱逐 17 799 次

**线上证据**（`var/log/wls/shared-memory-*/weline-wls-memory-*.log`）：

```
[MemoryService:25366@...] [INFO] [SessionStore] LRU evicted 1 sessions (reason=memory)
```
**累计 17 799 次**，且仍在实时发生（01:07–01:08 连续多条）。

memory 服务内存上限 256M（启动参数 `--memory-limit=256M`），RSS 实测 212 612 KB。**reason=memory** ⇒ 会话因内存不足被强制驱逐。

**影响**：用户登录态 / 购物车在会话中丢失，对电商站点是直接的业务损失，而且表现为"随机掉登录"，很难被归因到 WLS。

**修复方案**

1. 提高 memory 服务内存上限，或**
2. 给 SessionStore 设置**基于 TTL 的正常过期**而非纯 LRU on memory pressure；调整 `max sessions` 使常态占用低于水位。
3. 会话数据落到 Redis/DB 做二级存储，内存只做热层。
4. 加 `LRU evicted` 速率告警（当前 1.7 万次没有任何告警）。

---

### D8【中】日志无轮转、无界增长

**线上证据**：

| 文件 | 大小 |
|---|---|
| `var/log/wls/timing.log` | **532 MB** |
| `var/log/php_error.log` | 150 MB |
| `var/log/` 合计 | **745 MB** |
| `var/cache/router-fpc-payloads` | **4.0 GB** |
| `var/` 合计 | **5.8 GB** |
| `var/backup/tmp/core-update` | 751 MB（残留） |

`/etc/logrotate.d/` 下**没有任何 Weline / changanhanfu 条目**（只有 `nginx`、`mysql-server`、`rsyslog` 等系统条目）。

磁盘 49G 用 27G（57%），尚有余量，但 `timing.log` 每天约 100+ MB 且**每个请求全量落盘**（含完整模板明细 JSON，单条可达数 KB），属于调试级日志开在生产。

**修复方案**

1. 给 `var/log/**` 加 logrotate（`daily` + `rotate 7` + `compress` + `copytruncate`，因为 worker 常驻持有 fd）。
2. **关掉或采样** `timing.log` 的模板级明细（`[TemplatePerf]` / `[LayoutPerf]` / `[PackagePerf]`），只保留慢请求（如 `total_ms > 1000`）。
3. `php_error.log` 156 MB 主要是 MySQL 2006 的重复刷屏（见 D6），修 D6 后自然收敛；同时给日志加**同签名限流**。
4. 清理 `var/backup/tmp/core-update` 残留，并让 `core:update` 完成后自清理。

---

### D9【中】Gateway Agent 日志风暴 + 凭证 draining 自相矛盾

**线上证据**（`error-2026-10-01.log`）：

| 签名 | Oct-1 次数 |
|---|---|
| `[WlsGatewayAgent] desired-state task revocation could not be confirmed: Only an authenticated gateway Agent may revoke desired-state tasks.` | **1 677** |
| `[WlsGatewayAgent] certificate retirement replay state rejected: Certificate retirement state deadline was exhausted.` | 513 |
| `[Cli:server:gateway:agent] [ERROR] [WlsGatewayAgent]` 合计 | **2 234** |

**代码事实**：

- 该异常抛自 `MasterChildCredentialStore.php:690`，条件为：Agent 自身台账记录匹配、`role === 'gateway_agent'`、但 **`lifecycle_state !== 'active'`（即 `draining`）**。
- `draining` 由 `suspendService`（`:563-564`）写入，触发点包括 `global_drain`（`:8614`，**任何 `server:stop` / 整服重启都会触发**）、`instance_drain`、`dispatcher_stop_drain`。
- `GatewayProvider` 的 `getReloadStrategy()` 是 `graceful` ⇒ `supportsDrain() === true` ⇒ 一定会被置于 draining。
- **矛盾点**：Agent 被标为 draining，但进程仍在运行并继续回收其 desired-state 子任务，于是每秒每个任务刷一条 ERROR。
- **无节流**：`Agent.php:2411` 是无条件 `WlsLogger::error_`；对比同文件 `:1223-1237` 的启动失败是**有 30 秒节流**的。Agent 循环 `TICK_MILLISECONDS = 1000`。

**修复方案**

1. **draining 态下，Agent 的 desired-state 回收应被允许**（保留最小权限），或在 draining 时就停止回收任务，消除自相矛盾。当前是"既不许它做事，又要求它做事"。
2. 给该 ERROR 加节流（复用 `:1223-1237` 的模式）。
3. 审查 `certificate retirement deadline exhausted`：证书退役重放状态机超时，需要放宽 deadline 或幂等化。

---

### D10【中】资源超卖

| 项 | 实测 |
|---|---|
| vCPU | **2** |
| 内存 | 3 532 MB total / **无 swap（Swap: 0）** |
| Load average | **8.11 / 5.37 / 3.62**（2 核） |
| CPU steal | **18.2% st**（`top`） |
| 常驻内存占用 | mysqld 657 MB、worker#2 494 MB、worker#1 243 MB、memory 服务 212 MB、session 服务 43 MB、master 99 MB、gateway-agent 75 MB、nginx×3、runtime-watchdog 67 MB |

**内存账**：2×512M（worker）+ 2×256M（shared）+ dispatcher + master + gateway + watchdog ≈ 2 GB 应用侧，再加 mysqld 657 MB 与系统，**在 3.5 GB 且无 swap 的机器上必然互相挤压**。

另：`top` 显示 1 个 **zombie** 进程；`debug.log` 中反复出现 `检测到僵尸进程: runtime_watchdog#1 / dispatcher#1 / worker#1 / worker#2 已退出但未被回收`。

**修复方案**

1. **加 swap**（2~4 GB）——没有 swap 时任何一次内存尖峰都是硬崩，且 `vm.swappiness` 可调低以保持延迟。
2. 扩容到 4 vCPU / 8 GB，或**减少 worker 数 / 降并发**。当前配置（12 并发 × 2 worker + 全部 shared 服务）超出该机型能力。
3. 修僵尸回收：Master 未 `waitpid` 回收已退出子进程，与 D3 的监管失效同源。
4. 关注 **18.2% CPU steal**——宿主机超卖。若长期如此，应换机型或与供应商确认。

---

### D11【中】200 空响应（对爬虫尤其有害）

**线上证据**：`[UnexpectedEmptyResponse] method=GET uri=/ status=200 ... body_len=0 content_type=text/plain; charset=utf-8 ... router_controller=(空) router_action=(空)`，Oct-1 共 175 次（worker#1 80 + worker#2 95）。

根因为 D2 的 `output capture exceeded safe memory limits`（输出被丢弃）与 D1 的 `ConnectionPoolExhaustedException` 被 `catch (\Throwable)` 吞掉（`Theme/Observer/ControllerFetchFileAfter.php:133-143` 保留旧内容）。

**修复方案**：**禁止**在渲染失败时返回 200 空体。统一改为 503 + `Retry-After`，并在响应头标注原因（如 `X-WLS-Degrade: output-capture`），避免搜索引擎收录大量空白页。

---

### D12【运营风险】生产跑 `dev` 分支 + 691 个未提交改动

**线上证据**：

```
git rev-parse --abbrev-ref HEAD  → dev
HEAD 084722273 fix(seo): GSC 账户表单解析...
git status --short | wc -l      → 691
```

另有诊断期间观察到的 `php bin/w core:update -b dev -f -n`（root → runuser weline）在运行，且 `var/backup/tmp/core-update` 残留 751 MB。早先还有一个 `core:update -b dev -n` 处于 **D（不可中断睡眠）** 状态。

**修复方案**

1. 生产**不应**直接跑 `dev` 分支。至少切到 tag/受控发布分支，并让 `core:update` 不在业务高峰执行。
2. 691 个未提交改动意味着**线上代码与任何版本控制点都不对应**，出问题无法回滚。需要先固化一个基线 commit/tag。
3. `core:update` 加锁，避免并发执行，并清理备份残留。

---

### D13【中】健康检查失效（自愈形同虚设）

**线上证据**：`/var/log/changanhanfu-wls-health.log` **为空**（从未记录过 unhealthy），而同期站点大量 502。

**原因（本次实测复现）**——健康检查 `changanhanfu-wls-health.sh` 依次探测三个 URL，任一成功即判定健康：

```
https://www.changanhanfu.com/   → 000（8s --max-time 超时，实际需 45s）
http://127.0.0.1:9510/          → 000（超时）
http://127.0.0.1/               → 308  ← 命中，判定"健康"
```

`http://127.0.0.1/` 的 308 是 **nginx 的 HTTP→HTTPS 重定向**，**根本没有经过 WLS**。所以只要 nginx 活着，健康检查就永远"健康"，**WLS 全挂也不会触发自愈**。

**其他缺陷**：

- 探针 `--max-time 8`，而真实首页要 45 秒 ⇒ 正常响应也会被判失败（假阴性）；只因 308 兜住才没误报。
- `changanhanfu-wls-autostart.sh` 的自愈动作是 `server:shared:start` + `server:start`，**没有先 `server:stop`**。若 WLS 半死不活（正是 D3 描述的状态），直接 `start` 可能产生双 Master / 端口冲突，反而制造故障。
- 自愈无速率限制与熔断，探测失败即全量拉起。

**修复方案**

1. 健康检查必须探**经过 WLS 的**端点：应校验一个**渲染类**路径（如 `/healthz` 由 WLS 直接返回 `X-Served-By: wls` 头），并**校验该头存在**，而不是只看状态码。移除对 `http://127.0.0.1/` 308 的依赖（或要求同时校验 WLS 侧标记）。
2. 超时按站点真实 p99 设定（如 15s），并区分"超时"与"连接拒绝"。
3. 判定"不健康"后，自愈应先 `server:stop` 再 `start`，并加冷却（如 5 分钟内最多 1 次）与熔断。
4. 健康检查结果应上报监控，而不是只写本地日志（当前日志为空，无人知道它没在工作）。

---

## 四、修复优先级与执行顺序

### 阶段 0：立即止血（今天，< 1 小时，全部可回滚）

| # | 动作 | 对应 |
|---|---|---|
| 0.1 | 加 swap 2~4 GB（当前 0 swap，任何尖峰即硬崩） | D10 |
| 0.2 | 生产 `env.php` 显式配置 `wls.fiber.max_active=6` 与 master `pool_size=8`（消除 12 > 10） | D1 |
| 0.3 | 显式配置 `wls.performance.fpc_build_wait_timeout_ms=8000`（当前 0ms，等于关闭 single-flight） | D5 |
| 0.4 | 给 `var/log/**` 加 logrotate，并手动截断 `timing.log`（532 MB） | D8 |
| 0.5 | 停掉/排期 `core:update`，清理 `var/backup/tmp/core-update` | D12 |

### 阶段 1：修复根因（本周）

| # | 动作 | 对应 |
|---|---|---|
| 1.1 | `ConnectionPool::DEFAULT_ACQUIRE_TIMEOUT_SECONDS` 30.0 → 可配置，默认 ≤3000ms；同步改架构文档与那个断言 30.0 的测试 | D1 |
| 1.2 | 给 bundled PHP 加 `--enable-ffi` 并重编；或先在 Linux 实现 `posix_kill` 兜底并给 `stableProcessTerminator` 接生产实现 | D3 |
| 1.3 | 修正 `killInstanceProcess()` 失败后的状态机：禁止推进 STOPPED / 清 PID / FAILED | D3 |
| 1.4 | fence 加指数退避 + 拆分四种 reason 码；终止改为带进程树（`$tree=true`） | D3 |
| 1.5 | 补齐 `Adapter/Mysql/Query.php` 的 `markConnectionUnhealthy`（对齐 Pgsql） | D6 |
| 1.6 | 健康检查改探 WLS 渲染端点 + 校验 `X-Served-By`；自愈先 stop 再 start + 加冷却 | D13 |
| 1.7 | 修 `leases=95` 的租约叠加（先加诊断定位到具体服务） | D1 |
| 1.8 | 渲染/取连接失败禁止返回 200 空体，改 503 | D11 |

### 阶段 2：加固与治本（本月）

| # | 动作 | 对应 |
|---|---|---|
| 2.1 | 逐个改造内存热点：`query_providers` 按需加载、`GlobalDictionaryProvider` 分片、EAV 游标迭代、`SessionProtocol` 帧长上限 | D2 |
| 2.2 | 统一所有 CLI/cron 的 `memory_limit`（bundled php.ini 默认仅 128M） | D2 |
| 2.3 | Gateway Agent draining 语义修复 + 日志节流 | D9 |
| 2.4 | SessionStore 改 TTL 过期 + 二级存储（Redis/DB） | D7 |
| 2.5 | 生产脱离 `dev` 分支，固化发布基线（当前 691 脏改） | D12 |
| 2.6 | 端口释放判定加属主（launch_id）校验；整服重启加熔断 | D3 |
| 2.7 | 修 Master 僵尸进程回收 | D10 |
| 2.8 | 扩容机型评估（含 18.2% CPU steal 与供应商确认） | D10 |

### 阶段 3：可观测性（与上面并行，防止再次"总是不稳定"却无数据）

1. 指标化：请求 p50/p95/p99、`fpc=wait_miss` 占比、`ConnectionPoolExhaustedException` 计数、worker RSS 水位、worker 重启次数、`LRU evicted` 速率、fence 耗尽次数。
2. 5xx 告警接入（Cloudflare 侧 + WLS 侧）。
3. 健康检查结果上报，而不是只写本地文件。
4. 日志加同签名限流，避免单类错误刷出 1.5 万条。
5. WLS 启动时做**配置自检**（`pool_size >= max_active`、FPC 等待预算 > 0、swap 存在），不满足则启动即失败——比运行期互相饿死好得多。

---

## 五、闭环验证清单

1. `https://www.changanhanfu.com/` 连续 20 次探测，p95 应 < 2 秒，无 502/503。
2. `var/log/wls/timing.log` 中 `total_ms > 10000` 占比应 < 0.1%（当前 28~51%）。
3. `error-2026-*.log` 中 `强制终止未获退出证明` 与 `复活前置 fence 已耗尽` 归零。
4. `Database connection pool exhausted` 归零。
5. `crash-*.log` 连续 7 天无新增 fatal。
6. nginx `upstream prematurely closed connection` 归零。
7. `LRU evicted` 归零或仅剩正常 TTL 过期。
8. 健康检查日志在故意 `server:stop` 后 **5 分钟内**出现一次自愈记录（证明它真的在工作）。
9. `var/log` 单文件不超过 100 MB，磁盘不随时间单调增长。

---

## 六、待确认事项（本次未能取证）

1. **MySQL 2006 的原始触发源**：`var/log/mysql/error.log` 在 30-Sep 22:00 UTC 前后为空，无重启记录。需查 `var/log/cron.log`（87 MB）与当时的慢查询，确认是否有长事务/大导入。
2. **`leases=95` 的调用栈**：需要一次带栈的诊断才能定位是哪个服务在循环取连接。
3. **nginx 的 `proxy_read_timeout` / `proxy_connect_timeout` 实际值**：本次未读取 nginx 站点配置全文。
4. **`core:update` 的两个并发实例**（一个 D 状态、一个 `-f` 强制）是谁在何时发起的；若与 09-30 的 2006 爆发时间相关则需一并评估。
5. **`[MemoryPressure] reason=memory_headroom` 的内存水位阈值**配置来源。
6. 生产 `extend/server/php` 的**编译脚本位置**，以便加入 `--enable-ffi` 后重编。
