# WLS 模式部署指南

WLS（Weline Server）是框架内置的常驻内存 HTTP 服务器。**生产默认使用边缘适配器 `wls.edge.adapter=nginx`**：由 Nginx 对外终结 TLS/HTTP2/HTTP3，WLS 以明文 HTTP/1.1 回源（`--no-ssl`）。自研 HTTP/2 与可选 native HTTP/3 **代码保留**，显式切换 `wls.edge.adapter=wls` 后可继续启用。

## 1. 模式说明

| 模式 | 说明 |
|------|------|
| **WLS 托管 Nginx** | `wls.edge.adapter=nginx` 且 `managed=true`（或 `auto` 且未检测到宿主机 Nginx）。WLS 安装/启停本项目 `extend/server/nginx`，多项目互不干扰。 |
| **宿主机 Nginx（不启动托管实例）** | `managed=false`，或默认 `managed=auto` 且检测到宿主机/宝塔 Nginx（或启动加 `--no-nginx`）。**WLS 只处理业务请求**（建议 `--no-ssl` 高端口）；TLS/H2/H3 与反代由用户已有系统 Nginx 配置。 |
| **WLS 自研边缘** | `wls.edge.adapter=wls`。恢复 WLS SSL Worker 的 HTTP/2 与可选 HTTP/3。适合无 Nginx 的本机/实验环境。 |

接入含义：**请求能到达 WLS 端口** + **在后台为该网站配置域名**。

### 1.1 边缘适配器配置

```php
'wls' => [
    'edge' => [
        'adapter' => 'nginx',           // nginx（默认）| wls；整段可省略
        'reload_command' => '',         // 宿主机 Nginx 续签后 reload，例：'systemctl reload nginx'
        'nginx' => [
            'managed' => 'auto',        // auto：检测宿主机 Nginx；true 强制托管；false 强制宿主机
            'auto_start' => true,       // 仅 managed 解析为 true；也可用 CLI --no-nginx 跳过单次启动
            'listen_http' => null,      // 仅托管模式：null → 8080 + projectPortOffset
            'listen_https' => null,     // 仅托管模式：null → 8443 + projectPortOffset
            'install_root' => 'extend/server/nginx',
            'runtime_root' => 'var/server/nginx',
            // 最佳性能默认
            'edge_cache' => true,
            'edge_cache_ttl_sec' => 60,
            'edge_cache_max_size_mb' => 1024,
            'edge_cache_keys_zone_mb' => 128,
            'gzip' => true,
            'gzip_comp_level' => 2,
            'upstream_keepalive' => 256,
            'worker_connections' => 32768,
        ],
    ],
],
```

### 1.2 宿主机已有 Nginx（推荐关闭托管）

当宿主机已经运行系统 Nginx（或宝塔/LNMP 等），**不要再启 WLS 托管实例**，避免抢端口、双边缘。默认 `managed=auto` 会检测 `/www/server/nginx/sbin/nginx`、`/usr/sbin/nginx` 与 `PATH` 中的 `nginx`；检测到即自动走宿主机模式。也可显式关闭：

```php
'wls' => [
    'edge' => [
        'adapter' => 'nginx',
        'nginx' => [
            'managed' => false,   // 关键：不下载、不启动 extend/server/nginx
            'auto_start' => false,
        ],
        // 可选：证书仍由 WLS 写入 app/etc/ssl/{domain}/，续签后 reload 宿主机 Nginx
        'reload_command' => 'systemctl reload nginx', // 或 'nginx -s reload'
    ],
],
```

```bash
# WLS 只跑业务（明文回源）
php bin/w server:start -p 9981 --host 127.0.0.1 --no-ssl

# 单次跳过托管启动（即便 managed=true）
php bin/w server:start -p 9981 --host 127.0.0.1 --no-ssl --no-nginx
```

宿主机 Nginx 反代示例（用户自管 conf，指向本项目 WLS 端口）：

```nginx
server {
    listen 443 ssl http2;
    server_name www.example.com;
    ssl_certificate     /path/to/project/app/etc/ssl/www.example.com/fullchain.pem;
    ssl_certificate_key /path/to/project/app/etc/ssl/www.example.com/privkey.pem;

    location ^~ /.well-known/acme-challenge/ {
        proxy_pass http://127.0.0.1:9981;
        proxy_set_header Host $host;
    }
    location / {
        proxy_pass http://127.0.0.1:9981;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

要点：

- WLS **不**改宿主机 `/etc/nginx`，不抢 80/443（除非你自己把 WLS 绑上去）。
- `server:stop` 在 `managed=false` 时**不会**去停系统 Nginx。
- `server:nginx:install/start` 仅用于托管模式；宿主机模式无需执行。
- 若启用可信代理，把宿主机反代网段列入 `trusted_proxy_cidrs`。

### 1.3 本项目托管 Nginx（多项目互不干扰）

按平台自动安装到 `extend/server/nginx`（需 `managed=true`）：

| 平台 | 方式 | 依赖 |
|------|------|------|
| macOS | 官方源码编译 | Xcode CLT；建议 `brew install openssl@3 pcre2` |
| Linux | 官方源码编译 | `gcc/make` + OpenSSL/PCRE 头文件（发行版 `*-devel`/`*-dev`） |
| Windows | 官方 `nginx.zip` 解压 | PHP `ZipArchive`，或 PowerShell / tar |

```bash
# 首次缺失时会按平台自动安装到 extend/server/nginx（也可显式：server:nginx:install / --install-nginx）
php bin/w server:nginx:install   # 可选：提前安装或强制重装

# 启动 WLS 明文回源；READY 后自动写 conf 并启动本项目 Nginx（缺失则自动安装）
php bin/w server:start -p 9981 --host 127.0.0.1 --no-ssl
# 或一次显式安装：php bin/w server:start -p 9981 --host 127.0.0.1 --no-ssl --install-nginx

php bin/w server:nginx:status
php bin/w server:stop   # managed=true 时会先停本项目托管 Nginx
```

对外访问端口为 `8080/8443 + projectPortOffset`（可用 env 覆盖）。安装目录 `extend/server/nginx`、运行态 `var/server/nginx` 均按项目 BP 隔离。托管 conf 默认：`upstream keepalive`、`access_log off`、较大 `worker_connections`；改 conf 后对已运行实例会 `reload`。

> 性能提示：内置 `server:benchmark` 是 PHP 客户端，测出的 QPS 常低于真实边缘能力。对比边缘吞吐建议用 `ab -k` / `wrk` / `bombardier`。托管 Nginx **默认最佳性能配置**：匿名 GET 边缘微缓存（`edge_cache=true`，TTL 60s，有 Cookie 跳过）、gzip（comp_level=2）、upstream keepalive=256、worker_connections=32768、access_log off。健康检查 `/_wls/` 不走边缘缓存。修改 `proxy_cache_path` 的 zone 大小后需 `server:nginx:stop` 再 `start`（reload 无法重建共享内存区）。
>
> **实测参考（匿名 GET 边缘 HIT，100 万请求 0 失败）**：macOS `ab -k` ~64117 QPS；Windows 11 ARM VM + 托管 nginx 1.26.3 + `bombardier -c100` ~14845 QPS（Windows 无 `reuseport`、`worker_processes 1`，VM 内绝对值低于 macOS 属预期）。Windows 首次启动前需 `ensureRuntimeDirectories()` 创建 `var/server/nginx/temp/*`（`client_body_temp` 等），conf 内已写绝对 temp 路径。

切换自研：在 `env.php` 设置 `wls.edge.adapter=wls` 后按既有 HTTPS 启动流程（可省略 `--no-ssl`）。`server:doctor` 会报告适配器、托管/宿主机模式与原生协议状态。

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
    'edge' => [
        'adapter' => 'nginx',
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

- **默认端口**：不配置 `port` 时，HTTP 用 80、HTTPS 用 443；生产推荐 Nginx 终结后 WLS 使用高端口 + `--no-ssl`。
- 配置优先级：**命令行参数 > env.servers[实例名] > env.server > 默认值**。
- 拓扑的唯一配置是 `wls.runtime.topology=auto|direct|dispatcher`，优先级为 **CLI > 实例配置 > `wls.runtime.topology` > `auto`**。2.0 不读取旧配置键或旧模式值；发现未知/已删除输入时在创建任何 Master/Worker 前明确失败。

## 3. 启动与停止

```bash
# 生产推荐：明文回源（配合 Nginx 边缘）
php bin/w server:start -p 9981 --host 127.0.0.1 --no-ssl

# 自研边缘（wls.edge.adapter=wls）：默认监听 80/443 HTTPS
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
- **拓扑限制**：命令行只提供 `--direct` 与 `--dispatcher`；Windows 拒绝 `--direct`，不会因 Worker 数为 1 而例外。没有受支持平台驱动的系统在启动前失败。
- **自动 Worker 数**：显式 `-c/worker_count` 始终优先。macOS 默认使用 Apple Silicon 性能核数（`hw.perflevel0.physicalcpu`，不可用时回退物理核/逻辑核），避免把效率核当作等价热 Worker；Linux `io` 默认逻辑核数的 2 倍、`cpu` 默认逻辑核数；Windows 使用平台稳定策略。所有平台再受 512 MB/Worker 的总内存预算和实现上限约束。

### 3.0 启动依赖预检

`server:start` 在创建 Master/Worker 之前先按唯一拓扑事实求出 `requested/effective topology`，再只读验证当前 PHP 的必需能力。普通启动不会下载、安装、编译或修改 php.ini。Linux/macOS Direct 必须预装并验证 `sockets + ext-event`，Direct HTTPS 另外验证 `OpenSSL`；可选 HTTP/3 所需的 FFI 不属于 TCP Direct 硬依赖，但要启用 H3，当前 PHP 必须预装并启用 FFI。任一 Direct 必需能力失败即 fail-closed；显式 Dispatcher 的 `ext-event` 是可选优化，缺失时保持 Dispatcher 并使用有界 `stream_select`。Linux 对实际监听地址执行双 socket SO_REUSEPORT 真实 accept 分布 probe；macOS 执行 Master listener FD 继承和两个消费进程的真实 accept probe。

只有本次显式传入 `--install-deps`，`server:start` 才允许调用 `env:install`，并在创建任何 WLS 子进程前使用同一个 `PHP_BINARY` 新进程复验：

- macOS：可能以当前用户运行 Homebrew 和 PECL，不使用 `sudo`。
- Linux：可能调用 apt/dnf/yum/apk/pacman；非交互路径只允许 `sudo -n`，不会等待密码。
- Windows：可能修改当前 PHP 配置；只启用与当前 PHP ABI 匹配且能被新子进程实际加载的现有 DLL，不下载未验证二进制。
- `--no-auto-deps` 仅作为旧脚本兼容选项；普通启动默认已经禁止安装，且该选项不能与 `--install-deps` 同用。
- 依赖准备、reuseport probe 或 Direct 策略 capability 失败时停止启动；Linux/macOS 如需继续必须由运维明确改用 `--dispatcher`，不静默改写拓扑。

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
- **其他系统**：没有受支持的平台驱动就停止启动；不会借用 Dispatcher 作为兼容回退。

实例 endpoint 只写 schema v4。嵌套 `runtime_selection` 完整保留 requested/effective topology、选择来源、OS、event loop、SSL engine、listener mode、策略兼容性和 reason codes；根级 topology/listener/event/SSL 投影已删除。Master 重入只接受完整 v4，旧 schema、缺失字段或未知字段都在绑定端口前拒绝，不推导、不补写。

`server:status <name>` 直接展示 endpoint schema v4 中持久化的选择、listener/event/SSL 与 policy digest。压测推荐显式使用 `php bin/w server:benchmark --instance <name>`；它先校验 endpoint v4，再把归因结果写入独立的 **Benchmark report schema v4**。仅使用 `-p` 时，命令只在 host/port 唯一匹配运行 endpoint 时归因运行时元数据；多匹配或零匹配的报告明确标记为 unattributed。当本机存在多个运行实例时，无参数自动选择会被拒绝，防止误压生产实例。

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

`php bin/w server:status <instance>` 会为每个业务 Worker 分开显示两项证据：`首页预热` 使用 `warmup_state + homepage_fpc` 展示 Process FPC 的 hit/source/status/reason；`动态首渲染测量` 展示 ready、elapsed/target、HTTP status、body、attempts、FPC 和实际 host/path，尚未采集时明确显示“未记录”。两项不能混为一谈：Worker READY 的首页缓存门禁以 `homepage_fpc.hit=true + source=process` 为准，动态首渲染则是独立发布性能证据。业务 Worker 缺少协议能力、首页 Process FPC、动态回执、HTTP 成功或正文证明时不会 READY；`elapsed >= target` 默认记为 `ready:slow` 供发布门禁和观测使用，不作为 Worker 存活失败。若 Master IPC 在查询窗口内繁忙，CLI 只读回退会把持久化 `worker_ready` 事件裁剪到当前 canonical `1..count`，历史 surge Worker 不会让健康的 4/4 实例误报为 4/5。

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

**方式 A：WLS 直连 80/443（无统一入口时）**

- 默认启动即监听 80/443（HTTPS 时用 443）：`php bin/w server:start`
- 外网访问时：`php bin/w server:start --host 0.0.0.0`
- Linux/Mac 需 root 或 setcap：`sudo php bin/w server:start` 或 `sudo setcap cap_net_bind_service=+ep $(which php)`
- 访问：`https://www.example.com/`（无端口）

**方式 B：WLS 监听高端口（开发/与 Nginx 配合）**

- 启动：`php bin/w server:start --host 0.0.0.0 -p 9981`
- 域名解析：公网 DNS A 记录指到服务器 IP；本机在 `hosts` 中添加 `127.0.0.1 www.example.com`
- 访问：`https://www.example.com:9981/` 或由 Nginx 反代（见方式 C）

**方式 C：宿主机 Nginx 反向代理（机器上已有 Nginx/面板时）**

机器上**已有**系统 Nginx（或宝塔/LNMP）时：设 `wls.edge.nginx.managed=false`，WLS **只跑业务回源**，不下载、不启停 `extend/server/nginx`。完整说明与 HTTPS 样例见 [§1.2](#12-宿主机已有-nginx推荐关闭托管)。

```php
'wls' => [
    'edge' => [
        'adapter' => 'nginx',
        'nginx' => ['managed' => false],
        'reload_command' => 'systemctl reload nginx', // 可选
    ],
],
```

```bash
php bin/w server:start -p 9981 --host 127.0.0.1 --no-ssl
```

用宿主机 Nginx 做前置统一入口：监听 80/443，按域名反代到 WLS（例如 9981）：

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

用户访问 `http://www.example.com/`（无端口），Nginx 转发到 WLS，Host 仍为 `www.example.com`。HTTPS 在 Nginx 侧配置 SSL 即可（证书可指向 WLS 签发的 `app/etc/ssl/{domain}/`）。若机器没有系统 Nginx、希望 WLS 自管边缘，改用 `managed=true`（默认），见 [§1.3](#13-本项目托管-nginx多项目互不干扰)。

这种部署中 WLS 看到的 socket peer 是 Nginx，loopback 不会自动成为白名单。要让 Worker 使用 `X-Real-IP` / `X-Forwarded-For`，必须把反代的实际源 CIDR 显式加入 `wls.accept_gate.trusted_proxy_cidrs`；该配置只授权采信转发头，不绕过 Origin Token、ban、限流或攻击规则。只有 `wls.accept_gate.whitelist_cidrs` 或安全规则中显式配置的 `ip_whitelist.ips` 可跳过这些规则。新安装默认 whitelist 为空。

```php
'wls' => [
    'accept_gate' => [
        // 仅列出真实反代源；示例为同机 Nginx。
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

Nginx 不是 WLS 运行时硬依赖：`managed=false` 时由宿主机自管；`managed=true` 时由 WLS 托管本项目实例。反代时只代理到 WLS 的一个公开端口。如需跨机高可用，应由外部 LB 在多个 WLS 实例之间均衡，而不是直连某个 Worker 的内部端口。

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

`server:benchmark` 在首批请求加入后立即显示真实进度，运行中约每 0.5 秒按实际完成/失败数刷新完成数、活动请求句柄、已发送数、耗时和实时 QPS；进度不会按时间模拟，最终完成会强制显示 100%。报告的 `qps`/“完成 QPS”按所有已完成请求计算，`success_qps`/“成功 QPS”单独表示成功吞吐，`latency_ms` 覆盖成功、HTTP 错误和 curl 错误的请求耗时。自动唯一选择、显式 `--instance` 和唯一端口归因都会先检查 Master、全部 Worker 及登记服务的健康状态；只要实例未就绪就直接拒绝，不再进入压测。`fsockopen` 端口可达不等于 Worker 已就绪。

### 5.2 HTTP 协议协商与连接复用

#### Linux Direct 的 HTTP/3 激活门禁

- Linux/macOS 的 TCP 拓扑仍由 `auto` 选择 Direct；Windows 固定 Dispatcher。
- Linux HTTP/3 使用 `reuseport-ebpf`，Worker 在 READY 前完成 native runtime、策略与预热，但 UDP 路由仅为 `staged`。
- Master 通过 `ack_ready` 发出带完整 lease/slot/epoch/generation 身份的 `ready_phase=activate + http3_route`；Worker 才把 listener socket 原子发布到 eBPF map。Master 收到并验证 `HTTP3_ROUTE_ACTIVATED` 后才发送 `final` READY。统一总预算为 3 秒，Worker 每 0.5 秒幂等重发 READY；相同激活回执只补发 final ACK，不会断开健康 Worker。
- 直连扩容时 canonical Worker 的 route count 必须覆盖新 slot；rolling reload 的 surge Worker 固定 `hold`，不会抢占 canonical HTTP/3 slot。
- `Alt-Svc: h3` 只在所需 canonical Worker 全部 `active` 后广播；staged、held、draining 或 identity 不一致均 fail closed，不会静默退化成随机 UDP 分发。
- macOS 继续使用单 Owner Datagram Router；Windows 继续 Dispatcher TCP 透传。三种平台不会共享 Linux eBPF 的启动参数。

WLS 的**生产默认**边缘适配器是 `nginx`：对外 TLS/HTTP2/HTTP3 由 Nginx 终结，WLS 数据面为 HTTP/1.1。自研 HTTP/2 / native HTTP/3 **代码保留**；仅当 `wls.edge.adapter=wls` 时，生产 TCP 才默认协商 HTTP/2（不满足时回退 HTTP/1.1），HTTP/3 由 WLS 自带的 ngtcp2/nghttp3/OpenSSL 原生 QUIC Adapter 提供（不进入 TCP ALPN 默认顺序；需 UDP listener、Adapter 自检、策略、Worker 和预热全部 READY，或浏览器依据 WLS 发布的 `Alt-Svc` 升级）。HTTP/2 Adapter 宣告 `SETTINGS_MAX_CONCURRENT_STREAMS=64`，每个 Stream 使用独立 Fiber；Worker 用默认 32（可配范围 16–32）的解析准入预算、512KiB 待写高水位、pending-response gate 和连接轮转做有界背压。只有“一个 TLS 连接上多个同时在途 Stream 全部完成”的运行门禁才算多路复用证据。

HTTP/3 原生组件只能由运维显式执行 `php bin/w server:http3:build` 准备（且 `wls.edge.adapter=wls`），且当前 PHP 必须已经预装并启用 FFI；该命令不会安装 FFI 或修改 `ffi.enable`。Linux 路径可下载固定摘要的 OpenSSL 3.5.7、ngtcp2 1.23.0、nghttp3 1.16.0 与 curl 8.21.0 源码，并把依赖封装进 WLS 私有 PIC-static transport。macOS 路径编译 WLS 传输桥接库后，把全部非系统 dylib 稳定快照到 owner-only、内容寻址目录，改写 install name/edge 为 `@loader_path` 私有闭包，删除所有 `LC_RPATH`，逐个 ad-hoc codesign，再核对哈希、owner、权限、签名、依赖图和 dyld 实际 loaded-image 绝对路径。两条路径都先发布不可用于生产的 immutable candidate；只有新 PHP 子进程精确固定 fingerprint/SHA 并通过真实 QUIC/TLS、Ticket 轮换与独立 TLS-context Session 恢复自检后，才在锁内原子更新 active。普通 `server:start` 只读选择身份和当前运行证据仍匹配的 manifest，不调用编译器或自检；组件缺失、证据失效、边缘适配器为 nginx，或 Linux eBPF 路由权限不足时不发布 `Alt-Svc`，Direct TCP 在自研模式下继续使用 HTTP/2 并自动回退 HTTP/1.1。`linux_pic_static_dependency_bundle` 只有在当前进程确为匹配平台/架构的 Linux、manifest ready、`dependency_linkage=pic-static` 且当前版本化运行证据全部成立时才为 true；通用库可加载或 macOS 证据不能推导该 Linux readiness。

同一 HTTP/1.1 Keep-Alive 或 HTTP/2 TCP/TLS 连接会复用现有握手。跨新连接、跨 Worker 的 TLS 1.3 Session Ticket 恢复必须以第二次服务端握手的复用状态和服务端计数器为准；客户端写出 ticket/session 文件、配置 `session_ticket` 或 `session_id_context` 都不等于恢复成功。PHP 8.4 Stream TCP 的 OpenSSL `-sess_out/-sess_in` 复核仍只得到 `New, TLSv1.3`，因此该版本的 HTTP/2、HTTP/1.1 保持 `session_resumption_verified=false`。PHP 8.6 外部有状态缓存与原生 HTTP/3/QUIC 无状态 Ticket Key Ring 是两个独立数据面，证据不能互相继承。H3 ABI 2.9 使用 current/previous 共享 Ticket Key Ring、Worker 安装 ACK、`SSL_session_reused` 和 encrypt/decrypt 计数器，并统一了 Worker/Router selectable fd 的唯一所有权；0-RTT 明确关闭。

PHP 8.4 的 Stream SSL 服务端不暴露纯 PHP 外部 Session Cache 回调。PHP 8.6 新增 `session_new_cb/session_get_cb/session_remove_cb`、`session_id_context` 与 `Openssl\Session`；设置 `session_get_cb` 后的 TLS 1.3 Ticket 是携带 Session ID 的 stateful ticket，不是跨 Worker 共享的 stateless Ticket Key Ring。WLS 已实现这条纯 PHP TCP 路径：独立有界 RAM-only TLS 子存储、严格 fail-fast 的预连接客户端、SNI/证书/context 隔离、reload 连续性、sidecar 自愈与服务端复用计数。它不要求把 HTTP/1.1/HTTP/2 改写为原生 TCP 数据面，也不要求另行编译 WLS 原生协议组件；“纯 PHP”不代表 PHP/ext-openssl 二进制无需预先编译或安装。只有可选 HTTP/3 QUIC 组件需要显式执行 `server:http3:build`。能力默认关闭；显式启用必须使用暴露这些 API 的 PHP 8.6 运行时，不支持时在监听前拒绝。

Doctor 的 `tls_alpn.runtime_verified` 不由“stream context 接受 `alpn_protocols` 配置”推导；能力探针必须完成真实 `h2` 与 `http/1.1` TLS 握手。Doctor 分开报告 TCP 有状态缓存与 HTTP/3 无状态 Ticket Key Ring，并进一步拆分 TCP 的持久功能证据、配置匹配、活动实例 scope、固定恢复握手 P95 ≤ 50ms、性能基线、PHP 发布通道、稳定三平台矩阵和 production-ready。无实例 verifier receipt 的全局快照必须显示 scope“未评估”，不能误报“不匹配”或 active。不得把 H3 证据映射到 TCP，也不得把 cross-context 自检升级为实时路由结论。`server:benchmark --http-version auto` 在 HTTPS cURL 与 WLS 原生 QUIC 数据面都已验证时优先 HTTP/3，否则默认 HTTP/2 并允许回退 HTTP/1.1；显式指定 `1.1/2/3` 时执行严格协议匹配。

Windows 的生产拓扑由 WLS 自身提供 HTTPS：公开端口由 Dispatcher 接收 TCP 字节并透传到 SSL Worker，TLS、HTTP 协商与统一策略都在 WLS 内完成，不依赖外部 Web Server。Linux/macOS Direct 则由 Worker 直接接受 TCP/TLS；macOS H3 的公开 UDP 端口由 WLS 原生 Router 所有，再按 QUIC connection ID 派发到认证 Worker channel。当前 H3 真实运行门禁已在 macOS 验证；Linux 与 Windows 必须各自完成 native ABI、UDP ownership、reload 和长稳验证后才可标记平台 READY。

2026-07-17 的 Parallels Windows 11 ARM + PHP 8.4.23 NTS x64 仿真门禁已证明 Dispatcher 的 TLS 1.3、ALPN `h2→http/1.1` 回退和 H2 多路复用：32 个同时在途 Stream 共用一个 TLS 连接并全部成功；100,000 个 H2 keep-alive 请求 0 错误，p95 111.588ms、p99 175.969ms、max 707.705ms，Worker RSS 增长 1.72%，进程无重启。该环境没有官方原生 ARM64 PHP，且 Windows H3/UDP 尚未 READY，所以 Windows 当前只发布已验证的 H2/H1.1 能力，不得发布 H3 `Alt-Svc`。

2026-07-19 的当前冻结源快照 `ai-test-win-frozen-20260719-11434` 通过 UNC + `pushd` 在 Parallels Windows 11 ARM / PHP 8.4 x64 仿真中完成最终复核：`auto -> dispatcher`，Direct/independent 在监听前拒绝，4/4 Worker 于约 13 秒内 READY，首页均为 Process FPC HIT。H2 c100 × 5,000 全部成功，2,920.81 QPS、P95 56.224ms、TLS P95 61.577ms、4 个物理连接、99.92% 复用，四 Worker 保持稳定并通过门禁。reload 约 29.8 秒，保留 Master/Dispatcher、替换全部 Worker PID，随后恢复 4/4 READY 与 Process FPC HIT。fresh H1/TLS 1.3 的 c50 × 500 两轮虽均完成 500/500 且 Worker 未重启，但分别以 P95 867.985ms / TLS P95 642.912ms 和 P95 476.49ms / TLS P95 364.78ms 保留为 FAIL；x64-on-ARM 的 c4 × 500 基线通过，72.83 QPS、P95 94.687ms、TLS P95 86.767ms。该差异与 PHP 8.4 不具备 TCP 外部 Session Cache、每条 fresh 连接都执行完整握手时的 x64-on-ARM 高并发饱和一致；在 PHP 8.6 Session Cache A/B 完成前不把它断言为单一根因，也不影响已通过的生产 H2 Keep-Alive/多路复用门禁。普通 start/reload 未出现原生构建进程且 H3 cache 始终不存在；停止后主端口、Worker、控制、Session/Memory 监听及相关 PID 全部为零残留。

2026-07-20 的最终功能样本 `ai-test-win86-tls-evidence-20260720-r2` 使用 Windows 11 ARM64 上的 PHP 8.6.0alpha2 x64 仿真，验证 `auto -> dispatcher`、3/3 Process FPC HIT、H2 500/500（4274.09 QPS，TLS P95 49.229ms）与 fresh H1 120/120（76.59 QPS，TLS P95 118.557ms）。绑定源码/config/报告哈希的 TCP TLS 证据通过同 Worker、跨 Worker、reload 后与恢复后各 24/24；认证 sidecar 关闭后的 400 轮混合窗口完成 800/800 HTTPS，请求关系均为跨 Worker，382 次恢复握手与服务端计数一致，sidecar generation 已切换。现有 `cache_failure/dropped_write/pending/inflight` 证据计数器均为 0；这不表示故障窗口中 sidecar 始终可用，完整握手正是预期降级。专用恢复握手 P95 156.236ms 未达到固定生产门禁 50ms；二进制头又证明 PHP 进程是 x64 而不是 Windows ARM64 原生执行，且运行时为 prerelease，稳定 macOS/Linux/Windows PHP 8.6 原生矩阵未完成。因此 durable mechanism=true，但 active/production=false。不可变证据为 `var/server/runtime-evidence/tls-session-resumption/e09cb186111c4bc27e9ea1520bddd6c5523c4055e1860e61b1d92f264ba5f63b/30961a7b5494e184c8351a048dc607cd2c9459c1b73c1d7e3d766dea033a6fe9.json`。

## 6. 常用命令速查

| 命令 | 说明 |
|------|------|
| `php bin/w server:start [name]` | 启动 WLS（默认 80/443，HTTPS 用 443） |
| `php bin/w server:start -p 9981` | WLS 原生监听自定义端口 9981（生产可直接监听 80/443） |
| `php bin/w server:start --host 0.0.0.0 -p 443` | 指定地址与端口 |
| `php bin/w server:start -c 8` | 指定 Worker 数量 |
| `php bin/w server:start --install-deps` | 显式允许本次调用 `env:install`；可能联网、运行包管理器/PECL并修改 PHP 配置 |
| `php bin/w server:http3:build` | 显式构建并真实自检 macOS/Linux 可选 HTTP/3 组件；普通启动不会调用 |
| `php bin/w server:start` | 启动（Master 默认启用，监控并自动重启 Worker） |
| `php bin/w server:start -d` | 守护进程模式 |
| `php bin/w server:start --cli` | 使用 PHP 内置 CLI 服务器（开发，无 HTTPS） |
| `php bin/w server:status [name]` | 查看实例、Master/Worker、实际 RuntimeSelection、listener/event/SSL 与 policy digest |
| `php bin/w server:benchmark --instance <name>` | 以 endpoint schema v4 归因实例，输出 Benchmark report schema v4 |
| `php bin/w server:start -p 9981 --host 127.0.0.1 --no-ssl` | 明文业务回源（配合 Nginx 边缘） |
| `php bin/w server:start ... --no-nginx` | 单次跳过托管 Nginx 启动 |
| `php bin/w server:nginx:install\|start\|stop\|reload\|status` | 仅托管模式（`managed=true`） |
| `php bin/w server:doctor` | 报告边缘适配器与托管/宿主机 Nginx 模式 |
| `php bin/w server:stop [name]` | 停止 WLS；`managed=true` 时会先停托管 Nginx |
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
| 启动预检报 `compiled_factories.php` 构造参数为 `null` | 旧反射编译器把对象默认值错误降级为 `null` | 更新代码后执行 `php bin/w reflection:compile`；新编译器会让无法安全字面量化的类回退到一次性反射，且预检失败返回非零退出码 |
| Worker 异常退出 | 进程崩溃 | Master 默认启用，会自动重启异常 Worker |
| `server:reload` 超时、断线或 Master 明确失败 | 控制面没有完成终态，或实例启动预算高于默认估算 | 命令会返回非零退出码；先用 `server:status` 核实状态。等待 deadline 按 Worker 批次、排水和启动预算动态计算；如需延长可配置 `wls.orchestrator.reload_wait_timeout_sec`，该值只会提高、不会压低安全估算。 |
| TLS 1.3 启动前失败 | 当前 PHP/OpenSSL 构建不支持 TLS 1.3 stream server | 切换到与当前平台匹配且暴露 `STREAM_CRYPTO_METHOD_TLSv1_3_SERVER` 的 PHP/OpenSSL，不删除协议门禁兜底 |
| 显式配置 HTTP/2/3 后启动失败 | 当前默认 WLS Transport Adapter 尚未提供 h2/h3，或显式兼容适配器未通过能力验证 | 改回 `protocols=['h1']` 与 `protocol_edge=disabled`；不要把配置值误报为实际协议能力 |
| 显式兼容 Edge 健康检查超时 | Dispatcher 未识别 loopback + 实例 token 健康探针，或 token/header 被中间层改写 | 核对兼容 Edge 与 Dispatcher 属于同一实例和 digest；其它明文请求仍应跳转 HTTPS或被拒绝 |
| h1/h2 正常但 h3 不可达 | 同一公开端口的 UDP 未放行，或上游 NAT/LB 不转发 QUIC | 放行并转发该端口的 UDP；用支持 HTTP/3 的 curl/浏览器复测 |
| 请求 `performance` 但状态显示 `external` | 启动环境已存在 `OPENSSL_CONF` | 这是运维配置优先的预期行为；核对该文件的 group/cipher 策略，不要期待 WLS 覆盖 |
| 跨平台复制后模板仍指向 `/Users/...` 或 `C:\\...` | `modules.php`、路由或模板缓存由另一平台生成 | 运行时会用模块稳定 `path` 重定位到当前 `BP/app/code`，并按 OS + BP 隔离模板缓存；若目标模块目录本身不存在，重新同步代码后再启动 |
| Windows 复制项目后 SQLite 仍指向原系统绝对路径 | SQLite 配置保存了另一平台下 `app/` 或 `var/` 内文件的绝对路径 | 运行时只在当前项目存在同后缀真实文件时重定位到当前 `BP`；外部数据库路径、`:memory:` 与 `file:` URI 保持原样 |
| Windows UNC 项目启动慢但本地磁盘正常 | SMB/Parallels 共享目录的元数据与大量 PHP include 延迟 | UNC 只作为兼容性验证；正式 QPS、冷启动和 Worker 批量启动门槛必须在 Windows 本地磁盘副本上测量 |
| 需要定位 WLS 冷启动长尾 | 旧 trace 只有秒级时间，无法区分 CLI 引导、配置、依赖、证书、编译、策略和 Master 内部阶段 | 仅诊断时设置 `WLS_STARTUP_TRACE=1`；`var/log/wls-startup-trace.log` 的每条记录包含进程内 `sequence/mono_ns/total_ms/delta_ms/process_elapsed_ms/memory_mb`。比较同一 PID 的 `delta_ms`，并用 `process_elapsed_ms - total_ms` 识别首条 trace 之前的 PHP/CLI 引导；关闭变量后不计时、不写 trace。 |

---

**版本：** 2.0.0-dev
**更新时间：** 2026-07-20
**状态：** macOS Direct HTTPS、Hybrid READY/HELLO 控制面和原生 HTTP/3 已通过真实门禁；ABI 2.9 已证明独立 TLS-context/previous-key H3 恢复，并有专用跨 Worker/reload 实机证据。Windows Dispatcher、TLS 1.3、H2/H1、reload、首页预热和 PHP 8.6 TCP 外部有状态 Session Cache 的持久功能链已验证；Linux Direct/TLS/H2/H1 与 reload 门禁也已完成。仍未完成的是 Linux 多 Worker H3、Windows H3、稳定原生 PHP 8.6 的 macOS/Linux/Windows TCP 恢复矩阵，以及固定恢复握手 P95 ≤ 50ms 门禁；当前 PHP 8.6.0alpha2 x64-on-ARM 样本为 156.236ms，不能标记生产就绪。

动态路径预热默认只包含首页 `/`。业务模块需要预热商品、分类或账户页面时，应显式配置 `wls.worker.dynamic_critical_paths` / `wls.worker.dynamic_hot_paths`，或通过 `Weline_Server::dispatcher::warmup_paths` 发布真实路由；Server 不内置任何演示业务 URL。
