# WLS 启动监听交接：能力门禁缺口与无 FFI 回退缺陷

**状态**：⬜ 待开发（status: pending）
**立项日期**：2026-09-26
**触发事件**：生产站 `changanhanfu.com` 2026-09-26 全站 502（约 75 分钟）
**影响面**：`Weline_Server`（启动链路 / 运行时能力门禁）
**优先级**：🔴 高 —— 任何「PHP 无 FFI 的 Linux 主机」在**纯 WLS（`edge.mode=wls`）**下
**无法以后台 Master 方式启动**，且报错信息完全不指向真实原因。

---

## 一、现象

生产重启 `default` 实例时（`sudo -u www php bin/w server:start default -r --no-ssl`）得到：

```
MASTER_BOOTSTRAP_FAILED: Direct shared listener endpoint could not be read.
```

- 类：`master_bootstrap`；抛出点：`DirectSharedListener::assertStreamEndpoint()`
- `var/server/instances/default.json` → `startup_phase=failed`、`master_pid=0`
- `127.0.0.1:9510` 无监听者 → 宝塔 nginx 上游不可达 → 502

**同一份代码、同一份配置**，改用 `--foreground`（前台 Master）即可启动成功。
这是本次定位的关键对照组：**差别不在依赖、不在端口、不在权限，只在「谁去打开监听套接字」。**

---

## 二、根因

### D1 🔴 无 FFI 时，回退路径会「关闭套接字」却「仍然声明 fd 3 交接」

`Start::startMasterInBackground()`（`Console/Server/Start.php:3472-3513`）：

```php
} elseif ($inheritedDescriptors !== []) {
    // Inherited-FD handoff needs the FFI-backed Unix batch launcher.
    // Production PHP builds often omit FFI; falling back keeps Master
    // startable via createDetachedPhpArgv after releasing the sockets.
    $ffiReady = \extension_loaded('FFI') && \class_exists(\FFI::class, false);
    $spawnedMasterPid = 0;
    if ($ffiReady) {
        $spawnResults = Processer::batchCreate([... 'inheritDescriptors' => $inheritedDescriptors ...]);
        $spawnedMasterPid = (int)($spawnResults['wls-master-startup-handoff'] ?? 0);
    }
    if ($spawnedMasterPid <= 0) {
        $this->closeStartupListenerCopies();                       // ← 主动关掉监听套接字
        $spawnedMasterPid = Processer::createDetachedPhpArgv(      // ← 不传 FD 3
            $argv, BP, $processIdentity, null
        );
    }
```

注释明说：无 FFI 时「释放套接字后仍能让 Master 起来」——即设计意图是
**回退后由 Master 自己 bind**。但这条意图被下面两处破坏：

1. **`publishStartupListenerHandoffIntent()` 无条件写死 fd 3**
   （`Start.php:7499-7547`）：

   ```php
   $config['gateway']['startup_listener_handoff'] = [
       'schema_version' => 1,
       'transport' => 'posix_inherited_fd',
       'continuous_ownership' => true,
       'fd' => DirectSharedListener::INHERITED_FD,   // = 3
       'lease_id' => ..., 'port' => ..., 'launch_id' => ...,
   ];
   ```

   只要 `$lease !== []` 就写，**不检查 FFI**。调用点在 `Start.php:999` 与 `Start.php:2020`，
   **早于** `saveInstanceInfo()`（`Start.php:2475`）与 `startMasterInBackground()`（`Start.php:2546`）
   ⇒ 交接声明**先被持久化进实例文件**，而能否兑现是在**后面**才决定的。

2. **`runMasterOnly()` 只信持久化声明**（`Start.php:3071` 起）：
   子进程从实例文件读回 `$data`，`Start.php:3203` 调
   `adoptPosixStartupListenerFromEndpoint()` → `validatedPosixStartupListenerHandoff()`
   （`Start.php:7834-7858`）逐项校验 `schema_version===1` / `transport==='posix_inherited_fd'` /
   `continuous_ownership===true` / `fd===3` / `port` 匹配 / `lease_id`·`launch_id` 为 32 位 hex 且
   `launch_id` 相等 —— **全部通过**（声明本身是合法的）。
   然后 `fopen('php://fd/3', 'r+')` 在分离子进程里**能打开**（fd 3 是别的描述符），
   于是进入 `DirectSharedListener::installStartupListener()` →
   `assertStreamEndpoint()`（`DirectSharedListener.php:226-252`）：

   ```php
   $bound = @\stream_socket_get_name($listener, false);
   if (!\is_string($bound) || $bound === '') {
       throw new \RuntimeException('Direct shared listener endpoint could not be read.');
   }
   ```

   ⇒ 抛出本次事故的那条错误。**「能打开 fd 3」被当成了「fd 3 就是那个套接字」的证明**，
   而 `continuous_ownership=true` 这个契约字段实际上**从未被兑现过**。

> **生产实测证据**（`var/server/instances/default.json`）：
> `gateway.startup_listener_handoff = {schema_version:1, transport:posix_inherited_fd,
> continuous_ownership:true, fd:3, lease_id:53506c2a782a8f50f27b28c7ccb8243c,
> launch_id:9491988daa620ab344bdd08e09a8e4b9, bind_host:127.0.0.1, port:9510}`，
> 而同机 `php -m` **没有 `ffi`**。声明与能力直接矛盾。

### D2 🔴 能力门禁漏检 FFI，导致「声明支持」与「实际可兑现」不一致

`RuntimeDependencyBootstrapper::canUseSharedFdPrimitives()`（`Service/Runtime/RuntimeDependencyBootstrapper.php:1548-1557`）：

```php
foreach (['proc_open', 'proc_close', 'proc_get_status', 'posix_setsid', 'posix_kill'] as $function) {
    if (!\function_exists($function)) { return false; }
}
return \is_dir('/dev/fd') || \is_dir('/proc/self/fd');
```

**没有 FFI 检查。** 生产这 5 个函数全有、`/proc/self/fd` 存在 ⇒ 门禁判定
`canUseSharedFdPrimitives() === true`，而真正的 FD 继承传输
（`Processer::batchCreate(['inheritDescriptors' => …])`）**在无 FFI 时根本不可用**。

连带后果：

- `RuntimeDependencyBootstrapper:92-97` 用该函数做 Direct 的 fail-closed 判据 ⇒ 判不出真实缺陷。
- `RuntimeDependencyBootstrapper:147-160` 的缺失清单**只列 `sockets` / `ext-event`**，永远不报 FFI
  ⇒ 运维照着提示装扩展，装完仍然起不来（本次即如此：装完 `ext-event`，错误只是换了一层）。
- `Start.php:1060` 的运维提示写「回退 `shared_fd`（需要 **ext-event** 与 POSIX FD/进程原语）」
  ⇒ **文档口径也漏了 FFI**。

### D3 🟡 `RuntimeDependencyBootstrapper:115-119` 内部自相矛盾

```php
if ($direct
    && (!$reusePortRequired || $this->canUseSockets())
    && $this->canUseEvent()        // ← 即使 reusePortRequired=true 也要求 ext-event
    && $opensslReady
) { return $this->result('ready', ...); }
```

`$reusePortRequired === true`（显式 `listener_mode=reuseport`）时，ext-event **本不该**是硬依赖，
但这里仍然要求；而 `147-160` 又把 `ext-event` 无条件列为缺失项。
两个分支对同一能力给出相反结论，取决于先命中哪一个。

### D4 🟡 失败信息不指向根因

最终对用户可见的只有
`MASTER_BOOTSTRAP_FAILED: Direct shared listener endpoint could not be read.`，
既不含「缺 FFI」，也不含「回退未兑现交接」。定位必须靠读源码 + 两组实机对照实验
（前台成功 / 后台失败），成本极高。

---

## 三、修复方案

### F1（推荐）让门禁认识 FFI，并禁止声明不可兑现的交接

1. 新增能力判据（POSIX）：

   ```php
   private function canUseInheritedFdTransport(): bool
   {
       return \extension_loaded('FFI') && \class_exists(\FFI::class, false);
   }
   ```

   并入 `canUseSharedFdPrimitives()`（或作为独立条件在 `92-97` 与 `115-129` 处显式参与判定），
   并把缺失项文案补上 `FFI`。

2. `publishStartupListenerHandoffIntent()`：POSIX 分支在写 `posix_inherited_fd` 之前，
   **先确认传输可用**；不可用时 `unset($config['gateway']['startup_listener_handoff'])`
   并**释放该次预留的监听套接字**，让 Master 走「自己 bind」的既有通路
   （即回退注释 3473-3475 真正想表达的行为）。

3. `startupMasterInheritedDescriptors()` 在传输不可用时返回 `[]`，
   使 `startMasterInBackground()` 直接落到 `Start.php:3519` 的 `detached_php_argv` 分支，
   **不再出现「先 close 再 fallback」的中间态**。

### F2（可选，彻底摆脱 FFI 依赖）用 `proc_open` 描述符表交接 FD 3

`proc_open()` 的 `$descriptor_spec` 支持直接传入**已有流资源**（`[3 => $listener]`），
子进程即得到 fd 3。可在 `createDetachedPhpArgv` 通路里实现等价交接，
从而在不装 FFI 的主机上也能保住「监听套接字零丢失」的平滑重启语义。

> 注意：`Start.php:3505-3513` 现有回退是 `closeStartupListenerCopies()` **之后**再 spawn，
> 若走 F2 必须去掉这次 close，否则套接字已释放、FD 3 指向无效目标。

### F3（必须）启动前置自检 + 可执行文案

在 `server:start` 早期（`Start.php:1041` 附近的依赖自检段）对**纯 WLS / 会预留公网监听**的实例
做一次传输能力自检，失败时直接输出：

```
WLS 启动监听交接需要 FFI（或改用 --foreground / 让 Master 自行 bind）。
当前 PHP 缺少：FFI。
安装建议：… / 或显式选择回退形态。
```

不允许把「交接不可兑现」拖到子进程里以 `Direct shared listener endpoint could not be read.` 收场。

### F4（必须）修掉 `115-119` 的自相矛盾

`reusePortRequired === true` 时不应要求 `canUseEvent()`；`ext-event` 的缺失清单
也应与 `reusePortRequired` 联动，而不是无条件列出。

---

## 四、验收标准

| # | 验收项 | 判据 |
|---|--------|------|
| A1 | 无 FFI 主机：后台 Master 能起来 | 移除 `ffi` 后 `server:start <inst> --no-ssl` → `startup_phase=running`，页面 200 |
| A2 | 无 FFI 主机：实例文件不残留 `posix_inherited_fd` 声明 | `gateway.startup_listener_handoff` 不存在 |
| A3 | 无 FFI 主机：`server:status` 明确提示缺 FFI 与替代形态 | 文案含 `FFI` 与 `--foreground` |
| A4 | 有 FFI 主机：仍走真交接 | Master 进程 `lsof` 可见 **fd 3 = 监听套接字**，页面 200 |
| A5 | 能力门禁单测 | `canUseSharedFdPrimitives()` 在无 FFI 时返回 `false`（或传输判据为 `false`） |
| A6 | `115-119` 矛盾消除 | `listener_mode=reuseport` 且无 ext-event 时不再误判 |

> ⚠️ **A1–A3 必须在真的没有 FFI 的环境里验**。本次事故的教训之一就是
> 「本机（macOS，有 FFI）永远复现不出来」。可用 `php -d extension= -n` 起裸 PHP，
> 或用 `php -d disable_functions=...` 隔离，但**首选**在容器/临时主机上真删 `ffi.so` 复跑。

---

## 五、实机证据（2026-09-26，生产 changanhanfu.com）

| 步骤 | 动作 | 结果 |
|------|------|------|
| E1 | `server:start diag-boot-probe -p 19510 -c 1 --no-ssl`（后台） | ❌ `MASTER_BOOTSTRAP_FAILED: Direct shared listener endpoint could not be read.` |
| E2 | 同上但加 `--foreground` | ✅ `127.0.0.1:19510` LISTEN，Master + watchdog + worker 全起 |
| E3 | 装 `event.so`（PECL 3.1.6）后再试后台 | ❌ 依赖报错消失，**错误换成同一条交接错误** ⇒ 证明 ext-event 不是本缺陷的解 |
| E4 | 装 `ffi.so`（php-8.4.24 `ext/ffi`，链接 `libffi.so.6`） | — |
| E5 | `server:start ffi-probe -p 19520 -c 1 --no-ssl`（**后台**） | ✅ `phase=running, daemon=true`；`lsof` 见 **fd 3 = 监听套接字**；HTTP 200 |
| E6 | `server:start default -r --no-ssl`（**后台**） | ✅ `phase=running`，Master 137257 持 fd 3 on 9510，全站 200 |

> **★ 本专项最有价值的一条经验**：
> 装了 `ext-event` 之后错误**不是消失，而是换了一层**——从「缺 ext-event」变成
> 「`Direct shared listener endpoint could not be read.`」。
> 这与 Darwin 专项里「修好 D1 暴露出 D6」是**同一类现象**：
> **同一条启动链上有多个独立的能力假设，每修一层只揭开下一层。**
> 因此验收方式不能是「装完扩展就宣布解决」，必须是**重复的真实端到端启动**，
> 让每一层失败精确指认下一层。

> **★ 第二个经验**：`fopen('php://fd/3')` 成功 **不等于** fd 3 是那个套接字。
> 分离子进程里 fd 3 极易被日志/管道占用，`continuous_ownership=true` 于是变成
> **一份从未被验证的自我声明**。任何「继承描述符」契约都应像
> `assertStreamEndpoint()` 那样**按端点自证**，而不是按「能打开」自证。

---

## 六、进度跟踪

| # | 任务 | 状态 |
|---|------|------|
| 1 | F1 门禁认识 FFI + 禁止声明不可兑现的交接 | ⬜ |
| 2 | F1 `startupMasterInheritedDescriptors()` 传输不可用时返回 `[]` | ⬜ |
| 3 | F3 启动前置自检 + 可执行文案（含 `--foreground` 替代提示） | ⬜ |
| 4 | F4 修掉 `115-119` 自相矛盾 | ⬜ |
| 5 | 补 `Start.php:1060` 运维提示文案（补上 FFI） | ⬜ |
| 6 | A5/A6 单测 | ⬜ |
| 7 | A1–A3 无 FFI 环境实机验证 | ⬜ |
| 8 | A4 有 FFI 环境实机验证（回归） | ⬜ |
| 9 | F2（可选）`proc_open` 描述符表交接 FD 3 | ⬜ |
| 10 | 文档同步（`doc/开发日志.md`、本 spec） | ⬜ |

---

## 七、关联

- 同期磁盘事故：`Weline_Deploy/doc/开发/spec/deploy-backup-retention-and-disk-guard.md`
- 同类「修一层冒一层」专项：`spec/darwin-process-identity-without-ps.md`
