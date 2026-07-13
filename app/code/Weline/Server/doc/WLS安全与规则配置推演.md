# WLS 安全与规则配置推演

> 状态：WLS 统一策略契约。2026-07-13。总体拓扑见 [WLS 运行时架构](WLS架构图.md)。

## 1. 核心不变量

- 直连不等于绕过规则。Linux/macOS direct 和 Windows/Dispatcher 必须得到相同的 Host、后台 Key、Origin Token、封禁、请求限流、URI/Header/Body 检查、维护模式、Static 与 FPC 结果。
- Worker 永远执行 L7 请求策略和缓存策略。Dispatcher 不再是 HTTP 规则的唯一执行点。
- Static/FPC 只能在 mandatory request guard 通过后命中；缓存不能绕过认证、后台路由、封禁或限流。
- 策略只在启动/发布时编译。请求热路径不读 JSON/env.php，不反射模块类，不调用 CLI Command，不轮询规则文件。

## 2. 两种拓扑的策略位置

| 能力 | Dispatcher 拓扑 | Direct 拓扑 |
|---|---|---|
| IP/CIDR 、连接数/速率、slowloris | Dispatcher L4 Gate | Worker AcceptGate |
| Worker 选择、后端连接、背压、failover | Dispatcher | 不存在 |
| TLS/SNI/证书/握手失败 | Worker | Worker |
| Host、后台 Key、Origin Token | Worker | Worker |
| URI/Header/Body/扫描/请求限流 | Worker | Worker |
| Static/FPC/Router/Controller | Worker | Worker |
| 维护模式 | Dispatcher 可切维护池，Worker 仍校验 epoch | Worker 本地直接响应 |

Dispatcher 拓扑使用 PROXY Protocol v2 把经过认证的客户端 peer 元数据传给 Worker。Direct 使用公开 socket 的真实 peer。只有 socket peer 命中编译后的 trusted proxy CIDR，Worker 才从 `X-Forwarded-For` 右向左剥离已声明的受信 hop，并选取最靠右的第一个非受信 IP。`CF-Connecting-IP`、`X-Real-IP`、`Weline-Real-IP` 等客户端可注入的单值头不作为身份权威；XFF 缺失、畸形或全为受信 hop 时 fail-close 到 transport peer。

Loopback 只是 transport peer，不是隐式白名单或 Origin 凭据。POSIX direct 绑定 `127.0.0.1` 并由 Nginx/Caddy 反代时，未配置 `trusted_proxy_cidrs` 就不采信转发头，且 loopback peer 仍完整执行 Origin Token、ban、限流和攻击规则。只有运维在 `ip_whitelist.ips` 或 `wls.accept_gate.whitelist_cidrs` 显式声明的 CIDR 才能跳过这些规则；`trusted_proxy_cidrs` 只授权解析客户端转发头，本身不授予白名单权限。

## 3. RuntimePolicyBundle

模块通过 Framework 的数据契约提交描述符，Server 运行时只消费编译结果，不引用模块 `Service/Model/Controller/Helper`。Provider 实现 `Weline\Framework\Runtime\Policy\RuntimePolicyProviderInterface::policies()`，并以 `runtime_policy_provider.<Vendor_Module>` 为 key 注册。`framework:compile` 生成 `generated/framework/runtime_policy_providers.php`；该文件是生成物，不得手工编辑。每条描述符至少包含：

- `id` / `priority` / `stage`
- `required_inputs`
- `matcher` / `action` / `state`
- `critical`
- `supported_topologies` / `capabilities`

常用 stage 为 `connection`、`tls`、`mandatory_request`、`cache`、`deep_request`、`response`。Bundle 是不可变 PHP array，位于：

```text
var/server/policy/{instance}/{sha256}.php
```

它不得包含 Closure、模块服务对象或热路径动态类名。当前状态只记录 active/staged/previous digest，不把可变配置混入 Bundle 文件。
进程通过 `RoutingPolicyRegistry::getActiveBundleArray()` / `getActiveDigest()` 读取已激活的内存快照，不在每个请求中 require Bundle Store。

`CACHE` stage 不是只供控制面展示。Worker 在启动及策略激活时把
`server.cache.static`、`server.cache.fpc` 的 `enabled` 与 `layer/layers`
编译成一个只读位图，并把该位图连同 policy digest 写入每个
`WorkerPolicyDecision`。HTTP、stream TLS、EventBuffer 都只读该快照：

- `static + process_l1` 才允许 Static L1 命中、canonical static 读取与 L1 发布；缺少或禁用该描述符时请求直接进入 Framework 路由。
- `fpc + process_l1` 才允许 Worker FPC fast path；未授权 `shared_l2` 时只查 Process L1，不能访问 Shared L2。
- Framework 当前 FPC pipeline 是“Shared L2 发布并装入 Process L1”的完整事务，所以只有两个 layer 都启用时才允许内部 FPC 查找/构建/发布；其它组合通过 request-scoped bypass 明确关闭 pipeline。
- 策略热切换先排空旧 application generation，再激活 Bundle；新请求的 digest 与 cache 位图来自同一次内存安装，不会逐请求扫描 descriptor、读取 Env/ObjectManager，也不会出现新 digest 执行旧缓存规则。

控制面命令：

```bash
php bin/w server:policy:check [instance]
php bin/w server:policy:compile [instance]
php bin/w server:policy:publish [instance]
php bin/w server:policy:status [instance]
php bin/w server:policy:rollback [instance]
```

`server:start` 在创建 Worker 前自动检查、编译和发布当前实例策略。策略热更新遵循：

### 3.1 实例 Host 编译上下文

Host Guard 是实例级策略，不是只读全局 `wls` 的宽松默认。`server:start` 在证书和公开域名解析完成后，把本次启动的最终 `host/public_host/ssl_domain` 作为只含数据的编译上下文，同时交给暂存注册表预检和最终策略激活；三者规范化后写进 `server.request.host_guard.allowed_hosts`，端口不参与 Host 名称比较。只要实例声明了其中任一 Host，Bundle 的 `host_policy_strict` 就必须为 `true`，未知的非托管 Host 在 Static/FPC/Router 前返回 403。

策略元数据只公开 `host_policy_source`、Host 数量和 `host_policy_context_digest`，不在 CLI 摘要中回显域名清单。`server:policy:check/compile/publish/rollback` 没有 Start 的内存配置时，只在控制面从 `var/server/config/{instance}.json` 与 endpoint 记录恢复这三个字段；Worker 请求热路不读取这些文件。Start 会持久化 `public_host`，使后续离线策略命令与首次启动使用同一语义。

staged Bundle 只有在完整编译 digest 与当前候选一致时才能被启动预检选中；发布指定旧 digest 或回滚时，至少必须保持相同的 Host context digest 且仍为 strict，否则控制面要求先重新编译。显式安全规则 `allowed_hosts.hosts` 可添加该实例的附加域名。现有产品契约仍允许 `localhost`、loopback Host 和托管本地域的单级子域；该例外不会把 `evil.example` 等任意非托管域名变成允许项。

策略校验会预编译 Worker 实际消费的 `malicious_patterns.patterns`、`bad_user_agents.patterns` 和 Body 恶意规则 PCRE。空值、非字符串、超过 1024 字节或无法编译的表达式会让 `server:policy:check` / compile / start 在控制面明确失败，不能进入 Worker 请求热路；Worker 仍保留无告警的防御性匹配边界，用于拒绝损坏的旧 Bundle。`ip_policy` 的 trusted-proxy/whitelist/deny 列表同样在控制面严格校验 IP 与十进制 CIDR 前缀范围，非法前缀不得通过整数强转变成 `/0`。

```text
POLICY_PREPARE -> PREPARED_ACK
-> POLICY_ACTIVATE -> ACTIVATED_ACK
-> POLICY_COMMIT -> COMMITTED_ACK
```

关键进程未 ACK、digest 不一致或拓扑缺少必需 capability 时，禁止激活；PREPARE/ACTIVATE 失败时全员回滚旧 digest。COMMIT 开始后不再局部回滚，未确认进程保持关门并重试 COMMIT，避免制造混合 digest。Worker 只有在 active policy digest 匹配、必需策略就绪且预热完成后才可 READY。

## 4. 唯一请求顺序

```text
AcceptGate
-> TLS/SNI
-> AuthoritativeHttpFramer（唯一字节边界）
-> MinimalRequestParser（语义只解析一次）
-> CanonicalClientIdentity
-> Host/Method/Header/Body 大小/Path 规范化
-> ACME/Health 专用门禁
-> BackendKeyGuard
-> OriginTokenGuard
-> 全局 Ban/请求限流/路径限流
-> Protected Path/URI/Header/UA/扫描规则
-> MaintenanceGate
-> Static L1
-> FPC Process L1
-> FPC Shared L2
-> Body 深度规则
-> Lazy Session
-> Router/Controller
-> ResponsePolicy
-> Cleanup
```

字节分帧与语义解析是两个明确边界。`worker_http_message.php` 的纯函数分帧器是 HTTP、stream TLS 和 EventBuffer TLS 共用的唯一 message-length 权威：返回 `incomplete/complete/error + consumed bytes`，每次只交付一条完整请求，并保留 keep-alive/pipeline 尾部给下一请求。WLS 不解码 request `Transfer-Encoding`，因此任何 TE（包括 `chunked`）、TE+Content-Length、冲突的重复 Content-Length、非十进制 Content-Length 都必须 `400 + Connection: close`；重复 CL 只有在所有数值完全相同时可接受。

“语义只解析一次”是数据契约，不是文档口号：`WorkerPolicyKernel` 只接受分帧器验证后且无尾部字节的单条请求，完成唯一一次请求行/Header 语义解析，产生不可变 `WorkerPolicyDecision` / Framework `RequestEnvelope`。该快照同时携带 method、HTTP protocol、已规范化 path、原始 target/query、小写 Header map、body、canonical client IP、trusted-proxy 结论和 policy digest。Static/FPC 与动态路由必须消费同一份 Decision；动态路由 `WlsRequest::fromEnvelope()` 直接水合，不再调用 `fromRaw()` 或重复扫描 HTTP 头。

`WorkerStaticResponseL1::lookup()` 只接受该 Decision；GET/HEAD、`If-None-Match` / `If-Modified-Since`、HTTP/1.0/1.1 和 Connection close/keep-alive 都从快照判定。后续 `WorkerFullPageCacheFastPath` 也只消费该 Decision，并在 SSE/upgrade、`Cache-Control: no-cache/no-store/max-age=0`、`Pragma: no-cache` 和显式 warmup/bypass 时拒绝命中。HTTP 与 stream TLS 在 Static/FPC 命中后直接进入 transport 写缓冲收尾，EventBuffer 使用显式 hit 结果跳过响应头重扫描；三者都不再在普通热命中调用冷 static handler、文件系统、ObjectManager、同步 telemetry 或 post-response queue。`cache_clear(cache_epoch)` 在 ACK 前清空每个 Worker 的 Static L1 与 FPC Process L1。

fast-path 性能面板记录必须由 `X-WLS-Performance-Diagnostics: 1` 或 `X-Weline-Performance-Diagnostics: 1` 显式开启，且仍需通过 DeveloperAccessPolicy。DEV 模式本身不再导致所有 Static/FPC 请求生成随机 request-id 并写 TraceStore。`X-WLS-Benchmark-Worker: 1` 是独立的平台 benchmark 归因契约，仅返回 Worker ID/port/PID，不开启面板 trace，也不绕过 Origin Token、ban、限流或攻击规则。业务路径高压必须使用专用测试实例，并仅对实际压测源 IP 显式配置 whitelist CIDR；不得用裸 Header 伪造安全绕过。

若 Framework runtime 未完成初始化，HTTP/HTTPS/EventBuffer 三种 Worker 统一返回通用 `500 Internal Server Error` 与 `request_id`/`X-Weline-Request-Id`。公开 body 不得包含异常消息、文件路径、连接信息或 `$runtimeError`；完整内部错误只在 WLS 日志中按同一 `request_id` 关联。

后台路由必须以当前 `backend_key` 开头。裸 `/admin/login`、`/admin/login/post` 等缺少 Key 的路径必须在 FPC/Router 前返回 404；正确入口是 `/{backend_key}/admin/login`。Area Route Manifest 还必须支持 Key 后只有货币、只有语言，以及货币/语言任意顺序的组合。Framework Router 仍是最终权威，Worker manifest 只做无副作用的快速拒绝。

## 5. 全局限流、封禁与日志

- 多 Worker 的实例级计数使用 Shared Memory `incr/cas`，不能把限额乘以 Worker 数。
- Worker 可原子预占 1/8/16/32 个 token 的小 lease；低阈值规则固定为 1，预占不能超过剩余配额。
- SharedState 短断时使用 `global_limit / ready_workers` 的保守本地配额，禁止无限放行，也禁止请求线程秒级等待。
- Ban 使用共享 TTL/CAS，进程本地只缓存已封禁的正向结果。
- 配额耗尽与攻击判定必须分离：实例级、路径级 token bucket 超额只返回 `429`，不得升级为共享 IP Ban。共享 Ban 只用于高置信攻击规则、受保护路径和达到路径扫描阈值等明确安全事件，避免反向代理或本机共享出口下“一次误判、全站 403”。
- 默认 `bad_user_agents` 只保留 `sqlmap`、`nikto`、`nmap`、`masscan` 等高置信扫描器；空 UA、`curl`、`python-requests` 可能来自健康检查、部署与运维命令，不得仅凭 UA 默认封禁。
- 路径扫描计数只统计具有扫描价值的路径。普通 `GET/HEAD` 浏览器静态扩展（CSS、JS、图片、字体、音视频、WASM、source map）不占用 unique-path 预算；点文件、无扩展路径和服务端可执行扩展仍参与计数，并继续执行 protected-path 与恶意规则。
- `server:security:unblock` 由 Master 同时广播给当前实例的业务 Worker、维护 Worker 与 Dispatcher。各进程会同步清除请求策略内核、Connection AcceptGate、共享状态和进程内分布式 Ban。`--clear-all` 只按当前实例 hash 前缀删除共享 Ban，不得清空其他 WLS 实例。
- Dispatcher 不再构建 `AttackDetector`、不读取或轮询攻击规则文件，也不重复维护 whitelist/CIDR 索引。其 accept 热路的唯一安全事实源是当前已激活 Bundle 构建的 `ConnectionAcceptGatePool`；URI/Header/Body 攻击规则只在 WorkerPolicyKernel 执行。
- 攻击日志进入进程 ring buffer 后批量提交；请求热路径不直接 ORM、写 JSON 或同步 IPC。
- TLS 握手和握手后的首请求都必须有总 deadline。macOS shared-FD 路径只对新握手连接执行 200ms 有界首读泵送，不允许将兼容补偿扩展为普通 keep-alive 的全连接扫描或秒级等待。
- `server:wls_error_scan` 使用增量 cursor 流式读取：单次最多 32 MiB、单文件最多 8 MiB、总 deadline 250 ms、单块 64 KiB。未结束的超长行只持久化 8 KiB 摘要、模式尾部和匹配状态；轮转以 inode/device/size 识别，多个日志按 cursor 轮转起点公平续扫。首次发现既有日志直接从 EOF 建 cursor，不再为了统计行号扫描整个历史文件。

## 6. 验证要点

- 对同一请求语料对比 Dispatcher/direct 的状态码、Header、Body、封禁、限流和缓存来源，结果必须一致。
- 三种 transport 必须一致拒绝 TE+CL、任何未支持 TE/chunked 和冲突的重复 CL；同一连接一次写入两条完整请求时，必须按顺序返回两个响应，不得把尾部当作第一请求 body 或静默丢弃。
- 伪造 XFF/CF Header 不能覆盖非受信 peer；Dispatcher 的 PROXY v2 元数据必须通过实例认证。
- loopback peer 未显式命中 whitelist CIDR 时，必须与公网 peer 一样执行 Origin Token、ban、限流和攻击规则；trusted proxy 不等于 whitelist。
- 对已声明实例 Host 的 Bundle，`host_policy_strict=true`、context digest 与最终 Start 配置一致；正确 Host 可通过，任意非托管 Host 必须在缓存和 Router 前 403。staged/rollback 的旧 Host context 不得激活。
- 16 Worker 全局限流仍为一份实例总额。
- 普通 `curl` 与浏览器 UA 必须通过；连续超过实例/路径额度时响应为 `429`，随后不得变成 `shared_ban`。高置信扫描器应立即 403，并使同一客户端后续请求命中共享 Ban。
- 同一客户端访问超过路径扫描阈值数量的不同静态资源后，首页仍必须可访问；非静态扫描路径超过阈值仍应触发 Ban。
- Static/FPC 命中时不创建 Session、Router 或 Controller，但 mandatory guard 必须已执行。
- 禁用 `server.cache.static` 或 `server.cache.fpc` 后，三种 transport 都不得继续命中或发布对应缓存；重新启用只能由新的 active digest 生效。
- 普通、TLS stream、EventBuffer 的动态请求在策略通过后均由同一 `RequestEnvelope` 创建 `WlsRequest`；Worker 热路不应再出现 `WlsRequest::fromRaw()`。
- 注入含密码/文件路径的 runtime 初始化错误时，公开 500 只能看到通用消息和 `request_id`，日志可用该 ID 定位内部细节。
- 策略发布失败后旧 active digest 不变；任一请求观测到混合 digest 都是发布失败。
- 可在 Dispatcher 中执行但 direct 无法履行的策略（例如按 SNI 切到不同进程池）必须让 direct 启动明确失败，不得静默忽略。
