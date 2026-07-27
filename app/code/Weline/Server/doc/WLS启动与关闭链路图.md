# WLS 启动与关闭链路图

## 适用范围

- 本文描述默认 WLS Orchestrator 模式下的实例链路，即 `php bin/w server:start [name]` 与 `php bin/w server:stop [name ...]`。
- `--cli`、非 Nginx `--strategy`、`--no-nginx` 和 `--no-ssl` 会在 `Start::execute()` 创建 Master/Worker 前拒绝；不存在另一条公网或开发 Server 分支。
- `server:stop name-a name-b`、`server:stop --prefix <prefix>` 与 `server:stop --all` 都只在外层枚举多个实例；每个实例仍复用同一关闭协议并单独获取 stop lock。

## 启动链路图

```mermaid
flowchart TB
    A["CLI: php bin/w server:start [name]"] --> B["Start::execute()"]
    B --> C{"启动分支?"}
    C -->|退役/非 Nginx 选项| C1["启动前拒绝<br/>不创建 Master/Worker"]
    C -->|--master-only| M1["runMasterOnly(instance)<br/>只校验 endpoint schema v4<br/>读取嵌套 runtime_selection"]
    C -->|默认 WLS| D["acquireStartLock(instance)"]
    D --> E["getServerConfig()<br/>解析 WLS 回源端口、Worker 数与公网 TLS 意图"]
    E --> E1["Nginx-only + RuntimeSelection 预检<br/>拒绝 --no-nginx/--no-ssl/外部边缘<br/>要求 managed=true、auto_start=true、二进制已安装且含 rewrite<br/>普通启动只读，不下载或编译"]
    E1 --> F{"是否需要先清旧实例?"}
    F -->|是: -r 或端口/实例冲突| G["停止前固化旧代实际监听端口<br/>stopExistingServer() 委托 server:stop"]
    F -->|否| H["检查 loopback H1、control、Dispatcher/Worker 与托管 Nginx 端口"]
    G --> G1["Restart handoff fence<br/>Windows 正常重启最多 30s；POSIX 12s；fast 6s<br/>等目标端口无监听 + scoped 进程全退出"]
    G1 --> H
    H --> I["saveInstanceInfo()<br/>写 endpoint schema v4 + 嵌套 runtime_selection<br/>WLS SSL=false、edge_adapter=nginx"]
    I --> J["保存 provisional 实例配置<br/>Nginx live 前不提交 env 公网端点"]
    J --> K{"daemon?"}
    K -->|后台| L["startMasterInBackground()<br/>后台拉起 server:start --master-only<br/>并轮询 instance.json 等待 master_pid/control_port"]
    K -->|前台| K0["拒绝 foreground<br/>托管 Nginx live gate 需要启动命令继续编排"]
    L --> M1
    M1 --> N["MasterProcess::run()"]
    N --> O["registerMasterPid()<br/>cleanupStaleInstanceFiles()<br/>分配 control_port"]
    O --> P["ServiceOrchestrator::bootstrapControlPlane()<br/>启动 IPC 控制面并预置 maintenance"]
    P --> Q["saveMasterInfo('bootstrapping')<br/>instance.json 写入 master_pid/control_port/startup_phase"]
    Q --> R["runLoopWithDeferredChildStartup()"]
    R --> S["startAllChildServices()<br/>按依赖阶段批量拉起子服务"]
    S --> S1["Windows: 固定 K 路 launcher<br/>macOS/Linux: 短命 PHP/pcntl launcher<br/>Linux reuseport 不继承 listener；shared_fd 只保留 Master-owned FD"]
    S1 --> T["REGISTER → READY gate<br/>Worker credential store fail-closed readiness<br/>首页 shared publish + process hit<br/>loopback H1 listener ACK"]
    T --> T1["waitForStartupAcceptance()<br/>等待关键角色、策略与 Dispatcher 路由 ACK 达标"]
    T1 --> U["persistServicesInfo()<br/>broadcastRoutingPolicyToWorkers()"]
    U --> V["armServerReadyNotification()<br/>startup_phase -> running"]
    V --> V0["如属重启：恢复并确认 maintenance 快照<br/>必须先回到 READY 业务路由"]
    V0 --> X["loopback /_wls/health 预检"]
    X --> Y["生成候选 Nginx 配置<br/>含 ACME → Worker 路由<br/>nginx -t → 原子发布 → start/reload"]
    Y --> Z["公网 live gate<br/>owner + generation + 证书绑定 TLS 1.3<br/>H2/H1: fresh 请求真实 WLS health<br/>H3: HTTP/3-only 真实 WLS health；本地/缓存不算<br/>TLS resume: pair-bound 有界证明"]
    Z --> Z1["持久化 Nginx 公网事实<br/>syncServerConfigToEnv()"]
    Z1 --> W["释放 start lock<br/>CLI 成功；Master/Nginx 常驻"]
```

## 关闭链路图

```mermaid
flowchart TB
    A["CLI: php bin/w server:stop [name ...]"] --> B["Stop::execute()"]
    B --> C{"目标选择"}
    C -->|--all| C1["stopAllInstances()<br/>枚举全部实例"]
    C -->|--prefix| C2["stopInstancesByPrefix()<br/>枚举匹配实例"]
    C -->|多个名称| C3["stopNamedInstances()<br/>按参数顺序去重"]
    C -->|单个/默认名称| D["acquireStopLock(instance)"]
    C1 --> D
    C2 --> D
    C3 --> D
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
    K --> L["阶段1: 停止项目托管 Nginx 公网 accept<br/>Direct/Dispatcher 停止接纳回源新请求"]
    L --> M["阶段2: 等待 Nginx 公网连接与内部 H1 请求排水完成"]
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
```

## 关键分支说明

- `server:stop` 可接收多个空格分隔的实例名，例如 `php bin/w server:stop api worker`；名称按输入顺序去重，逐个获取实例级 stop lock 并执行完整单实例关闭链路。某个实例锁被占用时只跳过该实例，继续处理后续名称。
- 新启动在停止旧实例之前产生不可变 `RuntimeSelection`，并以 endpoint schema v4 写入嵌套 `runtime_selection`。`--master-only` 只接受这一个事实源；旧 endpoint、缺失/未知字段或根级 topology/listener/event/SSL 投影都在绑定端口前拒绝，不重新推导或升级。
- 内部拓扑的 `auto` 在所有平台固定为 Direct：Linux 优先经能力验证的 `reuseport`，不可用时回退 Master-owned `shared_fd`；macOS 使用 `shared_fd`；Windows 为 Nginx 均衡的独立 `worker_ports`。所有平台仍允许显式 Dispatcher；已删除的 independent/遗留模式、配置键和命令行别名没有兼容读取入口。
- `server:start -r` 在任何新 Session/Memory sidecar、Master 或 Worker 创建前冻结一次旧代端口快照；空集合也是已捕获的有效快照，禁止在本次启动改写运行态后重新枚举。现行快照包含托管 Nginx 公网端口、WLS loopback 主端口、控制端口与 Dispatcher/Worker 端口，排除可跨实例复用的 Session/Memory sidecar。只有目标端口全部无监听且本项目+本实例的 Nginx、Master、Dispatcher、Worker、Maintenance 与 Runtime Watchdog scoped 进程全部退出才继续。遗留 Gateway 线索只允许用于回收旧残留，不构成可再次启动的角色。
- 重启交接超时时，端口 owner/scope 只用于诊断；`Start` 不杀 unknown/foreign 进程、不换端口、不跳过栅栏，而是中止新 Master 启动并返回非零。正常重启清理总预算在 Windows 为 30 秒、macOS/Linux 为 12 秒，fast-local 为 6 秒；Windows 的较长预算只覆盖已退出 PID 的 LISTEN 表延迟，不放宽 owner/scope 栅栏。
- `-r` 和 `-r -f` 都会在停止旧代前保存 `app/etc/env.php` 中的原始 `system.maintenance` 值；平滑 `-r` 随后才临时开启维护态。无论新 Master 成功、超时、端口栅栏失败或中途 return/fatal，启动事务都恢复该原值：原来已开启则保持开启，原来关闭则恢复关闭。
- 后台重启只有在新 Master 已进入 `running` 后才提交维护事务：启动进程绕过实例列表缓存，按显式实例 endpoint 直连控制面，保留本次命令的 `operation_id`，并在一个 monotonic 总 deadline 内等待该操作退出 `active/queued` 且 `maintenance_mode` 等于快照值。该恢复与确认必须发生在生成/启动 Nginx 候选和公网 health/protocol gate **之前**；否则门禁请求仍可能落到临时维护路由。Direct Master 只会在全部 READY Worker 完成维护门禁 ACK 后提交该状态；缺失 `maintenance_mode/control_operation` 字段、endpoint 不可控或超时都属于启动失败，禁止打印“维护模式已关闭”。
- 后台 `server:start` 只有在 Master/Worker `running`、托管 Nginx owner/config generation 接管、证书指纹绑定的 TLS 1.3 握手，以及 H2/H1 fresh 请求分别真实到达 owner 绑定的 `/_wls/health?detail=1`、匹配 WLS backend identity/config generation 后才返回 `0`；配置或 ALPN 不能单独写成 `runtime_verified`。H3 还要求 HTTP/3-only fresh QUIC 请求穿过 Nginx 到达同一真实 health；Nginx 本地响应或边缘缓存不算，客户端 verifier 不可用时明确保持 pending。失败门禁按事务规则返回非零并回滚候选配置；旧 generation 无法重新证明时停止 Nginx 并保留恢复证据。
- Nginx shared session cache/tickets 已启用。TLS 恢复门禁固定使用 `fresh-share-two-connection-pair-v1`：每对新建 SSL share，仅含 fresh issuer 与 fresh-TCP probe；有效 probe ≥ 8、`failed=0`、恢复握手 P95 ≤ 50ms。多 Worker 必须在各对 issuer/probe PID 上同时证明 same/cross，单 Worker cross 为 `not_applicable`；HTTP/3/QUIC Session Resumption 仍未验证。
- Nginx-only 模式拒绝 `--foreground`：前台 Master 会阻塞启动命令，无法继续完成托管 Nginx 事务与公网 live gate，因此必须在创建子进程前 fail closed。
- `server:start -r -f` 属于停机型切换，旧实例不会走平滑排水等待，而是更快进入本地清理。
- `server:stop -f` 仍然优先走 IPC STOP，但会把 Orchestrator 切到 `skipDrain=true`，也就是跳过关闭阶段 1/2，直接进入统一终止、校验和关闭 IPC。
- 如果 CLI 侧等待 IPC 进度超时，且判断停机流并未继续推进，`Stop` 会强杀 Master 并执行本地 residual cleanup。
- 如果本地 residual cleanup 后仍检测到残留进程，`Stop` 不会立刻删除 `var/server/instances/{instance}.json`，而是保留元数据，避免失去后续恢复和继续清理的控制线索。

## 跨平台批量启动约束

### macOS/Linux

- 先将整批命令严格预检为 `PHP_BINARY` argv，任一项含 shell 操作符或解析失败时，在未创建子进程前放弃优化路径。
- Master 用 `proc_open` argv + `bypass_shell` 启动一个短命 `php -r` launcher；不经 `sh`/`bash`/`dash`，也不为每个 Worker 串行等待 PID。
- launcher 为每项 `pcntl_fork`，子进程 `setsid`、重置 0/1/2 后 `pcntl_exec`最终 PHP argv。fork PID 经 exec 保持不变，因此 batch 回传的是真实 Worker PID，不是 launcher 或 shell PID。
- Linux 从 `/proc/self/fd`、macOS 从 `/dev/fd` 枚举 Master FD；默认将 FD > 2 在 launcher 中映射为 `/dev/null`，Worker 不得继承 Master 的 control/lock FD。POSIX `shared_fd` Direct 只对经校验的 loopback H1 listener FD 3 显式保留；其它替代槽位在 exec 前关闭。
- PID 回显使用一个总 deadline。收集后立即关管道、终结/回收 launcher 并 `proc_close`；Master 不长期保留 shell、launcher 或子进程 `proc` resource。
- launcher 退出后 Worker PPID 可重托管到 PID 1、`launchd` 或容器 subreaper；以真实 PID + lease + IPC 判定健康，不要求 PPID 恒等于 Master。
- 优化 launcher 不可用时，只能在严格预检尚未产生子进程时回退；已提交但 PID 超时的项返回 0 交给 IPC REGISTER 收敛，不重复启动。
- 通用 POSIX 后台 `Processer::create()` 使用 `cd && exec nohup ... & echo $!`。`exec` 让复合后台子 shell 原位替换为最终 PHP 进程，因此 `$!`、`ps`、PID 索引和子进程自报 PID 必须一致；禁止把短命 launcher 和真实进程同时登记为同一服务。

### Windows

- `Processer::batchCreate()` 将启动项分配到固定 K 路 PowerShell launcher，默认 K=4、范围 1-8；每一路内部顺序 `Start-Process`，各路并行。
- 所有 launcher 提交完成后才开始 batch result 总预算，避免脚本准备时间提前耗尽结果窗口。
- WLS framework child 自己持久化权威 PID，父进程只接收 raw PID，不重复写同一 PID 索引。
- helper 超过 TTL 后只发终止；确认退出前保留资源和结果文件，随后补登记迟到 PID、输出诊断并清理。
- 单 launcher 提交失败会在有界预算内逐项降级，不会让整组 Worker 静默返回 0。

### 进程身份租约与安全退场

- Master `ServiceRegistry` 是当前进程生命周期、slot、generation、lease、launch id 和 READY 的唯一运行时权威。所有终止、滚动替换和批次退场先从已认证 Registry 冻结 `pid + canonical process_name + launch_id + expected pname`。
- 冻结身份通过 `expected pname + PID` 确定性读取独立 `*-{pid}-pid.json` 租约，并叠加实时 OS 命令行、launch id 和 canonical pname 校验；不扫描目录，也不因 `pid_index/name_index` 暂时缺项放宽身份栅栏。
- `pid_index/name_index` 只承担 CLI 发现、兼容查询和可重建快照。索引存在时必须与独立租约一致；索引缺失不否定 Master 已知的完整租约，索引矛盾则 fail closed。
- 探测结果只允许 `running / exited / identity_mismatch / unknown`。只有 fresh probe 得到 `running + identity_match` 才能执行一次终止动作；其余状态绝不向当前 PID 发信号。
- Windows 索引发布禁止 `unlink(live target) -> rename`。同目录唯一临时文件写完并 flush 后先尝试原子 rename；目标替换不受支持时，在目标 `LOCK_EX` 内完整覆盖，读端 `LOCK_SH` 只能看到完整旧版或完整新版。
- Worker 终止默认不做进程树 kill；Direct loopback H1 回源端口是共享资源，任何单槽恢复与分批退场都禁止按端口杀进程。公网端口只属于项目托管 Nginx；端口占用诊断必须分别报告实际 owner。

## 运行拓扑平台边界

- Windows `auto` 固定使用 `worker_ports + stream_select` Direct；Linux `auto` 优先 `reuseport + event`，在 `sockets`/`SO_REUSEPORT` 不可用时回退 `shared_fd + event`；macOS `auto` 使用 `shared_fd + event`。三者共用 REGISTER→WARMING→READY、policy digest 与分批重载契约；业务 Worker 的 READY v3 必须证明首页 Process FPC 已热，并提交绕过 FPC 的动态首渲染回执。动态目标默认是发布性能门禁而非存活门禁；显式开启 strict 开关时才会因超标拒绝 READY。初启与标准分批 replacement 使用同一契约。
- Windows UNC 项目根目录使用有界冷启动兼容预算：首页 READY 单次 60 秒、Orchestrator 默认基线 150 秒、绝对总启动最多 300 秒；Windows 本地盘仍为 30 秒/90 秒，POSIX 不变。预算只延长失败上限，不降低 4/4 Worker、首页 Process FPC HIT、policy digest 或 listener capability 门禁；环境/配置显式值始终优先。
- 业务 Worker 的每一次 READY 发送都经过进程注入的 before-ready guard：首次注册、Master ACK 超时重发、普通 TCP 自动重连和 Supervisor 自动重连都重新检查显式非 local 的 Worker credential store。门禁异常时不发送 READY、不保留 confirmed 状态；自动重连已建立的控制连接会关闭，以便数据库恢复后按重连节流再次尝试。Maintenance Worker 不使用该浏览器凭据链，因此不执行 credential store 检查。
- 拓扑/依赖预检发生在任何 Master/Worker 创建之前；普通启动只读探测，不下载、安装或编译。Linux 的 `auto` 优先验证并选择 `reuseport` Direct，能力不可用时回退 `shared_fd`；macOS 选择 `shared_fd`，Windows 选择 `worker_ports`。Direct 最终不满足 listener/event/policy 能力时明确失败，显式 Dispatcher 仍受支持。只有 `--install-deps` 分支允许准备 PHP 依赖并用新进程复验。普通 start/reload/restart 也绝不构建 Nginx；仅显式 `server:nginx:install` 在 Unix 上可能构建，且 PCRE2/rewrite 为硬依赖。HTTP/3 只读检查已安装 Nginx 模块与真实 QUIC 门禁，不调用 PHP FFI/native 构建链。
- macOS `worker_count=auto` 使用性能核数并受内存预算限制；启动与 Doctor/建议共用同一个 resolver，显式 `-c` 保持不变。
- 平台无关的 `direct` 是唯一 Direct 状态值；旧平台专属值会被拒绝。WLS SSL 固定关闭，Worker 只运行明文 H1。
- `worker_ssl`、stream TLS、EventBuffer TLS、ProtocolEdge、Caddy 与 Native Transport 都是不可达遗留代码，不是配置项或回退路径。
- Worker 通过 `--public-origin` 获得对外 scheme/authority；该 HTTPS `public_origin` 必须经 `ManagedNginxPublicOrigin::normalize()` 校验后，由 endpoint schema v4 穿过 `ServerInstanceManager` allowlist 和 `Start::runMasterOnly()` 配置恢复原样传递，缺失、非字符串或非 HTTPS 时在绑定端口前 fail closed，禁止从内部回源 `host/port` 重建；READY 首页预热与真实 HTTP/HTTPS FPC key 一致。托管 Nginx 有 `$http_host` 时原样转发，H3 空值时用 `$host:$server_port`，再由固定 trusted loopback 上的 `Host` 与 `X-Forwarded-Proto/Port` 重建公开 origin；loopback 不因此成为业务 whitelist。
- H1 fresh 分流门禁仅允许 Nginx loopback allowlist 保护的 `/_wls/` 位置传播精确 `Connection: close`；普通业务位置清空 upstream `Connection`，持续复用 Nginx Keep-Alive 池。
- ACME HTTP-01 固定由项目托管 Nginx 的 `/.well-known/acme-challenge/` location 转发到 Worker。Nginx-only Dispatcher 的 `httpsEnabled=false`，因此不会在普通明文 H1 upstream accept 上执行旧的 50ms inline ACME peek；Gateway/native/TLS 路径也不会被重新激活。
- POSIX shared-FD listener 仅承载 loopback 明文 H1；Worker accept 后直接进入 H1 parser，不导出 TLS stream。
- Windows 11 ARM/x64 仿真环境的同规模 H2 2000 请求 A/B 证明了 ACME accept-path 修正：修正前 2000/2000、663.15 QPS、P95 166.699ms；修正后 2000/2000、971.18 QPS、P95 103.55ms，即 QPS +46.4%、P95 -37.9%。该证据只归因“跳过每条 Nginx 明文回源连接的 50ms peek”，不用于推导跨平台绝对性能。

## 平台验收边界

- Windows：必须在原生 Windows 做 2/4/8/16 Worker cold/warm 多轮，核对 PowerShell 返回 PID、IPC REGISTER PID、helper TTL/临时文件回收和 Defender 下 p95；macOS/模拟单元结果不代替该门禁。
- macOS：核对 batch PID = Worker `getmypid()` = REGISTER PID；launcher 退出后 PPID 重托管可接受，但不得残留 `php -r`/shell；用 `lsof -p {worker_pid}` 确认只有 Direct loopback H1 listener FD 3 可从 Master 继承，control/lock 和其它 listen FD 不得泄漏。
- Linux：必须独立 CI/实机重复 PID/PPID/残留进程检查，并用 `/proc/{worker_pid}/fd` 确认 reuseport 默认不继承 Master listener，shared_fd 回退只继承经校验的 listener，control/lock 与其它 FD 隔离；另验证 fresh H1 分布、loopback H1、SO_REUSEPORT 门禁和容器 subreaper。公网 TLS 另对项目托管 Nginx 验证。

## Worker 重载约束

- Worker 数达到阈值后默认三批，`worker_reload_min_ready=auto` 默认保留约三分之二 READY 容量。
- 每批 DRAIN 前按实时 Registry 再校验容量；不足时拒绝摘批。
- 普通 reload 只有在 maintenance/standby 容量已确认时才可整池切换；显式 `server:reload -f` 是用户接受短暂停机的强制契约。Windows `worker_ports` 与 POSIX `shared_fd` Direct 都使用标准安全分批；显式 Dispatcher 兼容模式可按其路由快照契约整池切换。
- 每批先统一置 DRAINING 并发布一次摘批快照，批内全部 READY 后再发布一次加回快照。
- `shared_fd` Direct 每批先停止目标 Worker 接新连接，等待已分派请求与回源 Keep-Alive 有界排空，再启动同批 replacement 并验证 policy、共享 listener、runtime 和首页 Process FPC。排空下限是托管 Nginx `upstream_keepalive_timeout_sec` + 5 秒，默认为 10 秒；不创建独立 accept backlog，避免退役 backlog 产生 RST。不存在 WLS Native active config ACK。
- H1 Worker 的 DRAIN 先停止 loopback accept，并完成已分派请求与待写响应。维护模式 ACK 先立即应用策略，再等待 active request/Fiber 与 response output 清空；空闲 preconnect、partial HTTP 和 slowloris 不得阻塞 ACK。
- 所有 drain/exit 等待使用总 deadline；到期后报告仍在途的具体阶段，不能用无界等待或静默关闭掩盖长尾。

## 请求边界失败隔离

- 每个 WLS 请求的 Session flush、response snapshot、namespace snapshot、trace、`StateManager`、module resetter、globals、`Context::leave()` 与 phase release 分别执行；任一步失败不得跳过后续可执行清理，最后按 stage/capability 聚合报错。
- 普通、异常、SSE 和取消请求共用同一 finally 契约；取消挂起 Fiber 前必须先恢复目标 Fiber 投影，再向 Fiber 抛入 `RequestExitException` 以展开 Runtime/Worker finally，不得直接丢弃 Scope bucket。
- 目标 Fiber 恢复只切换 superglobals、SSE/URL scratch 与已注册请求静态投影；`Context`、`HeaderCollector` 和 ObjectManager request bucket 由各自 Fiber WeakMap 保留，不覆盖主循环状态。
- reset/cleanup、Fiber resume/capture 或 post-response cleanup 任一失败，Worker 都会设置一次性 `drain-after-response` 原因。当前连接强制 `Connection: close`，普通 H1 Worker 消费该原因后立即关闭 listener、停止接单并向 Master 报告 quarantine 退出原因；不允许仅记日志后继续复用。
- module request state 必须位于当前 `RequestContext`/Fiber；进程级配置缓存、指标和共享连接池不得伪装成请求态被 peer Fiber 清空。
- 普通 HTTP 请求 Fiber 由 Worker scheduler 负责首次调度；`AsyncBizAdapters::dispatch()` 的完整框架分发边界不得再次主动 `yield`。协作式挂起只放在显式 I/O、SSE 或 deadline-aware 等待点，避免冷请求在回调前二次入队。

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
- `app/code/Weline/Server/Service/Runtime/HttpProtocolSelection.php`
- `app/code/Weline/Server/Service/Edge/Nginx/ManagedNginxService.php`
- `app/code/Weline/Server/Service/Edge/Nginx/ManagedNginxConfigWriter.php`
- `app/code/Weline/Server/Service/Edge/Nginx/ManagedNginxProcessManager.php`
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

- 启动图里，`Start.php` 负责“参数固化、锁、WLS 回源快照、项目托管 Nginx 配置事务与公网 live gate”；`MasterProcess` 负责“控制面启动与主循环”；`ServiceOrchestrator` 负责“子服务并发启动、READY 验收和运行期调度”。
- 关闭图里，CLI `Stop.php` 既是停机发起方，也是最终兜底清理方；真正的统一停机协议在 `ServiceOrchestrator::stopAll()` 中完成。
