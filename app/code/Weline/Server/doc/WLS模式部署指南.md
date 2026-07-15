# WLS 模式部署指南

WLS（Weline Server）是框架内置的常驻内存 HTTP 服务器，不依赖 Nginx/PHP-FPM 即可对外提供 Web 服务。**默认监听 80/443 直连省去 Nginx**，也可 `-p 9981` 等自定义端口。本文说明如何在 WLS 模式下部署及接入域名。

## 1. 模式说明

| 模式 | 说明 |
|------|------|
| **正常模式** | Nginx 监听 80/443，按 `server_name` 转发到 PHP-FPM 或 WLS；域名在 Nginx 与后台均需配置。 |
| **WLS 直接对外（推荐）** | WLS 监听 **80（HTTP）/ 443（HTTPS）**，无需外置 Nginx；当前默认公开协议为 HTTP/1.1，HTTPS 支持 TLS 1.2/1.3。Linux/macOS 拓扑为 direct（无 WLS Dispatcher），Windows 默认 Dispatcher。 |
| **WLS 自定义端口** | 使用 `-p 9981` 等监听高端口，适合与 Nginx 反代或开发环境。 |

接入含义：**请求能到达 WLS 端口** + **在后台为该网站配置域名**。

## 2. 环境配置

在 `app/etc/env.php` 中配置 `server` 或 `servers`（多实例）：

```php
'server' => [
    'host' => '127.0.0.1',   // 默认仅本机；明确对外直连时才使用 0.0.0.0
    'port' => 443,           // 监听端口（默认 80/443；改 9981 等可自定义）
    'worker_count' => 'auto', // 自动按平台 CPU 拓扑和内存预算计算；也可显式指定 4、8 等
    'mode' => 'io',          // io | cpu
],
'wls' => [
    'runtime' => [
        'topology' => 'auto', // auto | direct | dispatcher
    ],
    'http' => [
        'protocols' => ['h1'],
        'preferred' => 'h1',
        'protocol_edge' => 'disabled', // caddy 仅为用户显式选择的兼容模式
        'tls_session_resumption' => true,
        'alt_svc' => false,
    ],
],
// 多实例示例
'servers' => [
    'api' => [
        'host' => '0.0.0.0',
        'port' => 9001,
        'worker_count' => 4,
    ],
],
```

- **默认端口**：不配置 `port` 时，HTTP 用 80、HTTPS 用 443，直连省去 Nginx。
- 配置优先级：**命令行参数 > env.servers[实例名] > env.server > 默认值**。
- 拓扑的唯一长期配置是 `wls.runtime.topology=auto|direct|dispatcher`，优先级为 **CLI > 实例配置 > `wls.runtime.topology` > `auto`**。旧 `wls.topology`、`gateway.traffic_mode`、`direct_reuse_port`、`dispatcher_enabled`、`master_mode=linux-direct` 只做一版兼容读取；多个旧字段互相冲突时直接拒绝，不猜测意图。`independent` 只能被识别并明确拒绝，不再是可启动值。

## 3. 启动与停止

```bash
# 启动默认实例（默认监听 80/443，HTTPS 用 443，省去 Nginx）
php bin/w server:start

# 改用自定义端口（如 9981）
php bin/w server:start -p 9981

# 指定地址与端口
php bin/w server:start --host 0.0.0.0 -p 443

# Linux/macOS 显式使用 Dispatcher 对照/兼容
php bin/w server:start --dispatcher

# Linux/macOS 显式 direct（auto 已默认选择）
php bin/w server:start --direct

# 启动命名实例（使用 env.servers.api）
php bin/w server:start api -p 9001

# 查看状态（含 Master PID、Worker 状态）
php bin/w server:status

# 停止
php bin/w server:stop
# 或指定实例：php bin/w server:stop api
```

- **Linux/Mac 监听 80/443**：需 root 或 setcap，或使用 Nginx 反代到高端口（如 9981）。
- **Windows**：无特权端口限制，可直接监听 80/443。
- **拓扑限制**：所有平台都在启动前拒绝 `--no-dispatcher/independent`；Windows 额外拒绝 `--direct`，不会因 Worker 数为 1 而例外。
- **自动 Worker 数**：显式 `-c/worker_count` 始终优先。macOS 默认使用 Apple Silicon 性能核数（`hw.perflevel0.physicalcpu`，不可用时回退物理核/逻辑核），避免把效率核当作等价热 Worker；Linux `io` 默认逻辑核数的 2 倍、`cpu` 默认逻辑核数；Windows 使用平台稳定策略。所有平台再受 512 MB/Worker 的总内存预算和实现上限约束。

### 3.0 启动依赖预检

`server:start` 在创建 Master/Worker 之前先按唯一拓扑事实求出 `requested/effective topology` 和 `HttpProtocolSelection`，再决定必需依赖。Linux/macOS Direct 必须安装并验证 `sockets + ext-event`；HTTPS 在所有拓扑下还必须验证当前 PHP/OpenSSL。默认协议是 h1，不下载或构建 Go 工具链。任一拓扑、事件循环或 TLS 必需能力失败都 fail-closed；显式 Dispatcher 的 `ext-event` 仍是可选优化。

- macOS：Homebrew 和 PECL 以当前用户运行，不使用 `sudo`。
- Linux：按 apt/dnf/yum/apk/pacman/Docker 当前平台策略执行；非交互启动不会等待 sudo 密码。
- Windows：HTTPS 缺失 OpenSSL 时只允许当前 PHP ABI 的扩展安装器修复；PHP 8.4 缺少 `event` 时只下载官方 PECL event 3.1.4 的精确架构/TS-NTS/VS17 包，固定 SHA-256 校验后原子发布 `php_event.dll + pthreadVC2.dll`，并由同一 `PHP_BINARY` 新子进程实际加载验证。没有固定可信包或验证失败时保持 `Dispatcher + stream/select`，不会猜测或跨版本加载二进制。
- HTTP 协议：默认由 WLS Worker 直接提供 HTTP/1.1。仓库内 Go 协议边缘和自动构建链已删除；用户显式配置 `protocol_edge=caddy` 时才进入兼容适配链，默认不会安装外置 Web 服务器。
- 依赖安装、reuseport probe 或 direct 策略 capability 失败时默认停止启动；Linux/macOS 如需继续必须由运维明确改用 `--dispatcher`，不静默降级。

### 3.1 架构说明（多 Master / 多 Worker / 流量分发）

**多 Master（多实例）**：已支持。每个 `server:start [实例名]` 对应一个独立实例，每个实例有：

- **1 个 Master 进程**：不承载业务 HTTP/HTTPS，负责启动、健康监督、路由发布、平滑重载和异常 Worker 恢复。
- **N 个 Worker 进程**：常驻内存处理框架请求；实际监听方式由 Dispatcher/direct 拓扑决定。
- **0 或 1 个 Dispatcher**：Windows `auto` 必定启用；Linux/macOS `auto` 不启动，只有显式 `--dispatcher` 才启用。
- **0 或 1 个兼容 Protocol Edge**：默认不启用；仅在用户显式选择兼容模式时存在，且不得执行 Worker 安全与缓存策略。
- **0 或 1 个 HTTP 重定向进程**：仅需要 HTTP 到 HTTPS 重定向时存在，不承载业务请求。

实例之间通过实例名区分（如 `default`、`api`），实例信息存于 `var/server/instances/{实例名}.json`，多实例互不干扰。

**Master 不做数据面流量分发**。默认 h1 配置下，Linux 使用 SO_REUSEPORT、macOS 由 Master 传递共享 listener FD 给 Worker；Windows/显式 Dispatcher 由 Dispatcher 选择 READY Worker 并透传原始 TCP/TLS 字节。只有用户显式启用兼容边缘时，Worker 才使用带实例认证的私有 loopback 入口。

**总结**：

| 能力 | 是否实现 | 说明 |
|------|----------|------|
| 多 Master（多实例） | ✅ 已实现 | 多实例 = 多份 Master+Workers，按实例名区分 |
| Master 监控/重启 Worker | ✅ 已实现 | 健康检查、异常重启、重载信号 |
| Master 监听 443 并分发给 Worker | ❌ 不是数据面职责 | macOS Master 只 bind/传递 listener FD，不 accept/转发；Windows Dispatcher 或 Linux 内核负责分流 |
| 单端口 443 多 Worker 负载均衡 | ✅ 已实现 | Windows 由 Dispatcher 分发；Linux 使用 SO_REUSEPORT；macOS 使用 Master 共享 listener FD |

如果现有架构由 Nginx/LB 统一入口，上游仍应连接一个 WLS direct 公开端口或 Dispatcher 公开端口；不应绕过 READY/策略门禁直连独立 Worker 端口。

### 3.2 多 Worker 端口与单口压测

`auto` 根据平台选择不同数据面。不同拓扑不能只看峰值 QPS，还要对照业务路径的 p95/p99、Worker 分布、TLS 和故障恢复。

- **Dispatcher**：Windows 默认；Linux/macOS 显式 `--dispatcher`。使用公开主端口压测，用 Worker ID 响应头或运行时指标核对分布。
- **direct**：Linux/macOS 默认，含义是“不启动 WLS Dispatcher”。当前 h1/HTTPS 都由 Worker 共享公开端口并执行完整策略；`event_buffer + direct` 仍在启动预检时拒绝。
- **macOS Direct HTTPS**：共享 FD 只用于事件就绪通知；Worker 通过原生 socket accept 后导出为可 TLS 的 stream。TLS 完成后的首个请求有 200ms 有界首读泵送，用于衔接 OpenSSL 用户态缓冲与 ext-event 内核 FD 通知；普通 keep-alive 不进入该扫描。`Darwin + shared_fd + event` 下每轮最多 accept 1 个连接；成功 accept 后在繁忙期使用 100us 冷却并保持 20ms busy 状态，持续空闲时使用 5ms 冷却，使串行 fresh TLS 也能覆盖全部 Worker。listener 的 Event watcher 始终注册，冷却只抑制本轮 accept，不能销毁/重建 watcher；该不变量避免 Worker 仍显示 READY 却永久不再接收连接。该机制是共享队列上的竞争退让，不是严格轮转；rolling surge 的新旧代 Worker 仍共同竞争同一 listener。
- **direct 维护态**：不启动 Maintenance Worker；Master 将维护 epoch 下发给全部业务 Worker，只有全量 ACK 后才提交状态。业务 Worker 至少跨过一个 transport loop 再 ACK，等待已分派请求和待写响应，但不等待空闲 preconnect、未完成握手或 partial slowloris；EventBuffer 中已经完整的流水线请求会按有界预算经过同一 WorkerPolicyKernel 后再 ACK。
- **independent**：只保留旧值识别，因尚不具备完整 READY/策略保证而在预检阶段拒绝启动；请在 direct 与 Dispatcher 中选择。

实例 endpoint 从新启动开始写入 schema v3。`runtime_selection` 完整保留 requested/effective topology、选择来源、OS、event loop、SSL engine、listener mode、策略兼容性和 reason codes；根级的 topology/listener/supervisor 字段用于 CLI 兼容和观测。Master 重入必须确认投影完全一致，任意冲突都拒绝启动；旧 schema v2 只读兼容，Master 不会在缺少完整 `runtime_selection` 时把它改写为 v3。

`server:status <name>` 直接展示这份已持久化的实际选择、listener/event/SSL 与 policy digest；schema v3 投影冲突时只报校验失败，不做兼容推断。压测推荐显式使用 `php bin/w server:benchmark --instance <name>`。仅使用 `-p` 时，命令只在 host/port 唯一匹配运行 endpoint 时归因运行时元数据；多匹配或零匹配的报告明确标记为 unattributed。当本机存在多个运行实例时，无参数自动选择会被拒绝，防止误压生产实例。

三种入口都在 Worker 执行同一 mandatory request guard、Static/FPC 和 Router/Controller 管线。缺少后台 Key 的 `/admin/login` 会在缓存和 Router 前返回 404，必须访问 `/{backend_key}/admin/login`。完整执行顺序见 [WLS 安全与规则配置推演](WLS安全与规则配置推演.md)。

### 3.3 进程安全

框架通过 `--name=weline-xxx` 标识所有服务器进程（Worker、HTTP 重定向）。端口被占用时：
- 如果是**框架进程**（`-r` 强制重启时）：可自动杀死并重启
- 如果是**非框架进程**：不予杀死，提示用户手动处理，避免误杀系统服务

### 3.4 Hybrid Supervisor 控制面

Hybrid 是现行子进程控制面兼容层，无需单独配置密钥：Master 把当前实例 token 注入 Supervisor，子进程用它对 HELLO 身份做 HMAC-SHA256 签名。token 不作为明文字段出现在 HELLO 中。运维不应关闭这一认证，也不应手工复用其它实例的 token/channel。

READY 时序是硬门禁：

1. Supervisor 验证 HELLO 的 instance/channel、签名、role/slot 和当前 lease，再分配 `lease_id + generation`。
2. Hybrid 先把 REGISTER 交给 Master/Orchestrator；只有当前会话仍存活才会进入 `masterAccepted`。
3. Worker 上报 READY 后，Supervisor 只保存 `pendingReady`，不会先把槽位变为 READY。
4. Master 验证 readiness protocol v2/capabilities、topology、policy digest、warmup、首页 Process FPC、动态首页非 FPC 回执和 listener capabilities；返回与 `msg_id + slot + lease + generation` 一致的 ACK 后，Supervisor 才提交本地 READY 并回复 Worker。动态首渲染目标仍是发布性能门禁：冷链第一次有效渲染若超过 `target_ms`，Worker 会在同一个有界预热事务内立即复验已经填充的进程缓存，并以复验结果作为 READY 性能证明；只有尝试预算耗尽后仍慢，才记录最终 `ready:slow`，默认不会把一次主机抖动放大为重启风暴。如需把目标恢复为启动硬门禁，显式开启 `wls.worker.dynamic_warmup_block_on_target_ms`。Maintenance Worker 不要求业务动态首渲染证明。

这意味着 `server:status` 中的 READY 不是“子进程自报启动完成”，而是 Master 已验收当前精确 lease 的结果。旧连接、旧 generation 或不匹配 ACK 都不能改变新槽位状态。

`php bin/w server:status <instance>` 会在每个业务 Worker 下显示动态首渲染的 `ready`、`elapsed/target`、HTTP status、body、attempts、FPC 和实际 host/path。业务 Worker 缺少 v2/能力/回执、动态证明反而命中 FPC、HTTP 失败或正文为空时不会 READY；慢的第一次有效渲染会继续使用剩余 attempt 复验热链，只有最后一次仍满足 `elapsed >= target` 才显示 `ready:slow`。缺字段不会静默按旧版放行。若 Master IPC 在查询窗口内繁忙，CLI 只读回退会把持久化 `worker_ready` 事件裁剪到当前 canonical `1..count`，历史 surge Worker 不会让健康的 4/4 实例误报为 4/5。

控制面是有界的：HELLO 必须在5秒内完成，已注册会话60秒无活动会被关闭，子进程每5秒发送心跳。心跳在事件循环每轮构造控制 socket 写集合前调度，不依赖 Master 先发来可读消息；当前不可写时只进入有界缓冲，不同步等待。单会话读写缓冲各2 MiB；Hybrid 转发队列最多1024条/2 MiB，单条最大512 KiB。生命周期、策略 ACK 和路由 ACK 等关键消息遇到背压会关闭源会话并交由 Master 收敛；普通 log/telemetry 采用可损、批量上报，不得因输出洪峰拖断生命周期通道。

如果控制面拒绝或断开会话，先按原因处理，不要绕过 READY 门禁或把缓冲改成无上限：

| reason/现象 | 含义 | 处理 |
|---|---|---|
| `hello_identity_rejected` | 签名/时钟/nonce、role/slot 形状或 live lease 冲突 | 核对实例参数、Master/Worker 时钟和是否存在同槽位残留会话 |
| `channel_mismatch` / `instance_mismatch` | 子进程连入了错误的实例通道 | 核对实例名、Supervisor endpoint 和启动参数，不复用其它实例的 endpoint |
| `session_lease_mismatch` | 消息的 slot/lease/generation 不再属于当前会话 | 让子进程正常重连获取新 lease，不重放旧心跳或 READY |
| `master_register_pending` / `ready_already_pending` | REGISTER 尚未被 Master 接受，或当前 lease 已有一个待验收 READY | 查 Master/Orchestrator 日志和能力门禁，不重复洪泛 READY |
| `critical_control_backpressure` | 关键消息超过 Hybrid 转发预算 | 查找上报洪峰或 Master 消费停滞，先恢复消费者，不盲目扩容队列 |
| 约60秒后无故掉线 | 已注册会话心跳没有推进 | 检查子进程主循环是否持续调用 `hasPendingWrites()`/写集合调度，以及控制 socket 是否长时间堵塞；不要把心跳只挂在可读事件上 |

## 4. 域名接入

### 4.1 在后台绑定域名（必做）

框架根据 **HTTP Host + 完整 URL** 匹配网站，域名必须在后台配置：

1. 进入 **网站管理**（Weline Websites）
2. 编辑对应网站，在 **域名/地址** 中添加要使用的域名，例如：
   - `www.example.com`
   - `example.com`
   - 直连 WLS 端口时可用：`example.com:9981`
3. 保存后，该域名的 URL 会参与框架的网站解析，请求带此 Host 即识别为该网站。

WLS 的 Host Guard 位于网站解析之前。启动时的最终 `host/public_host/ssl_domain` 会编译进当前实例的严格 Host 策略；实例另有域名时，应把它加入该实例启动配置或安全规则 `allowed_hosts.hosts`，然后重新执行 `server:policy:compile/publish` 或重启。仅在网站后台绑定域名不会自动放宽 Worker 的安全入口。`localhost`、loopback 与托管本地域单级子域继续按开发环境契约可用，但任意非托管 Host 不会因此放行。

与是否使用 Nginx 无关，WLS 与 Nginx 模式均需在后台配置域名。

### 4.2 让请求到达 WLS 端口

**方式 A：WLS 直连 80/443（推荐，省去 Nginx）**

- 默认启动即监听 80/443（HTTPS 时用 443）：`php bin/w server:start`
- 外网访问时：`php bin/w server:start --host 0.0.0.0`
- Linux/Mac 需 root 或 setcap：`sudo php bin/w server:start` 或 `sudo setcap cap_net_bind_service=+ep $(which php)`
- 访问：`https://www.example.com/`（无端口）

**方式 B：WLS 监听高端口（开发/与 Nginx 配合）**

- 启动：`php bin/w server:start --host 0.0.0.0 -p 9981`
- 域名解析：公网 DNS A 记录指到服务器 IP；本机在 `hosts` 中添加 `127.0.0.1 www.example.com`
- 访问：`https://www.example.com:9981/` 或由 Nginx 反代（见方式 C）

**方式 C：Nginx 反向代理到 WLS 高端口**

Nginx 监听 80/443，按域名反代到 WLS（例如 9981）：

```nginx
server {
    listen 80;
    server_name www.example.com example.com;
    location / {
        proxy_pass http://127.0.0.1:9981;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

用户访问 `http://www.example.com/`（无端口），Nginx 转发到 WLS，Host 仍为 `www.example.com`。HTTPS 在 Nginx 侧配置 SSL 即可。

这种部署中 WLS 看到的 socket peer 是 Nginx/Caddy，loopback 不会自动成为白名单。要让 Worker 使用 `X-Real-IP` / `X-Forwarded-For`，必须把反代的实际源 CIDR 显式加入 `wls.accept_gate.trusted_proxy_cidrs`；该配置只授权采信转发头，不绕过 Origin Token、ban、限流或攻击规则。只有 `wls.accept_gate.whitelist_cidrs` 或安全规则中显式配置的 `ip_whitelist.ips` 可跳过这些规则。新安装默认 whitelist 为空。

```php
'wls' => [
    'accept_gate' => [
        // 仅列出真实反代源；示例为同机 Nginx/Caddy。
        'trusted_proxy_cidrs' => ['127.0.0.1/32', '::1/128'],
        // 默认保持空；只在明确接受完整规则跳过时增加。
        'whitelist_cidrs' => [],
    ],
],
```

**方式 D：多 Worker 单公开端口（现行生产模式）**

`-c 4` 不再意味着对外暴露 443/444/445/446：

- Linux/macOS direct：Worker 直接共享一个公开端口；Linux 使用 SO_REUSEPORT，macOS 使用共享 listener FD。
- Windows/Dispatcher：Dispatcher 监听公开端口并把原始 TCP/TLS 字节透传到 READY Worker，Worker 后端端口不对客户端公开。
- 用户显式启用兼容边缘时：兼容入口独占公开端口，Worker 私有端口只接受 loopback + 实例 token。

Nginx/Caddy 只需要代理到 WLS 的一个公开端口。如需跨机高可用，应由外部 LB 在多个 WLS 实例之间均衡，而不是直连某个 Worker 的内部端口。

## 5. HTTPS / SSL

- **默认**：WLS 启动时自动启用 HTTPS，无证书时对本地/内网（127.0.0.1、localhost 等）**自动签发自签证书**，证书按域名存放在 `app/etc/ssl/{域名}/`。
- **本地 CA 复用**：本地/内网证书优先使用 Weline 本地 CA 签发。项目内 CA 可用时会同步到用户级全局目录；新项目缺少 CA 时会先从该全局目录恢复，避免每个项目重复生成并导入新的本地 CA。可用 `wls.ssl.local_ca_dir` 指定全局 CA 目录。系统信任库只有 CA 证书但没有 `rootCA.key` 时，不能作为签发材料复用。
- **已有证书**：自动检测 `app/etc/ssl/` 下证书，或使用 `--ssl-cert` / `--ssl-key` 指定。
- **生产**：可继续用 Nginx 终结 HTTPS（80/443）反代到 WLS 高端口，或直连 WLS 80/443（需 root/setcap）。
- **HTTP 重定向到 HTTPS**：**Master 默认启用**。HTTPS 启用时，Master 会**自动启动一个独立的 HTTP 进程**（不计入 Worker 数），监听 80 端口，将 HTTP 请求 301 重定向到 HTTPS。

### 5.1 当前 HTTP 协议能力

当前默认生产配置只启用 `h1`。仓库内 Go 协议边缘与自动构建链已删除，因此 WLS 不再宣称默认支持 HTTP/2/3，也不发布 `Alt-Svc`。TLS 1.2/1.3、HTTP/1.1 keep-alive、Worker 常驻缓存和统一策略内核继续由 WLS 自身提供。

```php
'wls' => [
    'http' => [
        'protocols' => ['h1'],
        'preferred' => 'h1',
        'protocol_edge' => 'disabled',
        'tls_session_resumption' => true,
        'alt_svc' => false,
    ],
],
```

- HTTP/1.1 keep-alive 默认启用；错误响应关闭连接，请求数、空闲时间与连接年龄均必须有界。
- TLS session resumption 只按当前 PHP/OpenSSL 实际探测结果报告；没有跨 Worker 共享 ticket 证据时不得宣称完整重启后必然复用。
- `server:status` 显示实际协议顺序、preferred、edge 与 TLS 配置。配置 h2/h3 但没有可验证适配器时，启动在创建子进程前失败。
- 新的 HTTP/2/3 实现必须是 WLS 自有 Transport Adapter，直接接入同一 WorkerPolicyKernel，不能复制 Host、后台 Key、Origin Token、安全、限流、Static/FPC 规则。
- 显式兼容边缘使用的私有 keep-alive transport 仍要求 loopback + 实例 token；该 transport 信任不等于业务白名单。

验证示例：

```bash
curl -k --http1.1 https://example.com/
curl -k --http2 https://example.com/
curl -k --http3-only https://example.com/
php bin/w server:status <instance>
```

### 5.1.1 翻译词典按请求模块加载

WLS 不在启动时预装全部语言或全部模块词典。Worker 首次处理某条路由时，从 Request 已登记的 Controller、Layout、Query 等模块建立范围：先查最终译文的进程内词哈希；模块词典 L1 缺失时才查 `phrase` Shared Memory 的模块 CSV 快照；Shared miss 才解析本模块 CSV 并回填。

若模块 CSV 没有该词，Worker 不会加载全 locale 数据，而是继续执行 `Worker 单词 L1 -> Shared Memory 单词记录 -> md5(word + locale) 精确数据库查询`。这兼容没有 `source_module` 的历史词条，同时保证共享内存帧和每次数据库结果都只包含一个词。

- 普通请求结束不会清 Worker 翻译 L1；同一个词的后续查找是进程内哈希读取。
- Worker 模块 L1 命中时不会查询 Shared Memory、模块元数据或文件版本；只有 cache epoch 清空本地变量后的首次读取才计算版本并回源。
- 翻译发布会清理 `phrase/i18n` cache epoch，使所有 Worker 在下一次访问时获取新模块快照或单词记录。
- 后台发布记录应带正确的 `source_module`，便于模块归属、维护和导出；旧的无归属记录由精确单词索引兼容，不会全局批量加载。
- 若日志出现 `SessionProtocol frame_too_large`，先检查是否又把全 locale 词典写入 Shared Memory；正确实现只允许模块 CSV 快照或单词级小记录。

### 5.2 TLS 1.3 与密钥交换 profile

`app/etc/env.php` 使用以下配置；示例默认值同见 `app/etc/env.sample.php`：

```php
'wls' => [
    'ssl' => [
        'protocols' => ['tls1.2', 'tls1.3'],
        'key_exchange_profile' => 'performance', // performance|system
    ],
],
```

- `performance` 是默认值。WLS 在派生 Master/Worker 子进程前生成 `var/server/tls/openssl-performance-{hash}.cnf`，其中只有 `Groups = X25519:P-256`；不强制 TLS 1.3 ciphersuite。
- 当前公开 TLS 由 Worker 的 PHP/OpenSSL 处理；`protocols` 与 `key_exchange_profile` 会进入实例、Endpoint、Master IPC 和 benchmark 元数据。实例级 `wls.servers.<name>.ssl` 也会进入 Master 的不可变运行上下文，不能与 Worker 真实能力分叉。
- 若运维已在 WLS 启动环境设置 `OPENSSL_CONF`，WLS 完整保留该配置，不生成覆盖。这是最高优先级的进程级 OpenSSL 策略入口。
- `system` 用于显式保留系统 OpenSSL group 策略，适合运维要求混合后量子组或统一系统密码策略的环境。
- `protocols` 未配置时默认为 `['tls1.2', 'tls1.3']`；一旦显式配置，空字符串、空数组、非字符串元素或 TLS 1.0/1.1/未知值都在创建子进程前被拒绝，不再回退到 `TLS_SERVER`。
- 启用 TLS 1.3 但当前 PHP/OpenSSL 不暴露 TLS 1.3 server stream 常量时，`server:start` 在创建 Master/Worker 前拒绝启动；不会静默降级。

当前 macOS/4 Direct Worker 首页 fresh-TLS 正式 5 轮中位数证据中，`performance` 相对 OpenSSL 3.5+ 系统选出的 `X25519MLKEM768` 混合组：并发 32 QPS +11.69%、p95 +2.37%；并发 128 QPS +9.77%、p95 -9.93%，全部 0 错误。完整数据见 [WLS 运行时架构](WLS架构图.md#37-tls-13-进程性能策略与实测证据)。强制 AES128 ciphersuite 的试验因并发 32 QPS 回退约 11.2% 已拒绝；现行 profile 不设置套件顺序。

滚动重载必须同时验证长连接复用和 fresh handshake。2026-07-11 当前 macOS Direct 实例在 reload 中分别完成 100,000 次 TLS 1.3 keep-alive（9,562.78 QPS，p95 6.230ms）与 100,000 次 fresh TLS 1.3（1,776.06 QPS，p95 21.725ms），两组均 0 错误；reload 后当前 4 Worker 的 20,000 次 fresh TLS 分布 `max/min=1.055`。跨代压测会同时记录旧代、surge、新代 PID，不能把不同存活窗口的总计数误判为当前代倾斜。

生命周期索引与 maintenance ACK 收口后的最终复核为：100,000 次 keep-alive 10,313.37 QPS、p95 18.669ms；20,000 次稳态 fresh TLS 1,869.76 QPS、p95 21.201ms、`max/min=1.036`；与 rolling reload 重叠的 20,000 次 fresh TLS 0 错误、p95 28.330ms、max 105.782ms。reload 后只保留 1 Master + 4 canonical Worker，PID 索引无短命 launcher 或 surge 残留。空闲 TLS preconnect 存在时 maintenance enable/disable 仍能全量 ACK，避免浏览器预连接拖住 restart。

2026-07-14 当前 macOS 专用实例补充验证了持久票据与新协议边缘配置：同一张 TLS 1.3 票据跨两轮 Worker upstream reload 及协议边缘完整停启均为 `Reused`。首页 Process FPC c32×2,500 为 10,736.43 QPS、p95 4.089ms；health c128×100,000 为 15,084.92 QPS、p95 11.960ms；fresh TLS c32×2,000 为 3,246.75 QPS、p95 11.055ms、`max/min=1.131`，全部 0 错误。另一次与 rolling reload 重叠的 100,000 请求为 0 错误、14,065.69 QPS、p95 13.670ms、max 50.546ms，收敛后仍为 4/4 canonical Worker。

2026-07-15 当前 Native 代码代再次验证：旧 TLS 1.3 ticket 跨完整 Master + Protocol Edge 重启仍为 `Reused`；macOS Direct 的 HTTP/2 health c128×1,000,000 为 0 错误、15,720.26 QPS、p95 13.675ms、p99 18.969ms、max 228.168ms，HTTP/3 health c128×100,000 为 0 错误、12,845.60 QPS。首页 Process FPC 的 HTTP/2 / HTTP/3 各 100,000 请求均 0 错误。Windows 11 ARM64 实机已完成 `auto -> dispatcher`、h1/h2/h3、TLS 1.3 X25519 与 session resume；空闲 Windows 的 4/16 Worker 长稳仍应作为发布环境门禁单独执行，不能使用受其它长期任务满载的 VM 数字替代。

```bash
# 默认即 HTTPS（自动证书或 app/etc/ssl/）
php bin/w server:start

# 使用已有证书
php bin/w server:start --ssl-cert /path/to/cert.pem --ssl-key /path/to/key.pem

# 基准命令可将协议最小/最大版本同时锁定为 TLS 1.3
php bin/w server:benchmark -p 9443 --ssl --tls-version 1.3 --no-keepalive
```

## 6. 常用命令速查

| 命令 | 说明 |
|------|------|
| `php bin/w server:start [name]` | 启动 WLS（默认 80/443，HTTPS 用 443） |
| `php bin/w server:start -p 9981` | 改用端口 9981（可省 Nginx 时用 80/443） |
| `php bin/w server:start --host 0.0.0.0 -p 443` | 指定地址与端口 |
| `php bin/w server:start -c 8` | 指定 Worker 数量 |
| `php bin/w server:start` | 启动（Master 默认启用，监控并自动重启 Worker） |
| `php bin/w server:start -d` | 守护进程模式 |
| `php bin/w server:start --cli` | 使用 PHP 内置 CLI 服务器（开发，无 HTTPS） |
| `php bin/w server:status [name]` | 查看实例、Master/Worker、实际 RuntimeSelection、listener/event/SSL 与 policy digest |
| `php bin/w server:benchmark --instance <name>` | 精确压测指定实例，报告安全归因 schema v3 运行时元数据 |
| `php bin/w server:stop [name]` | 停止 WLS（含 Master） |
| `php bin/w server:start -r` | 平滑重启 |

## 7. 故障排查

| 现象 | 可能原因 | 处理 |
|------|----------|------|
| 访问域名 404 或非预期网站 | 后台未配置该域名或 URL 不匹配 | 在网站管理中为对应网站添加该域名，注意协议与端口 |
| 无法访问 | 防火墙未放行端口或 WLS 未监听 0.0.0.0 | 放行 80/443 或所用端口；外网访问时使用 `--host 0.0.0.0` |
| 80/443 绑定失败（Linux/Mac） | 特权端口需 root 或 setcap | `sudo php bin/w server:start` 或 `sudo setcap cap_net_bind_service=+ep $(which php)`，或改用 `-p 9981` + Nginx 反代 |
| 端口被占用 | 已有进程占用该端口 | 使用 `server:stop` 停止对应实例，或 `-p 9981` 等改端口 |
| Nginx 502 | WLS 未启动或端口错误 | 执行 `php bin/w server:status` 确认 WLS 监听端口，与 Nginx `proxy_pass` 一致 |
| 子进程停在 STARTING 且 PID=0 | 批量 launcher 创建后 `pcntl_exec` 失败或子进程在注册前退出 | 查看对应 `var/process/<process>.log`；launcher 会记录脱敏后的 errno、PHP 可执行文件名和脚本名，不再静默等待到 READY 超时 |
| Worker 异常退出 | 进程崩溃 | Master 默认启用，会自动重启异常 Worker |
| TLS 1.3 启动前失败 | 当前 PHP/OpenSSL 构建不支持 TLS 1.3 stream server | 切换到与当前平台匹配且暴露 `STREAM_CRYPTO_METHOD_TLSv1_3_SERVER` 的 PHP/OpenSSL，不删除协议门禁兜底 |
| 显式配置 HTTP/2/3 后启动失败 | 当前默认 WLS Transport Adapter 尚未提供 h2/h3，或显式兼容适配器未通过能力验证 | 改回 `protocols=['h1']` 与 `protocol_edge=disabled`；不要把配置值误报为实际协议能力 |
| 显式兼容 Edge 健康检查超时 | Dispatcher 未识别 loopback + 实例 token 健康探针，或 token/header 被中间层改写 | 核对兼容 Edge 与 Dispatcher 属于同一实例和 digest；其它明文请求仍应跳转 HTTPS或被拒绝 |
| h1/h2 正常但 h3 不可达 | 同一公开端口的 UDP 未放行，或上游 NAT/LB 不转发 QUIC | 放行并转发该端口的 UDP；用支持 HTTP/3 的 curl/浏览器复测 |
| 请求 `performance` 但状态显示 `external` | 启动环境已存在 `OPENSSL_CONF` | 这是运维配置优先的预期行为；核对该文件的 group/cipher 策略，不要期待 WLS 覆盖 |

---

**版本：** 2.0.0-dev
**更新时间：** 2026-07-15
**状态：** 当前发布能力为 HTTP/1.1 + TLS 1.2/1.3；POSIX `auto -> direct`、Windows `auto -> dispatcher`、首页 FPC、后台 Key 与策略一致性仍需在删除协议边缘后的代码代重新验证。旧 Native h2/h3 与百万请求数字属于已撤销实现，不能作为当前门禁结果；Windows 空闲环境的 4/16 Worker 冷启动和长稳仍需独立复验。

动态路径预热默认只包含首页 `/`。业务模块需要预热商品、分类或账户页面时，应显式配置 `wls.worker.dynamic_critical_paths` / `wls.worker.dynamic_hot_paths`，或通过 `Weline_Server::dispatcher::warmup_paths` 发布真实路由；Server 不内置任何演示业务 URL。
