# Darwin 进程身份不再依赖 `ps`：托管 Nginx 生命周期可闭环

**状态**：✅ 已完成（status: done）—— D1–D6 全部修复，A1–A12 全绿，实机 E1–E7 全通过
**日期**：2026-09-26
**归属**：`Weline_Server`（消费方）+ `Weline_Framework`（原语）
**触发**：托管 Nginx 默认改公网 80/443 后做实机验证时，`server:start`（`managed=true`）**必然失败**

---

## 一、问题清单

实现「托管 Nginx 默认监听公网 80/443」时，本机（macOS）做实机验证，暴露出一组与端口改动**无关**的既有缺陷。
它们此前一直存在，只是被「本机托管 Nginx 从不真正启动」掩盖了。

| 编号 | 缺陷 | 严重度 | 影响面 | 本计划处置 |
|------|------|--------|--------|-----------|
| **D1** | Darwin 上**取不到进程命令行**（唯一通路是外部 `ps`），导致 `NginxProcessIdentity` 身份门禁必然失败 | 🔴 阻断 | 托管 Nginx 的 start / reload / stop 全部不可用 | ✅ 修复 |
| **D2** | Darwin 上**僵尸进程无法在不调用 `ps` 的前提下分类**，导致 `terminateExactProcessIdentity()` 永不判定「已释放」 | 🔴 阻断 | Master / 托管 Nginx 失败回滚时**杀不掉**已启动进程，留下孤儿 | ✅ 修复 |
| **D3** | `Stop::acquireStopLock()` 新增 `$force` 形参后，两个匿名子类夹具未同步 → 整文件编译期 `FATAL ERROR` | 🟡 测试 | 2 个测试文件全部无法运行 | ✅ 已修（`30fceb9fe`） |
| **D4** | `Processer::getProcessIdByPort()` 只返回 `lsof` **第一条**记录，同端口多监听者时归属错判 | 🟡 已缓解 | 端口归属判定；托管 Nginx 误回退 | 📝 记录，探针侧已绕过 |
| **D5** | ①`Stop::collectIndexedResidualPids()` 是 `private`，两处测试覆写它是**死代码**（守卫永不触发）；②`StopCommandFastLocalCleanupTest::testGracefulStopTrustsAuthoritativeIpcCompletionWithoutLocalPrefixScan` 直接读真实 `var/process/pid/pid_index.json`，**长期假绿**——它的绿只因为 D1 让身份探测恒失败 | 🟡 测试（**D1 修复后才暴露**） | 「热路径不扫 PID 索引」「IPC 成功后不做本地前缀扫描」两条契约实际未被守卫；任何真有同名实例在跑的机器上该用例都会红 | ✅ 修复 |
| **D6** | `NginxChildProcessProbe` 在 Darwin 上仍走「通用 Unix」分支：`pgrep -P` + **`ps -p … -o pid=,ppid=,command=`** ⇒ `workerPids()` / `processIsRunning()` 在 `ps` 不可用时恒 `null` | 🔴 阻断（**D1 修复后才暴露**） | ①`detectEffectiveWorkerCount()` 得 0 ⇒ 托管 Nginx **启动整体失败**；②旧版 Nginx 退役校验抛 `Unable to enumerate the exact legacy Nginx worker generation.`；③证书续期路径同源 | ✅ 修复 |

---

## 二、现象与证据

### 2.1 D1：身份门禁在 `ps` 不可用时必然失败

实测命令：

```bash
$ ps -p $$ -o args=
(eval):1: operation not permitted: ps
```

`Processer::getProcessCommandLine()` 因此返回空串：

```text
self pid=93231
cmdline=[] len=0
info={"pid":93231,"exists":false,"name":"","command":"","memory":"","cpu":"","start_time":""}
isRunningByPid=1
probeProcessState=running
driver=Weline\Framework\System\Process\Driver\LinuxProcessDriver
```

调用链（`ManagedNginxProcessManager` → `NginxProcessIdentity`）：

```text
ManagedNginxProcessManager.php:415   $command = Processer::getProcessCommandLine($pid, true);
ManagedNginxProcessManager.php:416   $this->processIdentity->inspectLaunchCandidate($pid, $command);
        ↓
NginxProcessIdentity.php:216   if ($pid < 1 || \trim($commandLine) === '') {
NginxProcessIdentity.php:217       return $this->failure($pid, 'PID or command line is unavailable.');
```

实机报错（`server:start`，`managed=true`）：

```text
Hosted Nginx startup failed: Managed nginx newly-started process identity is unsafe;
publication evidence was retained before config rollback.
WLS Worker 已 READY，但托管 Nginx 未通过公网协议门禁；将回收本次实例。
```

回归测试里同一个根因以更小的形态复现（`ManagedNginxProcessManagerConfigTestRecoveryTest:463`）：

```text
Failed asserting that 'started nginx PID identity could not be verified:
pid identity mismatch: process command line is unavailable; failed launch did not match
the immutable managed Nginx runtime; no signal was sent: PID or command line is unavailable.'
contains "exact newly launched master was stopped".
```

**根因**：`LinuxProcessDriver::getProcessCommandLine()` 的策略是「`/proc/{pid}/cmdline` → `ps -p -o args=`」。
`/proc` 在 macOS 不存在，于是**唯一通路就是外部 `ps`**。
（`ProcessDriverFactory` 注册表只有 `WindowsProcessDriver` + `LinuxProcessDriver`，
而 `LinuxProcessDriver::supports()` 对所有非 Windows 返回 true ⇒ macOS 落在这个驱动上。）

对比：Linux 上走 `/proc`，**零外部依赖**；Darwin 上却把安全门禁押在一个可被策略禁用的外部二进制上。

### 2.2 D2：僵尸进程在无 `ps` 时被误判为「存活」

实测 `MasterLeaseRuntimeIdentity::terminateExactProcessIdentity()` 对一个真实 `sleep 120` 子进程：

```text
child pid=1249
capture={"birth":"66d54af1...","pid_namespace_id":""}
observe=match
terminate={"released":false,"terminated":true,
           "reason":"darwin_posix_termination_unverified","owner_state":"unknown","pid":1249}
still alive=1        # ← SIGKILL 已发出，进程已死（僵尸），但被判定为「未释放」
```

**关键测量**（`pcntl_fork` 造真实僵尸；三种原生探针 + `posix_kill`）：

| 进程状态 | `proc_pidinfo(pid,3)` | `sysctl KERN_PROCARGS2` | `posix_kill(pid,0)` |
|---|---|---|---|
| 存活 | `read=136`，`pbi_status=2` | `rc=0`，`len>0` | `1` |
| **僵尸** | **`read=0`** | **`rc=-1`** | **`1`** |
| 已回收 | `read=0` | `rc=-1` | **`0`** |

结论：**libproc 读不到「尸体」**；僵尸与已回收在 libproc 下**不可区分**，
唯一判据是 `posix_kill($pid, 0)`（`1` vs `0`）。

`terminateExactProcessIdentity()` 的 Darwin 分支只把 `OWNER_MISSING` / `OWNER_MISMATCH` 当作成功：

```text
MasterLeaseRuntimeIdentity.php:416   if (\in_array($postState, [OWNER_MISSING, OWNER_MISMATCH], true))
MasterLeaseRuntimeIdentity.php:427   （SIGKILL 之后同样）
MasterLeaseRuntimeIdentity.php:444   'darwin_posix_termination_unverified'
```

而 `observeProcessIdentity()` 在 `processBirth()` 为 null 时：

```text
MasterLeaseRuntimeIdentity.php:211   $observedBirth = $this->processBirth($pid, false, false);
MasterLeaseRuntimeIdentity.php:212   if ($observedBirth === null) {
MasterLeaseRuntimeIdentity.php:213       return $this->processDefinitelyMissing($pid)
MasterLeaseRuntimeIdentity.php:214           ? self::OWNER_MISSING : self::OWNER_UNKNOWN;
```

`isProcessDefinitelyMissing()` → `inspectDarwinProcess($pid, false)` → libproc 连续 8 次失败
→ `probePosixProcessExistence($pid)`（`posix_kill`，僵尸返回 **true**）
→ 兜底 `inspectPosixProcessWithPs($pid)` → **`ps` 被禁用 → 返回 `[]`**
→ `($info['exists'] ?? null) === false` 为 **false**（键缺失 ≠ false）
→ `OWNER_UNKNOWN` → 终止永不闭环。

**后果**：失败回滚时框架拒绝终止自己刚启动的 Master / Nginx master，
`reason=darwin_posix_termination_unverified`，留下孤儿进程与孤儿 owner intent。
实机日志：

```text
已拒绝使用裸 PID 终止 Master …：reason=darwin_posix_termination_unverified
Cannot recover owner intent while nginx PID identity is unsafe.
```

### 2.3 D3：匿名子类夹具的签名漂移（已修）

```text
FATAL ERROR: Declaration of …Stop@anonymous::acquireStopLock(string $instanceName, int $timeout = 5): bool
must be compatible with …Stop::acquireStopLock(string $instanceName, int $timeout = self::STOP_LOCK_TIMEOUT, bool $force = false): bool
```

`git log -L 331,340:…/Stop.php` 归因到 `4ccafc138`（新增 `$force` 未同步夹具）。
已修：`StopCommandFastLocalCleanupTest`（5 处）+ `StopCommandBootstrapCleanupResidualTest`（1 处），提交 `30fceb9fe`。

> **复发防线**：本仓大量测试用「匿名子类覆盖 protected 方法」+「`strpos($source, '<字面代码片段>')` 钉实现细节」。
> 改动**任何**被覆盖的方法签名、标识符或措辞前，必须先 grep `Test/` 目录，否则失败信息会以
> 「断言类型不符 / 整文件 FATAL」这种看不出原因的形式出现。

### 2.4 D4：`getProcessIdByPort()` 的归属歧义（已缓解）

同端口可被**多个**进程同时 `listen`（不同地址）。`getProcessIdByPort()` 只取 `lsof` 第一条：

```text
实测：托管 Nginx master 持 *:443 时
      lsof -ti:443 -sTCP:LISTEN → 36667 60174 81347 …
      getProcessIdByPort(443)   → 36667（Codex/ChatGPT 桌面应用，只监听 127.0.0.1:443）
```

`ManagedNginxPublicPortProbe::classifyOccupant()` 已改为**先自证、后指认**：
「自己的 master 存活（`run/nginx.pid`）**且**端口被自己的配置声明（owner 记录或生效 conf 的 `listen`）」
才算 `STATE_SELF`，kernel PID 比对只作最后兜底。
本计划**不改** `getProcessIdByPort()` 本身（通用原语，改动面过大且语义上「第一条」就是它的契约），
仅在文档中记录其歧义性，避免后续调用方误用。

### 2.5 D5：D1 修好后暴露出的**长期假绿**测试（新增）

这是修复 D1 的**直接副作用**，也是本轮唯一一次「我引入的新失败」。按纪律先做了
**改动前 / 改动后同环境对照**（同一台机器、同一个正在运行的 `default` 实例）：

```text
基线（stash 掉 D1+D2 四个文件）：
  7) StopCommandStableIdentityRetirementTest::testResidualRetirementUsesProtectedBirthLeasesOnly
  Tests: 377, Assertions: 1406, Failures: 7.

改动后：
  7) StopCommandFastLocalCleanupTest::testGracefulStopTrustsAuthoritativeIpcCompletionWithoutLocalPrefixScan
  Tests: 377, Assertions: 1405, Failures: 8.
```

⇒ 基线已含其中 7 条，**新失败恰好 1 条**。

**定位过程**（在用例里临时插桩，取真实调用序列）：

```text
DEBUG residuals=[200,66895,67148,42144,47602,47601,47724,47723]
DEBUG running  =[66895,67148,42144,47602,47601,47724,47723]
DEBUG calls    =["show","ipc"]   deleted=[]
```

夹具里 `ServerInstanceInfo('default', …, masterPid=26895, …)` 是**合成**的（26895 根本不存在，
实测 `liveness=exited`）；但 `collectFastLocalResidualPids()` 会额外合并
`collectIndexedResidualPids()` —— 后者直接读**真实**的
`var/process/pid/pid_index.json`，于是捡到了本机此刻**真的在跑**的 `default` 实例
（`weline-wls-master --name=weline-wls-master-default-p05113ef3`，PID 66895 等 7 个）。

判据链：

```text
collectRunningResidualPids()   Stop.php:2920-2931
  └─ $info['exists'] 为 true          ← DarwinProcessDriver::getProcessInfo() 原生补齐
  └─ isLikelyResidualWlsProcessName('php')  → true
  └─ isResidualPidStillOwnedByWls($pid)
       └─ Processer::isWelineServerProcess($pid)     Processer.php:13232
            └─ Processer::getProcessCommandLine($pid)  ← ★ D1 修好后才有值
```

**关键**：`Processer::isWelineServerProcess()` 的第一条策略就是读命令行。
D1 未修时该值恒为 `''` ⇒ 恒 `false` ⇒ 残留恒被判「已清干净」⇒ 用例恒绿。
**这个用例的绿，正是 D1 这个缺陷本身撑起来的。**

同一根因还暴露第二处：

```text
Stop.php:2808            private   function collectIndexedResidualPids(...)   ← 父类
StopCommandFastLocalCleanupTest.php:70      protected function collectIndexedResidualPids(...)
StopCommandResidualPidIndexTest.php:458     protected function collectIndexedResidualPids(...)
```

两处覆写都写着
`throw new \RuntimeException('direct force-stop must not scan PID indexes on the hot path')`，
但父类方法是 `private` ⇒ 子类同名声明的**永远不会被内部调用** ⇒ **守卫是死的**。
（实测：把可见性放宽到 `protected` 后两处守卫**仍未触发** ⇒ 说明「热路径不扫 PID 索引」这一
不变量在实现上**确实成立**，只是此前没有被真正验证过。）

**处置**：

1. `Stop::collectIndexedResidualPids()` 由 `private` 放宽为 `protected`
   （与同族 `collectResidualPrefixPids()` / `collectRecoverableManagedPids()` 一致）。
   **零运行时行为变化**：全仓无任何生产类 `extends Stop`（`extends Stop` 只出现在测试匿名类里），
   放宽后两处既有守卫变为**活守卫**，且实测不触发 ⇒ 不变量被证实。
2. 该用例补 `collectIndexedResidualPids()` 覆写返回 `[]`，切断宿主环境输入；
   **其余来源全部保留原实现**（本实例 Master PID 记录 + 真实存活探测 + 真实 IPC 成功路径），
   因此覆盖度不降，只是不再依赖「本机此刻跑不跑同名实例」。

> **复发防线**：单测里任何会读 `Env::VAR_DIR` 下**项目级共享状态**
> （`process/pid/pid_index.json`、`process/pid/port_index.json`、`var/server/instances/*`）
> 的通路，都必须显式覆写成固定值，否则用例的绿/红会随宿主环境漂移；
> 更要警惕「因为某个探测常年坏掉所以用例常年绿」这类**由缺陷支撑的绿**。

### 2.6 D6：D1 修好后**下一条** `ps` 依赖立刻顶上来（新增）

D1 修好后重跑 `server:start gateway80 -p 9560 --certificate-profile test`，
**错误信息变了** —— 这本身就是 D1 已修复的最强证据（旧错误消失了）：

```text
修复前：Hosted Nginx startup failed: Managed nginx newly-started process identity is unsafe …
        （NginxProcessIdentity：PID or command line is unavailable.）

修复后：Hosted Nginx startup failed: managed nginx TLS session resumption verification failed:
        managed nginx effective worker count could not be verified; candidate process stopped
```

调用链与根因：

```text
ManagedNginxService.php:448  $resumption = …->verify(…)
  └─ ManagedNginxTlsSessionResumptionVerifier.php:70   detectEffectiveWorkerCount($masterPid)
       └─ :689  NginxChildProcessProbe::workerPids($masterPid)
            └─ :92-96  非 Linux ⇒ 走「通用 Unix」分支
                        processTableExecutable() → /bin/ps（存在）
                        childListExecutable()    → /usr/bin/pgrep（存在）
            └─ :108-117  GatewayBoundedCommandRunner::run([/bin/ps, -p, …, -o, pid=,ppid=,command=])
                        ⇒ /bin/ps 被拒 ⇒ code≠0 ⇒ return null
  └─ :691  return is_array($workers) ? count($workers) : 0;   ⇒ 0
  └─ :71-74  $effectiveWorkerCount < 1 ⇒ failure('managed nginx effective worker count could not be verified')
```

实测（本机 agent 沙箱）：

```bash
$ /bin/ps -p 1 -o pid=,ppid=,command=
(eval):1: operation not permitted: /bin/ps
$ /usr/bin/pgrep -P 1 | head -3
98
100
102
```

⇒ **`pgrep` 可用、只有 `ps` 被拒**；而 `workerPids()` 的 `pgrep` 结果必须再经 `ps` 才能拿到
`ppid` 与 `command`，所以整条通路仍然断在 `ps` 上。

`NginxChildProcessProbe::processIsRunning()` 同文件 :190-200 也是 `ps -p … -o state=`，
受影响路径是 `SslCertificateService` 的旧版 Nginx 退役校验（:10676）。

**修复后的判别性验证**（把 `NginxChildProcessProbe.php` stash 掉再跑新用例）：

```text
1) …::testWorkerPidsReturnsEmptyInsteadOfUnknownForALiveNonNginxParent
   Darwin worker enumeration must not depend on the external ps command.
   Failed asserting that null is of type array.
2) …::testProcessIsRunningUsesNativeLiveness
   Failed asserting that null is true.
3) …::testProcessIsRunningReportsATerminatedAndReapedChildAsStopped
   Failed asserting that null is true.
Tests: 8, Assertions: 21, Failures: 3.
```

修复后同文件 **8 tests / 25 assertions / 0 failures**。

### 2.7 `proc_listchildpids` 的返回值语义（★ 实测，与头文件注释不符）

`proc_listchildpids(ppid, buf, bytes)` 的返回值实测是**写入的 `pid_t` 条目数**，不是字节数：

| 场景 | 返回值 | 缓冲区内容 |
|------|--------|-----------|
| `ppid=1` | `475` | 475 个 PID（同一时刻 `pgrep -P 1` 给出 474 条，差 1 为竞态） |
| shell 带 3 个 `sleep` + 探针自身 | `4` | `[90073, 90074, 90075, 90079]` |
| shell 带 2 个 `sleep` + 探针自身 | `3` | `[90494, 90495, 90499]` |
| `ppid=0` | `2` | `[1, 0]` —— **垃圾数据**，必须显式拦截 |

因此实现**不把返回值当循环上界**，而是扫描「开头连续的正数」（缓冲区零初始化，
未写入槽位恒为 0，两种语义下都正确），再用返回值做一次一致性交叉校验。
`ppid < 1` 一律 `null`。

---

## 三、决策自审（强制）

### 决策 A：修复落在「驱动层」而不是「`NginxProcessIdentity` 内部」

| 问题 | 分析 |
|------|------|
| 为什么这么做？ | 缺的是**平台原语**（Darwin 上取 argv / 判僵尸），不是 Nginx 身份算法。`NginxProcessIdentity` 只是第一个撞上的消费方。 |
| 收益 | 一次修复覆盖**全仓** macOS 调用方：`isWelineServerProcess()`、`isProcessManagerCreated()`、`status` 批量探测、`batchGetProcessInfo()` 等。 |
| 缺陷/风险 | 触及 `Weline_Framework` 进程驱动这一中心区域。 |
| 影响范围 | `Weline_Framework::System\Process`、`Weline_Server::Service\Edge\Nginx`、`Weline_Server::Service\MasterLeaseRuntimeIdentity`。 |
| 应对方案 | 用 OCP 方式**新增** `DarwinProcessDriver` 并注册在 `LinuxProcessDriver` **之前**；不改 `LinuxProcessDriver::supports()` 契约，不改任何既有方法签名 ⇒ 零回归面。 |
| 安全隐患 | 必须保持 fail-closed：原生探针取不到证据时**不得**放行，只能回落到既有 `ps` 通路。 |
| 命中技能 | `weline-server`、`quality-assurance`、`php-unit-testing`。 |

### 决策 B：原生探针「优先」而非「替换」

| 问题 | 分析 |
|------|------|
| 为什么这么做？ | 正常 macOS 主机上 `ps` 可用，现有行为（含 `%mem`/`%cpu`/`lstart` 字段）是**被测试钉住**的，不能动。 |
| 收益 | `ps` 可用时行为与今天**逐字段一致**；`ps` 不可用时降级到内核数据，而不是「查不到」。 |
| 缺陷/风险 | 两条通路并存，需要明确优先级与回落条件。 |
| 影响范围 | `DarwinProcessDriver::getProcessInfo()`（原生补齐缺字段）、`getProcessCommandLine()`（原生优先）、`probeProcessState()`（原生优先）。 |
| 应对方案 | 仅在原生探针**明确给出结论**时才短路；其余一律 `parent::`。 |
| 安全隐患 | 僵尸判定必须同时满足「libproc 读不到」+「`posix_kill` 说在」，缺一即 `unknown`。 |
| 命中技能 | `weline-server`、`quality-assurance`。 |

### 决策 C：argv 渲染沿用「裸空格拼接」，不发明引号语义

| 问题 | 分析 |
|------|------|
| 为什么这么做？ | 既有两条通路都是裸拼接：`LinuxProcessDriver:797` 把 `/proc/cmdline` 的 `\0` 换成空格；`ps -o args=` 本身也是裸拼接。 |
| 收益 | `NginxProcessIdentity::commandMatches()` 的 tokenize 语义**完全不变**；含空格的路径在三条通路上**同样**失败闭合，不会出现「原生更宽松」的假接受。 |
| 缺陷/风险 | 含空格的 argv 元素无法还原（既有通路同样无法还原）。 |
| 影响范围 | `DarwinProcessDriver` 的 argv 渲染。 |
| 应对方案 | 与 Linux 逐字节对齐；不引入引号转义（会改变 `tokenize()` 的匹配结果）。 |
| 安全隐患 | 失败方向是**拒绝**，不是放行 ⇒ 安全。 |
| 命中技能 | `weline-server`。 |

### 决策 D：D5 只放宽可见性 + 收紧用例，**不改** `Stop` 的业务判定

| 问题 | 分析 |
|------|------|
| 为什么这么做？ | D5 的表面症状是「用例红了」。但真因是**用例读了宿主环境**，而生产侧「残留存活就不删实例元数据」是**正确的 fail-safe**。若为了迁就用例去改 `collectRunningResidualPids()` / `deleteInstance()` 的判定，等于把 D1 修好的正确性又改回「误判为已清干净」。 |
| 收益 | 生产行为零改动；用例从「依赖宿主环境」变为「自洽夹具」；两处死守卫变为活守卫。 |
| 缺陷/风险 | 放宽 `private`→`protected` 属于可见性契约变更，理论上会与既有子类冲突。 |
| 影响范围 | 仅 `Stop::collectIndexedResidualPids()` 一行签名 + 1 个用例。 |
| 应对方案 | 先 grep 确认全仓**无生产类** `extends Stop`（只有测试匿名类），再改；改后跑全量 `Console/` 对照基线。 |
| 安全隐患 | 无。可见性放宽不改变任何调用路径（父类内部 `$this->` 调用在改前改后都绑定到同一个实现）。 |
| 命中技能 | `weline-server`、`quality-assurance`。 |

### 决策 E：D6 在**原语层**补 `childPids()`，而不是在 `NginxChildProcessProbe` 里手写 FFI

| 问题 | 分析 |
|------|------|
| 为什么这么做？ | 「枚举子进程」和「取 argv / 判僵尸」是同一类平台原语。放进 `DarwinProcessProbe` 才能被后续调用方复用（`SslCertificateService`、`ManagedNginxProcessManager` 已各自需要它）。 |
| 收益 | FFI 句柄、`available()` 硬门禁、`scalarInt()` 踩坑修复、失败闭合策略**只实现一次**。 |
| 缺陷/风险 | `DarwinProcessProbe` 的职责从「单进程观察」扩到「父子关系观察」，类注释需要同步。 |
| 影响范围 | 新增 1 个公开方法 + 1 条 CDEF 声明；`NginxChildProcessProbe` 新增 1 个 private 方法 + 2 处分支。 |
| 应对方案 | 复用既有 `ffi()` / `scalarInt()`；返回值语义用**扫描式**读取规避未文档化行为（见 §2.7）。 |
| 安全隐患 | `null`（问不出来）与 `[]`（确认没有）严格区分；`workerPids()` 在原生返回 `null` 时**回落** `ps`/`pgrep`，不改变 `ps` 可用时的行为。 |
| 命中技能 | `weline-server`、`quality-assurance`。 |

---

## 四、修复方案

### 4.1 新增平台原语（`Weline_Framework`）

**新文件** `app/code/Weline/Framework/System/Process/Native/DarwinProcessProbe.php`

纯 FFI、**零子进程**、零外部二进制：

| 方法 | 原语 | 返回 |
|------|------|------|
| `available(): bool` | `extension_loaded('FFI')` + `ffi.enable` + `PHP_INT_SIZE>=8` | 是否可用 |
| `bsdInfo(int $pid): ?array` | `proc_pidinfo(pid, PROC_PIDTBSDINFO=3, …)` | `start_tvsec` / `start_tvusec` / `status` / `name` / `comm` / `ppid`；读不到返回 `null` |
| `commandLine(int $pid): ?string` | `sysctl(CTL_KERN=1, KERN_PROCARGS2=49, pid)` | argv 裸空格拼接；失败 `null` |
| `liveness(int $pid): string` | 组合上二者 + `posix_kill` | `running` / `zombie` / `exited` / `unknown` |

`liveness()` 判定表（**直接由 §2.2 的实测表推导**）：

| libproc `bsdInfo` | `posix_kill(pid,0)` | 结论 | 理由 |
|---|---|---|---|
| 读到，`status !== 5`(SZOMB) | — | `running` | 内核给出可读的活动进程记录 |
| 读到，`status === 5` | — | `zombie` | `sys/proc.h` 的 `SZOMB`（与既有 `MasterLeaseRuntimeIdentity:1063` 一致） |
| 读不到 | `true` | `zombie` | 尸体持有 PID 直到被回收 ⇒ 活动身份已消失 |
| 读不到 | `false` 且 `errno=ESRCH(3)` | `exited` | 已被回收 |
| 读不到 | `false` 且 `errno=EPERM(1)` / 无 posix | `unknown` | 无权限或无原语 ⇒ **绝不猜** |

> **不变量**：`unknown` 是唯一「不表态」的出口，调用方必须保持 fail-closed。
> 绝不把「读不到」直接当成 `exited`（`proc_pidinfo` 对 pid 1 也返回 `read=0`，实测）。

### 4.2 新增 Darwin 驱动（`Weline_Framework`）

**新文件** `app/code/Weline/Framework/System/Process/Driver/DarwinProcessDriver.php`

```php
class DarwinProcessDriver extends LinuxProcessDriver   // ← 故意不 final
{
    public function supports(): bool { return PHP_OS_FAMILY === 'Darwin'; }
    public function getOsName(): string { return 'Darwin'; }

    public function getProcessCommandLine(int $pid): string;  // 原生优先 → parent
    public function getProcessInfo(int $pid): array;          // parent 优先，原生补齐缺字段
    public function probeProcessState(int $pid, bool $fresh = false): string; // 原生优先 → parent
}
```

> **为什么不是 `final`**：本缺陷唯一有判别力的回归手法，是用匿名子类覆写
> `executeCommand()` 让它**恒失败**，从而在**一台 `ps` 正常的机器上**模拟出
> 「外部命令不可用」的环境（见 `DarwinProcessDriverTest::driverWithUnusableExternalCommands()`）。
> `final` 会让该测试整文件 `FATAL ERROR: cannot extend final class`。
> 与既有的 `LinuxProcessDriver`（同样非 `final`）保持一致。

**注册顺序**（`ProcessDriverFactory::$driverClasses`）：

```php
private static array $driverClasses = [
    WindowsProcessDriver::class,
    DarwinProcessDriver::class,   // ← 新增，必须在 LinuxProcessDriver 之前
    LinuxProcessDriver::class,
];
```

不改 `LinuxProcessDriver::supports()`（它对所有非 Windows 返回 true）。
靠**注册顺序**保证 Darwin 命中新驱动，Linux 自然落到原驱动 ⇒ 契约零变更。

`batchGetProcessInfo()` 无需覆盖：`LinuxProcessDriver` 对非 `/proc` 平台会委托 `getProcessInfo()`。

### 4.3 修复 Darwin 僵尸判定（`Weline_Server`）

`MasterLeaseRuntimeIdentity::inspectDarwinProcess()` 在「libproc 连续失败 + `posix_kill` 说在」
这一支上，**先问原生僵尸探针**，只有探针也不表态时才回落 `ps`：

```text
现状： libproc 失败 → posix true → inspectPosixProcessWithPs()（ps 不可用 ⇒ []）⇒ exists 缺失 ⇒ UNKNOWN
改后： libproc 失败 → posix true → liveness()==='zombie' ⇒ return ['exists'=>false, 'state'=>'Z'] ⇒ MISSING
```

效果链：`observeProcessIdentity()` → `processDefinitelyMissing()` 为 true → `OWNER_MISSING`
→ `terminateExactProcessIdentity()` 返回 `darwin_posix_term_released` / `darwin_posix_kill_released`。

同一提交里还补了第三处（**由 D1 修复连带暴露**）：`inspectDarwinProcess()` 在
`($info['exists'] ?? null) !== true` 这一支上，原本只回 `pbi_name` + **空命令行**。
修好 D1 后这变成新的假阴性来源 —— 一个**健康**的 Master 会因「`name='php'` + 空 command」
被 `managedProcessStatus()` 判成 `OWNER_MISMATCH`。改为**两级原生回退**：

```text
① inspectDarwinManagedProcess($pid)['command']   （拒绝非 ASCII —— 本仓路径含「框架」，会拒）
② DarwinProcessProbe::commandLine($pid)          （字节级原始 argv，接受非 ASCII）
```

命中即同时采用其 `name`。这让 `ServerInstanceManagerMasterLeaseOverlayTest` 与
`MasterLeaseRuntimeIdentityDarwinBirthTest` 从「基线就红」变为绿（后者基线同样是红）。

### 4.4 修复 D5：可见性放宽 + 用例去环境耦合

| 文件 | 改动 |
|------|------|
| `Weline_Server/Console/Server/Stop.php` | `collectIndexedResidualPids()`：`private` → `protected`，补 docblock 说明为何必须可覆写 |
| `Weline_Server/Test/Unit/Console/StopCommandFastLocalCleanupTest.php` | `testGracefulStopTrustsAuthoritativeIpcCompletionWithoutLocalPrefixScan` 补覆写返回 `[]`，切断 `pid_index.json` 环境输入 |

**未改**：`collectRunningResidualPids()`、`isResidualPidStillOwnedByWls()`、`deleteInstance()` 调用点
—— 生产判定保持原样（残留存活 ⇒ 保留实例元数据，是**正确的 fail-safe**）。

### 4.5 修复 D6：Darwin 子进程枚举改走 libproc

**`Weline_Framework/System/Process/Native/DarwinProcessProbe.php`**（新增 1 个公开方法）

```php
public static function childPids(int $pid): ?array   // proc_listchildpids，零子进程
```

- CDEF 追加 `int proc_listchildpids(int ppid, void *buffer, int buffersize);`
- 常量 `PID_T_BYTES = 4`（Darwin `pid_t` = `int32_t`，**不是** `PHP_INT_SIZE`）、`MAX_CHILD_PIDS = 4096`
- 读取方式：**扫描开头连续的正数**，不把未文档化的返回值当循环上界（§2.7）
- 失败闭合：`pid < 1` → `null`；FFI 不可用 → `null`；返回值 `< 0` / 扫不出 PID /
  扫满整个缓冲（可能截断）/ 数量与返回值及返回值÷4 都对不上 → `null`

**`Weline_Server/Service/Edge/Nginx/Runtime/NginxChildProcessProbe.php`**

```php
workerPids():
    Linux   → /proc（不变）
    Windows → []（不变）
    Darwin  → ★ 新增 darwinWorkerPids()：childPids + bsdInfo(ppid) + commandLine(标题)
              原生返回 null 时**回落**既有 ps/pgrep 分支（ps 可用时行为逐字节不变）
    其它    → ps/pgrep（不变）

processIsRunning():
    Linux   → /proc（不变）
    Windows → null（不变）
    Darwin  → ★ 新增 liveness() 三态短路；unknown 时**回落** ps
```

`darwinWorkerPids()` 与 Linux 分支保持**同样的稳定性契约**：
采样前后各取一次子进程列表并要求逐字节相等，否则返回 `null`（代际不可证）。
子进程 `bsdInfo` 读不到或 `ppid` 不匹配同样返回 `null`。

**未改**：`linuxProcessStartTicks()`、`sortedPids()`、`GatewayBoundedCommandRunner` 的通用分支，
以及 `SslCertificateStorageSecurityTest:333` 钉住的调用点文本
（`NginxChildProcessProbe::workerPids(` 在 `SslCertificateService` 中原样保留）。

---

## 五、验收标准

### 5.1 单元 / 集成

| # | 用例 | 通过标准 |
|---|------|---------|
| A1 | `DarwinProcessProbe::liveness()` 对**存活**子进程 | `running` |
| A2 | 对 `pcntl_fork` 造出的**真实僵尸**（父不 `waitpid`） | `zombie` |
| A3 | 对 `waitpid` **已回收**的 PID | `exited` |
| A4 | 对不可能存在的 PID（如 999999） | `exited` |
| A5 | `commandLine()` 对真实子进程 | 含可执行路径与全部 argv，裸空格拼接 |
| A6 | `liveness()` 在 FFI 不可用时 | `unknown`（不抛异常、不猜） |
| A7 | `terminateExactProcessIdentity()` 对真实 `sleep` 子进程 | `released === true`，`reason` ∈ {`darwin_posix_term_released`,`darwin_posix_kill_released`} |
| A8 | `ManagedNginxProcessManagerConfigTestRecoveryTest::testStartIdentityFailureStopsTheExactNewlyLaunchedMaster` | 通过（消息含 `exact newly launched master was stopped`） |
| A9 | `StopCommandFastLocalCleanupTest` 全文件（9 tests） | 全绿，且**在宿主真有 `default` 实例运行时也全绿**（D5） |
| A10 | `StopCommandResidualPidIndexTest`（含死守卫变活守卫） | 全绿，守卫**未被触发** ⇒ 「热路径不扫 PID 索引」成立 |
| A11 | `DarwinProcessProbe::childPids()` 对真实子进程 | 含全部直接子进程；`pid<1` → `null`；不存在的 PID → `[]` |
| A12 | `NginxChildProcessProbe::workerPids()` 对**活着的非 nginx 父进程** | 返回 `[]`（**不是** `null`）⇒ 证明不再依赖 `ps` |
| A13 | `NginxChildProcessProbe::processIsRunning()` 对自身 / 不存在 PID / 已回收子进程 | `true` / `false` / `false` |

新增测试文件：

| 文件 | 规模 |
|------|------|
| `Weline_Framework/Test/Unit/System/Process/Native/DarwinProcessProbeTest.php` | 10 tests / 40 assertions |
| `Weline_Framework/Test/Unit/System/Process/Driver/DarwinProcessDriverTest.php` | 8 tests / 27 assertions |
| `Weline_Server/Test/Unit/Service/MasterLeaseRuntimeIdentityDarwinZombieTest.php` | 5 tests / 24 assertions |
| `Weline_Server/Test/Unit/Service/Edge/Nginx/NginxChildProcessProbeDarwinTest.php` | 8 tests / 25 assertions |

D6 用例的判别性已用「把 `NginxChildProcessProbe.php` stash 掉再跑」验证：**3 条失败**
（`null is of type array` / `null is true` ×2），修复后 8/8 绿 —— 见 §2.6。

### 5.2 回归（实测）

| 范围 | 结果 |
|------|------|
| `Test/Unit/Service/Edge/Nginx/` 全量 | **172 tests / 59132 assertions，0 failures，2 skipped**（其中 D6 新增 8/25）。改动前的同一范围是 164/59107/0 failures —— **注意：那是 D1 修好之后、D6 修好之前**；D1 之前该范围是 164/59103 且**含 1 条 failure** |
| `Test/Unit/Console/` 全量 | **377 tests / 1406 assertions / 7 failures** —— 与改动前**逐条同名单同数量**（7 条均为既有环境失败，见下） |
| `Framework/Test/Unit/System/Process/Driver/` + `Native/` | 25 tests / 91 assertions，1 skipped，0 failures |
| `Test/Unit/Service/` 目标子集（Termination / DarwinZombie / DarwinBirth / MasterLeaseOverlay / SharedSidecarInspector / SharedStateServiceManager） | 53 tests / 236 assertions，0 failures |

**一次未能复现的瞬时失败（诚实记录）**：在 D6 用例刚落地后的一次 `Edge/Nginx/` 全量跑中，
`ManagedNginxPublicPortProbeTest::testTheActiveConfigDoesNotClaimAPortItDoesNotDeclare`
报 `'foreign'` 期望 vs `'self'` 实得。排查结论：**与本改动无因果关系** ——

1. 该用例在 PHPUnit 发现序中排在 D6 新用例**之前**（`--list-tests` 行 75 vs 行 99）；
2. `ManagedNginxPublicPortProbe` 完全不引用 `NginxChildProcessProbe`（已 grep 全仓确认调用方只有
   `ManagedNginxTlsSessionResumptionVerifier` / `ManagedNginxProcessManager` /
   `SslCertificateService` / `NginxProcessIdentity`）；
3. 该用例自身有**临时端口竞态**：`freePort()` 先绑 `:0` 拿到端口再关闭，`holdPort()` 才重新绑定，
   中间窗口内 `getProcessIdByPort()`（`lsof` 第一条）可能瞬时指向别的 owner；
4. 之后 **11 次连跑全部干净**（去掉 D6 用例 5 次 164/59107/0，保留 D6 用例 6 次 172/59132/0）。

⇒ 判定为**既有瞬时竞态**，不在本计划范围内；已记录以便后续单独排查。

已确认**非本改动引起**的既有失败（保留在案，不在本计划范围）：

- `Console/`：`GatewayAgentDesiredStateLifecycleTest`、`GatewayCommandJsonOutputTest`、
  `StartCommandRuntimeConfigTest`、`StartForceSwitchStopArgsTest`、
  `StartSharedStateRuntimeSummaryOutputTest`（×2）、`StopCommandStableIdentityRetirementTest`（共 7 条）。
- `ProcesserUnixBatchLauncherHandshakeTest` 会**挂死**（`alarm` 75s 后 SIGTERM）。
  已用「stash 掉本改动后原样复跑」证明**基线同样挂死**，且该用例只反射
  `Processer::unixBatchLauncherCode` + `proc_open`，不经过 `getDriver()` / `getProcessCommandLine()`。

### 5.3 实机端到端（本机 macOS）—— ✅ 全部通过

前置：`app/etc/env.php` 临时置 `wls.edge.nginx.managed=true` + `auto_start=true`。

```bash
php bin/w server:start gateway80 -p 9560 --certificate-profile test
```

| # | 检查 | 实测结果 | 判定 |
|---|------|---------|------|
| E1 | `server:start` | `WLS 2.0 edge mode: legacy (request: auto)`；`TLS → TLS 1.3 (Nginx, live verified)`；`TLS session resumption → shared cache/tickets; live Reused verified: same Worker + cross Worker`；`✅ Weline Server Startup Completed!`；**无** `process identity is unsafe` | ✅ |
| E2 | `server:nginx:status` | `Managed: Yes` / `Installed: Yes` / `Running: Yes` / `PID: 93751`；`HTTP: 80`；`HTTPS: 443`；`Port source: Public default 80/443` | ✅ |
| E3 | `lsof -nP -iTCP:80 -iTCP:443 -sTCP:LISTEN` | `nginx 93751`（master）与 93752–93761（10 个 worker）同时持 `*:80` 与 `*:443` | ✅ |
| E3b | 公网入口实跑 | `http://…/` → **308** 跳 HTTPS；`https://…/` → **200**（`ssl_verify_result=0`） | ✅ |
| E4 | `server:nginx:reload` | `configuration candidate tested, activated, and verified`；worker 代际 93752–93761 → **94740–94749**（旧代际排空） | ✅ |
| E5 | `server:nginx:stop` | `stopped`，退出码 0；**无** `Cannot recover owner intent while nginx PID identity is unsafe` | ✅ |
| E6 | 停止后残留 | 无 nginx 进程、无 80/443 监听者、无 `managed-nginx.owner.intent.json` | ✅ |
| E7 | `server:status gateway80` | Master `93691`；Worker `93719`–`93722`、Watchdog `93717`、Gateway Agent `93718` —— **全部晚于 Master** | ✅ |

> **E1 的两次运行是本计划最有说服力的一组证据**：同一条命令、同一台机器，
> 修复前报 `PID or command line is unavailable`（D1），修好 D1 后**错误前移**为
> `effective worker count could not be verified`（D6），修好 D6 后整体成功。
> 每次错误都精确指向下一层未修的 `ps` 依赖，没有一次是「说不清为什么失败」。

### 5.4 环境收尾 —— ✅ 已还原

| 项 | 处置 |
|----|------|
| `app/etc/env.php` | 先备份到 `/tmp/env.php.before-e2e`，验证后**按字节还原**（`diff` 无差异）⇒ `managed=false` / `auto_start=false`，与实验前完全一致 |
| `gateway80` 实例 | `server:stop gateway80` 干净停止；记录标记 `startup_phase=stopped` / `pid=0` / `master_enabled=false` |
| 80 / 443 / 9560 | 全部释放（`lsof` 无监听者） |
| 实验前既有孤儿 | 清理了 **3 个 ppid=1 的 nginx cache-manager 孤儿**（PID 42722 / 47618 / 70628，早前失败实验的遗留，无监听端口），使 E6 的「无孤儿」断言有意义 |
| `default` 实例 | 仍健康：`https://p05113ef3.test.weline.com:9555/` → **200**（Master 66895，6/6 Running） |

> ⚠️ **本机托管 Nginx 现在是「可用但默认关闭」**。历史备忘里的
> 「本机保持 `managed=false`，否则 `server:start` 会整条失败」这条理由**已随 D1/D6 修复失效** ——
> 现在把它改成 `true` + `auto_start=true` 即可获得公网 80/443 入口（本次已验证全链路）。
> 之所以还原成 `false`，只是因为**验证运行不应静默改动本机配置**，把开关留给使用者决定。

---

## 六、风险与对策

| 风险 | 等级 | 对策 |
|------|------|------|
| 新增驱动改变 macOS 现有行为 | 🔴 | 原生**优先但仅在明确结论时短路**；`getProcessInfo` 保持 `parent::` 优先，只补空字段；先跑全量回归再实机验证 |
| 僵尸判定放宽导致误杀活进程 | 🔴 | `liveness()` 三态；`unknown` 一律回落既有通路；僵尸判定要求「libproc 读不到」+「`posix_kill` 说在」**同时**成立 |
| FFI 在某些发行版被禁用 | 🟡 | `available()` 先探测；不可用即回落 `ps`（行为同今天） |
| 静态契约测试钉住实现细节 | 🟡 | 新增文件、不改既有方法签名；改前先 grep `Test/` |
| `ps` 通路的 `%mem`/`%cpu` 字段无原生等价物 | 🟢 | 保持 `''`（与 `getDefaultProcessInfo()` 的「未取到」语义一致），不伪造 |
| **修好一层 `ps` 依赖后，下一层立刻顶上来**（D1 → D6 实测发生） | 🔴 | 不靠「一次改完」，而靠**实机 E2E 反复跑**：每次失败都精确暴露下一层。教训是**不要把 macOS 的进程观察押在任何外部二进制上**；新增同类通路前先 grep `'/bin/ps'`、`'ps -'`、`pgrep`、`lsof` |
| `proc_listchildpids` 返回值语义未文档化 | 🟡 | 用「扫描开头连续正数」读取，不用返回值当循环上界；再用返回值做交叉校验（§2.7） |

---

## 七、进度跟踪

| 项 | 状态 |
|---|---|
| 计划文档 | ✅ 本文 |
| D3 夹具签名漂移 | ✅ `30fceb9fe` |
| D4 归属歧义 | ✅ 已在探针侧绕过 + 记录 |
| D1 原语 + Darwin 驱动 | ✅ `DarwinProcessProbe` + `DarwinProcessDriver` + 工厂注册 |
| D2 僵尸判定 | ✅ `MasterLeaseRuntimeIdentity::darwinProcessIsZombie()` + 两级原生回退 |
| D5 假绿用例 + 死守卫 | ✅ 可见性放宽 + 用例去环境耦合 |
| D6 子进程枚举去 `ps` | ✅ `DarwinProcessProbe::childPids()` + `NginxChildProcessProbe` Darwin 分支 |
| A1–A13 用例 | ✅ 全绿 |
| 5.2 回归 | ✅ 与基线逐条对齐，无新增失败 |
| 5.3 实机端到端 | ✅ E1–E7 全通过 |
| 5.4 环境收尾 | ✅ 已按字节还原 |
| 5.4 环境收尾 + 提交推送 | ⬜ |
