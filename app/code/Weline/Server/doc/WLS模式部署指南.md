# WLS 模式部署指南

WLS（Weline Server）是框架内置的常驻内存 HTTP 服务器。**公网入口唯一使用项目托管 Nginx**：Nginx 终结 TLS 1.3 与 HTTP 协商，WLS 固定以 loopback 明文 HTTP/1.1 Keep-Alive 回源。公网默认协商 HTTP/2，客户端不支持时由 Nginx 自动回退 HTTP/1.1；H3 还要求 Nginx HTTP/3/QUIC 配置、UDP、Alt-Svc 与项目 owner 绑定的 HTTP/3-only 请求真实到达 `/_wls/health?detail=1` 并返回匹配的 WLS 后端身份，Nginx 本地响应或边缘缓存不能作为证据。PHP cURL verifier 不可用时保持 pending。普通启动拒绝 `--no-nginx`、`--no-ssl`、外部/宿主机 Nginx、Caddy、WLS-native 与 WLS TLS 回退。

## 1. 模式说明

| 模式 | 说明 |
|------|------|
| **项目托管 Nginx（唯一受支持）** | 固定 `wls.edge.adapter=nginx`、`managed=true`、`auto_start=true`。WLS 只启停本项目 `extend/server/nginx`；二进制必须通过显式安装命令预先存在。 |
| **外部/宿主机 Nginx（已退役）** | 当前 `server:start` 在创建子进程前拒绝 `managed=false`、`auto_start=false` 与 `--no-nginx`，不提供绕过托管 Nginx 的启动路径。 |
| **Gateway/Caddy/WLS-native/TLS/Protocol Edge（已退役）** | 只保留历史识别或残留清理材料；没有可成功启动的数据面命令、配置值或能力失败时的回退入口。 |

接入含义：**公网请求到达 Nginx** + **Nginx 能访问 WLS loopback 回源端口** + **在后台为该网站配置域名**。

### 1.1 Nginx 边缘配置

```php
'wls' => [
    'edge' => [
        'adapter' => 'nginx',           // 唯一支持值；整段可省略
        'reload_command' => '',         // 遗留外部边缘键；项目托管模式不使用
        'nginx' => [
            'managed' => true,          // 唯一受支持模式
            'auto_start' => true,       // WLS READY 后自动启动并验证本项目 Nginx
            'listen_http' => null,      // 仅托管模式：null → 8080 + projectPortOffset
            'listen_https' => null,     // 仅托管模式：null → 8443 + projectPortOffset
            'install_root' => null,      // 使用平台隔离默认目录
            'runtime_root' => null,      // 使用平台隔离默认目录
            // 最佳性能默认
            'edge_cache' => true,
            'edge_cache_ttl_sec' => 60,
            'edge_cache_max_size_mb' => 1024,
            'edge_cache_keys_zone_mb' => 128,
            'gzip' => true,
            'gzip_comp_level' => 2,
            'upstream_keepalive' => 256,
            'upstream_keepalive_timeout_sec' => 5,
            'worker_connections' => 32768,
        ],
    ],
],
```

### 1.2 已退役：宿主机 Nginx 模式

以下配置和反代片段只保留为退役部署的迁移参考，**不是当前可运行入口**。当前启动不会检测、接管或放行宿主机 Nginx；`managed=false`、`auto_start=false` 和 `--no-nginx` 都会在创建 WLS 子进程前失败。

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

> 退役配置没有对应的 `server:start` 命令；不得复制旧 `--no-ssl` / `--no-nginx` 启动方式。

宿主机 Nginx 反代示例（用户自管 conf，指向本项目 WLS 端口）：

```nginx
server {
    listen 443 ssl;
    http2 on;
    ssl_protocols TLSv1.3;
    ssl_session_cache shared:WLS_SSL:10m;
    ssl_session_timeout 1d;
    ssl_session_tickets on;
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

退役模式的历史要点：

- WLS 不曾修改宿主机 `/etc/nginx`；当前模式则只管理项目隔离目录，不接管系统 Nginx。
- 当前 `server:start`/`server:stop` 只形成项目托管 Nginx 与本实例 WLS 的完整生命周期。
- 若启用可信代理，把宿主机反代网段列入 `trusted_proxy_cidrs`。

### 1.3 本项目托管 Nginx（多项目互不干扰）

托管 Nginx 必须由运维显式安装（需 `managed=true`）。macOS 默认安装到 `extend/server/nginx`；Linux 默认使用 `extend/server/nginx-linux-{arch}`，避免不同架构共用二进制；Windows 使用项目身份隔离的本机目录。该安装动作与协议实现无关：WLS 本身仍是 PHP 代码；仅 macOS/Linux 的托管 Nginx 安装命令会构建 Nginx，Windows 使用官方预编译包。

Windows 安装器把 ZIP cache、extract、candidate、rollback 与 final 全部限制在同一项目身份的本机 LOCALAPPDATA 根。candidate 的 nginx.exe、PE 架构、二进制 SHA 与 manifest 完整校验后，才以 final → rollback、candidate → final 的同卷目录 rename 发布；解压或发布失败时恢复旧 final。即使项目从 UNC/Parallels 共享目录启动，也不会在共享盘解压或逐文件覆盖正式安装。

| 平台 | 方式 | 依赖 |
|------|------|------|
| macOS | 固定摘要的官方 1.30.4 源码编译 | Xcode CLT、OpenSSL 3 和 PCRE2 头文件（例如 `brew install openssl@3 pcre2`）；PCRE/rewrite 为硬依赖 |
| Linux | 固定摘要的官方 1.30.4 源码编译 | `gcc/make` + OpenSSL/PCRE2 头文件（发行版 `*-devel`/`*-dev`）；PCRE/rewrite 为硬依赖，无 zlib 头时才可自动关闭 gzip 模块与配置 |
| Windows | 固定摘要的官方 1.30.4 `nginx.zip` 解压，不在目标机编译 | PowerShell、PHP `iconv`，以及 `ZipArchive` / PowerShell / tar 中至少一种解压能力 |

```bash
# 显式安装到当前平台的项目隔离目录；这是唯一允许下载/构建 Nginx 的入口
php bin/w server:nginx:install
php bin/w server:nginx:install --force   # 必须先停止托管 Nginx；显式替换不匹配的旧版本

# 启动 WLS 明文回源；READY 后写 conf 并启动已安装的本项目 Nginx
php bin/w server:start -p 9981

php bin/w server:nginx:status
php bin/w server:stop   # managed=true 时会先停本项目托管 Nginx
```

普通 `server:start`、reload 和 restart **绝不下载或编译 Nginx**。托管模式缺少二进制时，启动返回非零并提示先显式执行 `php bin/w server:nginx:install`；不会在启动路径中自动补装。Unix 显式安装在 PCRE2 头文件缺失时 fail closed，安装 manifest 和运行时 capability probe 都要求 rewrite 模块；不会回退到 `--without-http_rewrite_module`。

对外访问端口为 `8080/8443 + projectPortOffset`（可用 env 覆盖）。安装与运行目录均按项目 BP 和平台隔离；Linux 默认目录还包含架构后缀，Windows 使用项目身份哈希对应的本机目录。托管 conf 默认：HTTPS `http2 on`（客户端不支持时自动回退 HTTP/1.1）、HTTP/1.1 upstream keepalive、`upstream_keepalive_timeout_sec=5`、`access_log off`、较大 `worker_connections`。Worker reload 的 drain 安全下限为 upstream idle timeout 再加 5 秒。启动前同时校验 manifest 的平台/架构/官方包摘要/已安装二进制摘要、实际 `nginx -V` 版本、HTTP/2、HTTP SSL、rewrite 与 OpenSSL ≥ 1.1.1；发布后还必须完成证书指纹绑定的 TLS 1.3 握手，并分别以真实 H2/H1 请求抵达 owner 绑定的 WLS health、匹配 generation 与 backend identity。install/start/reload/stop 共用项目级生命周期锁。新配置先写候选文件并通过 `nginx -t`，再发布、reload；任一步失败都会恢复磁盘上的上一版配置，而且只有旧 owner generation 被真实请求重新证明后才继续运行，否则停止 Nginx 并保留恢复证据。

每次托管 Nginx reload 还执行独立的 `fresh-share-across-nginx-reload-v1` 连续性事务：候选发布前用新 SSL share 建立一个 fresh TLS 1.3 Session 并保留该 share；新 generation 激活、旧 Worker 排水后，使用同一 share 在 fresh TCP 上恢复。只有 Nginx Master PID 和证书摘要不变、generation 已变化、结果为 `r`、新旧 Nginx Worker PID 不同且恢复握手 ≤ 50ms 才通过。它证明 Nginx shared SSL session cache/ticket 在 graceful reload 期间连续，不能推导 PHP Stream/WLS 或 HTTP/3/QUIC Session Resumption。

托管配置用 `$wls_upstream_authority` 保留公开 authority：有 `$http_host` 时原样转发；HTTP/3 的 `$http_host` 为空时回退为 `$host:$server_port`。`Host`、`X-Forwarded-Proto` 与 `X-Forwarded-Port` 一起经过固定可信的 loopback H1 hop，Worker 据此重建公开 origin，避免非标准 HTTPS 端口丢失。

普通业务请求默认清空 upstream `Connection` 并复用 Nginx Keep-Alive 池；只有精确的 `Upgrade: websocket` 会映射为 tunnel 头并绕过边缘缓存，但当前发布矩阵尚未用 101/帧往返宣称 WebSocket 可用。Nginx loopback allowlist 保护的 `/_wls/` 探测位置会把客户端精确的 `Connection: close` 传播给 WLS，使 H1 fresh 分流门禁同时创建 fresh edge 与 fresh 回源连接；其它 Upgrade/Connection 值不会传播。

ACME HTTP-01 也只走项目托管 Nginx：`/.well-known/acme-challenge/` 明确反代到 WLS Worker。Windows Direct 不存在 Dispatcher 的旧 inline ACME peek；ACME 可达性与普通 `worker_ports` accept 热路径因此互不牺牲。

隔离验证或容器/虚拟机部署可用进程环境变量 `WLS_NGINX_LISTEN_HTTP`、`WLS_NGINX_LISTEN_HTTPS` 覆盖所有平台的托管 Nginx 端口；`WLS_NGINX_INSTALL_ROOT`、`WLS_NGINX_RUNTIME_ROOT` 的项目相对目录覆盖仅适用于 macOS/Linux，且禁止绝对路径和 `..` 逃逸。Windows 始终忽略这两个目录覆盖，固定使用本机 `LOCALAPPDATA`（缺失时 `TEMP`）下的项目身份目录。同一实例的 start/doctor/reload/stop 必须继承同一组端口变量，owner 记录仍会绑定真实公网端口并阻止错实例归因。

> 性能提示：`server:benchmark --instance <name>` 默认压测 owner 与实例绑定一致、已经 live 验证的托管 Nginx 公网端点；owner/config generation 不一致时 fail closed，不回退到 WLS。只有显式内部 host/port 才归因为 `wls_endpoint`。托管 Nginx **默认最佳性能配置**：匿名 GET 边缘微缓存（`edge_cache=true`，TTL 60s，有 Cookie 跳过）、可用时 gzip（comp_level=2）、upstream keepalive=256、worker_connections=32768、access_log off。健康检查 `/_wls/` 不走边缘缓存。修改 `proxy_cache_path` 的 zone 大小后需 `server:nginx:stop` 再 `start`（reload 无法重建共享内存区）。
>
> **1.30.4 实测参考（WLS health，全部 0 请求错误）**：macOS H2 100000 请求为 6560.16 QPS、H3 5000 请求为 6517.13 QPS；Linux ARM64 H2/H3 分别为 5979.56/7492.49 QPS。Windows 11 ARM 的旧 Dispatcher A/B 中，移除每条明文 upstream connection 的 50ms ACME peek 后，H2 2000 请求从 663.15 提升至 971.18 QPS，P95 从 166.699ms 降至 103.55ms；该数字只保留为历史优化归因，不能代表当前 `worker_ports` Direct。Windows Nginx 单有效 Worker、VM 与 x64 PHP 仿真开销使绝对值不可与原生 macOS/Linux 横比。

## 2. 环境配置

在 `app/etc/env.php` 中配置 `server` 或 `servers`（多实例）：

```php
'server' => [
    'host' => '127.0.0.1',   // Nginx 回源地址
    'port' => 9981,          // Nginx 回源端口
    'worker_count' => 'auto', // 自动按平台 CPU 拓扑和内存预算计算；也可显式指定 4、8 等
    'mode' => 'io',          // io | cpu
    'https' => true,         // 公网 Nginx TLS 意图；WLS 回源仍固定明文 H1
],
'wls' => [
    'runtime' => [
        'topology' => 'auto', // 所有平台 -> Direct
        'listener_mode' => 'auto', // Windows -> worker_ports；Linux -> reuseport/shared_fd；macOS -> shared_fd
    ],
    'edge' => [
        'adapter' => 'nginx',
        'nginx' => [
            'managed' => true,
            'auto_start' => true,
        ],
    ],
],
// 多实例示例
'servers' => [
    'api' => [
        'host' => '127.0.0.1',
        'port' => 9001,
        'worker_count' => 4,
    ],
],
```

- **公网端口**：只由项目托管 Nginx 监听；默认是 `8080/8443 + projectPortOffset`，也可由 env 覆盖为 80/443 等端口。WLS 自动固定为 loopback 明文 H1 高端口，禁止传入 `--no-ssl` 绕过公网 TLS 门禁。
- 配置优先级：**命令行参数 > env.servers[实例名] > env.server > 默认值**。
- WLS 内部拓扑的 `auto` 在所有平台都选择 Direct：Windows 为 Nginx 均衡的独立 `worker_ports`；Linux 优先经能力验证的 `reuseport`，不可用时回退 Master-owned `shared_fd`；macOS 使用 Master-owned `shared_fd`。所有平台仍允许显式 Dispatcher 作为兼容/诊断拓扑；已删除的 `independent` 始终拒绝。该内部选择不改变公网唯一由 Nginx 所有的事实。

## 3. 启动与停止

```bash
# 生产推荐：明文回源（配合 Nginx 边缘）
php bin/w server:start -p 9981


# 启动命名实例（使用 env.servers.api）
php bin/w server:start api -p 9001

# 查看状态（含 Master PID、Worker 状态）
php bin/w server:status

# 停止
php bin/w server:stop
# 或指定实例：php bin/w server:stop api
```

- **监听边界**：WLS 不直接监听公网 80/443；Nginx 反代到 WLS 的 loopback 高端口。
- **拓扑限制**：所有受支持平台的 `auto` 都选择 Direct，也允许显式 Dispatcher；Windows Direct 只接受 `worker_ports`，POSIX Direct 不接受该监听模式。`independent` 始终拒绝。没有受支持平台驱动的系统在启动前失败。
- **自动 Worker 数**：显式 `-c/worker_count` 始终优先（当次 CLI 不静默钳制）。`worker_count=auto` 时启用 `wls.worker_budget`（默认 true）：容量取 `cgroup memory.max`（字面量 `max`/无效则回退）或 `MemTotal`，**不用 MemAvailable 定启动数**；单槽 RSS 估计为 `max(192, worker_memory_limit_mb)`（含 panel 粘盘后的实际 limit）；`limitMb≤2300` 时 hardCap=2。解析出的 auto 整数默认不落盘；历史实例文件仅有整数且无 `worker_count_requested` 时，低内存会钳制到 hardCap。关闭预算：`wls.worker_budget.enabled=false`（回退旧 `floor(MemTotal/512)` 公式）。
- **完整重启顺序**：旧 Nginx 停止后，新 Master/Worker 先达到 READY；启动器随后把 maintenance 恢复为重启前快照并确认业务路由，再生成/启动 Nginx 候选并执行公网 TLS/H2/H1/H3 门禁。不得让门禁请求仍落到临时 Maintenance Worker。
- **Windows 清理预算**：正常 `server:start -r` 等待旧代端口与 scoped 进程清理最多 30 秒；macOS/Linux 为 12 秒，fast-local 为 6 秒。超时仍 fail closed 并返回非零，不改端口、不启动第二个同名实例。
- **Windows 后台 Master 控制面确认**：默认软等待 30 秒，硬等待使用 `soft × 4` 且封顶 120 秒；Parallels UNC 冷启动和 ARM64 上 x64 PHP 仿真可在 60 秒后才发布 control endpoint。已知 spawned PID 在窗口内持续受控，该扩展不放宽 Worker READY、首页 Process FPC 或 Nginx 协议门禁；POSIX 仍使用既有有界默认（硬上限最多 60 秒）。显式 `wls.orchestrator.background_master_control_hard_wait_sec` 优先，仍封顶 120 秒。

### 3.0a 长久运行与整机内存压力控制器

WLS 长久稳定不等于「永不停机」。内存控制器只保证：**启动不超配 + 可重建内存可淘汰 + Critical 单槽优雅减池（全路径复活门闩）+ Green 慢恢复到 `budget_ceiling` + 进程有界换血 + 可观测**。

| 开关 | 默认 | 作用 |
|---|---|---|
| `wls.worker_budget.enabled` | true | 启动期容量预算 |
| `wls.memory_pressure.enabled` | true | 运行时压力 FSM / 广播 / 减池 / 恢复 |

运行时同源压力：cgroup `current/max`；否则 `1 - MemAvailable/MemTotal`。升档需连续 3 次采样；Green 恢复线 0.65。Critical：`desired-1` 后对 **单个** 最高实例 `sendDrainToInstance`（有界 45s/120s），禁止 `WorkerScaler`/reconcile 一次砍光超额槽；复活入口（含 reconcile 补齐）在 `instanceId>desired` 或 `live>=desired` 时门闩跳过。Green×`recover_samples`(10)+冷却后 `desired+1`，上限启动时的 `budget_ceiling`。Master 重启按意图重算 ceiling，从 ceiling 起跑（不持久化缩容后的 desired）。

HTTP/SSL Worker 统一读 `wls.memory_guard.worker_memory_*`（默认 warning=0.80 / drain=0.92）；SSL 亦上报 `memory_pressure_drain` 并计入 planned recycle，且支持 `worker_max_requests` 错峰换血。Host Critical 时跳过 homepage keep-warm。FPC 进程 L1 在硬压力末位回收；忙 Fiber 时可 skip 并在请求结束后再 compact。

**残余风险（必须知情）**：无 systemd 自动拉起、Nginx 默认大边缘缓存仅文档 WARN、Master 自身泄漏不在本控制器范围、非负载驱动的自动扩容主循环本轮不做。回滚：关闭上述双 flag。

代码审查结论（实施加严）：只改一处复活入口不够 → 全路径门闩 + Critical reconcile fence；SSL 须补 planned drain reason；减池必须单槽 `sendDrainToInstance`。

### 3.0 启动依赖预检

`server:start` 在创建 Master/Worker 之前先按平台求出唯一内部拓扑，再只读验证当前 PHP 的必需能力。普通启动不会下载、安装、编译或修改 php.ini，也不会安装 Nginx。Linux `auto` 先验证 PHP `sockets` 与内核 `SO_REUSEPORT`，可用时选择 `reuseport`，不可用时回退只要求 POSIX FD 原语的 Master-owned `shared_fd`；两者都要求预装 `ext-event`。macOS Direct 使用 Master-owned `shared_fd + ext-event`。Windows Direct 使用每 Worker 独立 loopback 端口和内置 `stream_select`，不要求 `event/ev`、继承 FD 或 `SO_REUSEPORT`，也不会安装 DLL 或编译扩展。显式 Dispatcher 兼容模式仍使用有界 `socket_select`。

只有本次显式传入 `--install-deps`，`server:start` 才允许调用 `env:install`，并在创建任何 WLS 子进程前使用同一个 `PHP_BINARY` 新进程复验：

- macOS：可能以当前用户运行 Homebrew 和 PECL，不使用 `sudo`。
- Linux：可能调用 apt/dnf/yum/apk/pacman；非交互路径只允许 `sudo -n`，不会等待密码。
- Windows：`worker_ports` Direct 已是平台完整能力；`--install-deps` 不下载、不启用也不编译 PHP 事件扩展。
- `--no-auto-deps` 仅作为旧脚本兼容选项；普通启动默认已经禁止安装，且该选项不能与 `--install-deps` 同用。
- Linux `auto` 的 reuseport probe 失败会在 Direct 内部回退 `shared_fd`；最终 listener/event/FD 能力或 Direct 策略 capability 仍失败时停止启动，不会改写为 Dispatcher。

### 3.1 架构说明（多 Master / 多 Worker / 流量分发）

**多 Master（多实例）**：已支持。每个 `server:start [实例名]` 对应一个独立实例，每个实例有：

- **1 个 Master 进程**：不承载业务 HTTP/HTTPS，负责启动、健康监督、路由发布、平滑重载和异常 Worker 恢复。
- **N 个 Worker 进程**：常驻内存处理 Nginx 的 HTTP/1.1 回源请求；实际监听方式由平台拓扑决定。
- **0 或 1 个 Dispatcher**：所有平台 `auto` 都是 0；只有显式兼容/诊断拓扑才启动 1 个。

实例之间通过实例名区分（如 `default`、`api`），实例信息存于 `var/server/instances/{实例名}.json`，多实例互不干扰。

**Master 不做数据面流量分发**。WLS 回源固定为明文 h1：Linux `auto` 优先由各 Worker 建立 `reuseport` listener，能力不可用时回退 Master 创建并传递的单一 `shared_fd`；macOS 使用 `shared_fd`；Windows 每个 Worker 绑定独立 `worker_ports`，项目托管 Nginx 直接将全部端口写入 upstream 池。公网 TLS 与协议协商始终在 Nginx，运行时事实中的 `runtime_selection.ssl_engine` 固定为 `none`；历史 `stream` 值只允许被旧实例配置读取并在新一代规范化，不能重新启用 Worker TLS。

**总结**：

| 能力 | 是否实现 | 说明 |
|------|----------|------|
| 多 Master（多实例） | ✅ 已实现 | 多实例 = 多份 Master+Workers，按实例名区分 |
| Master 监控/重启 Worker | ✅ 已实现 | 健康检查、异常重启、重载信号 |
| Master 监听公网 443 并分发 | ❌ 不允许 | 公网 443 只由 Nginx 监听；Master 只管理 WLS 回源 listener |
| Nginx 到多 Worker 的回源负载均衡 | ✅ 已实现 | Windows upstream 列出全部 `worker_ports`；Linux auto 优先 `reuseport` 并回退 `shared_fd`；macOS 共享 Master-owned `shared_fd` |

托管 Nginx 配置必须来自实例 endpoint：Windows 写入全部 READY Worker loopback 端口；Linux 写入当前 `reuseport/shared_fd` listener；macOS 写入共享 listener。不得手工绕过 owner/config generation 与 READY/策略门禁。

### 3.2 多 Worker 回源端口与单口压测

`auto` 根据平台选择固定的内部回源数据面。不同拓扑不能只看峰值 QPS，还要对照业务路径的 p95/p99、Worker 分布和故障恢复；TLS/H2/H3 必须另对公网 Nginx 测量。

- **worker_ports Direct**：Windows 自动使用。每个 Worker 绑定独立 loopback h1 端口，Nginx upstream 连接池直接均衡这些端口；Worker 使用内置 `stream_select`。
- **reuseport Direct**：Linux 自动优先使用。每个 Worker 拥有同一 loopback 端口的独立 accept 队列，要求 `sockets`、内核 `SO_REUSEPORT` 与 `ext-event`。
- **shared_fd Direct**：macOS 自动使用，也是 Linux reuseport 能力不可用时的自动回退。Master 拥有 listener，Worker 共享单一 loopback h1 accept 队列并执行完整策略；要求 POSIX FD 原语与 `ext-event`，`event_buffer + direct` 仍在启动预检时拒绝。
- **Dispatcher**：仅为所有平台显式兼容/诊断拓扑；不是 `auto` 回退路径。
- **shared_fd Direct**：共享 FD 只用于事件就绪通知；Worker 在同一内核 accept 队列上接收连接。listener 的 Event watcher 始终注册，冷却只抑制本轮 accept，不能销毁/重建 watcher。rolling 使用标准分批，不启动 reuseport new-first surge，从而不会在独立 accept backlog 退役时重置已到达连接。
- **direct 维护态**：不启动 Maintenance Worker；Master 将维护 epoch 下发给全部业务 Worker，只有全量 ACK 后才提交状态。业务 Worker 至少跨过一个 transport loop 再 ACK，等待已分派请求和待写响应，但不等待空闲 preconnect、未完成握手或 partial slowloris；EventBuffer 中已经完整的流水线请求会按有界预算经过同一 WorkerPolicyKernel 后再 ACK。
- **其他系统**：没有受支持的平台驱动就停止启动。

Worker 数必须用目标机器上的矩阵选择，不能仅按 CPU 数线性增加。2026-07-27 的发行版预编译 Linux ARM64 runner 上，原 `shared_fd` 矩阵 8 Worker H2 为 17,790.20 QPS / P95 12.475ms，高于 16 Worker 的 16,887.18 QPS / P95 13.132ms；16 Worker fresh H1 虽然请求全部成功，但 10,000 请求的 Worker `max/min=4.929`，未通过 `<=1.5` 分布门槛。后续 10 Worker H2 达到 18,279.10 QPS，但 fresh H1 仍为 `1.905`；8 Worker 的 fresh H1 又在不同 generation 间出现 `1.327` PASS 与 `2.230`/`2.443` FAIL。因此 8/10 都不是已证明的全局固定默认值，临时 auto=8 假设已撤回。

同一 10 vCPU、8 Worker runner 的监听器 A/B 中，`shared_fd` fresh H1 为 `max/min=1.592`（失败）；显式 `reuseport` 两轮分别为 `1.171`、`1.166`（通过），H2 为 18,259.27 QPS / P95 17.916ms。切换 Linux `auto` 后实际选择 `direct/reuseport/event`，H2 10,000/10,000 为 18,208.24 QPS / P95 17.734ms，fresh H1 10,000/10,000 为 2,409.61 QPS、`max/min=1.145`（通过）。因此 Linux auto 采用已实测的 reuseport 优先策略；容量验收仍需同时覆盖吞吐、P95/P99、重复 fresh 分布、Worker 恢复和 Nginx reload 连续性。

实例 endpoint 只写 schema v4。嵌套 `runtime_selection` 完整保留 requested/effective topology、选择来源、OS、event loop、listener mode、策略兼容性和 reason codes；根级投影已删除。Master 重入只接受完整 v4，旧 schema、缺失字段或未知字段都在绑定端口前拒绝，不推导、不补写。`edge_adapter=nginx` 与 WLS h1 回源选择是 Start 已验证的实例协议策略，必须穿过 Master 与 Orchestrator 的所有 endpoint 写回；任一环节丢失时 Worker 都应 fail closed。

`server:status <name>` 直接展示 endpoint schema v4 中持久化的回源选择、listener/event、policy digest 与项目托管 Nginx 公网事实。推荐显式使用 `php bin/w server:benchmark --instance <name>`：只有 owner/config generation 与实例绑定一致且 Nginx live probe 通过时才压公网端点。仅显式提供内部 host/port 才测 WLS 回源并标记 `wls_endpoint`；多匹配、零匹配或多个运行实例无明确目标时 fail closed，防止误压生产实例。

两种内部拓扑都在 Worker 执行同一 mandatory request guard、Static/FPC 和 Router/Controller 管线。缺少后台 Key 的 `/admin/login` 会在缓存和 Router 前返回 404，必须访问 `/{backend_key}/admin/login`。完整执行顺序见 [WLS 安全与规则配置推演](WLS安全与规则配置推演.md)。

### 3.3 进程安全

框架通过 `--name=weline-xxx` 标识 WLS 服务器进程。端口被占用时：
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

`php bin/w server:status <instance>` 会为每个业务 Worker 分开显示两项证据：`首页预热` 使用 `warmup_state + homepage_fpc` 展示 Process FPC 的 hit/source/status/reason；`动态首渲染测量` 展示 ready、elapsed/target、HTTP status、body、attempts、FPC 和实际 host/path，尚未采集时明确显示“未记录”。两项不能混为一谈：Worker READY 的首页缓存门禁以 `homepage_fpc.hit=true + source=process` 为准，动态首渲染则是独立发布性能证据。业务 Worker 缺少协议能力、首页 Process FPC、动态回执、HTTP 成功或正文证明时不会 READY；`elapsed >= target` 默认记为 `ready:slow` 供发布门禁和观测使用，不作为 Worker 存活失败。若 Master IPC 在查询窗口内繁忙，CLI 只读回退会把持久化 `worker_ready` 事件裁剪到当前 canonical `1..count`，历史 replacement/扩缩容 Worker 不会让健康的 4/4 实例误报为 4/5。

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
   - 公网带非标准 Nginx 端口时可用：`example.com:8443`
3. 保存后，该域名的 URL 会参与框架的网站解析，请求带此 Host 即识别为该网站。

WLS 的 Host Guard 位于网站解析之前。Nginx 必须原样转发公网 Host；实例另有域名时，应把它加入实例配置或安全规则 `allowed_hosts.hosts`，然后重新执行 `server:policy:compile/publish` 或重启。仅在网站后台绑定域名不会自动放宽 Worker 的安全入口。

公网只使用 Nginx，但 WLS 仍需在后台配置域名，才能按 Nginx 转发的 Host 解析网站。

### 4.2 让请求经 Nginx 到达 WLS

公网请求只能先到 Nginx；WLS 只监听 loopback 明文 H1 回源端口，不提供直连 80/443 或公网高端口模式。

**已退役方式：宿主机 Nginx**

以下内容只用于识别旧部署，不是当前启动方式。当前 `server:start` 拒绝 `managed=false`，不会把系统 Nginx 视为可交付公网边缘：

```bash
# 无可运行命令：当前启动会拒绝 managed=false
```

旧部署曾由宿主机 Nginx 监听 80/443；迁移时应改为下一节的项目托管 Nginx，不得继续沿用外部生命周期。

**现行唯一方式：项目托管 Nginx**

无论宿主机是否已有系统 Nginx，都只能显式配置 `managed=true` 并使用项目隔离的托管生命周期。先单独安装固定身份的托管 Nginx，再启动 WLS；普通启动不会代替安装：

```bash
php bin/w server:nginx:install
php bin/w server:start -p 9981
```

公网 DNS 指向 Nginx 所在服务器；用户访问 `https://www.example.com/`，Nginx 保留 Host 并转发到 WLS。任何情况下都不要把 WLS 回源地址改为 `0.0.0.0` 作为公网入口。

这种部署中 WLS 看到的 socket peer 是项目托管 Nginx。RuntimePolicyBundle 会固定把 `127.0.0.0/8` 与 `::1/128` 合入 trusted proxy transport peers，使 Worker 可采信托管 Nginx 覆盖后的 `X-Real-IP` / `X-Forwarded-For`、scheme、port 与 authority；loopback 仍不会自动成为业务白名单。该信任只重建 client/origin 事实，不绕过 Origin Token、ban、限流或攻击规则。只有 `wls.accept_gate.whitelist_cidrs` 或安全规则中显式配置的 `ip_whitelist.ips` 可跳过这些规则，新安装默认 whitelist 为空。

```php
'wls' => [
    'accept_gate' => [
        // loopback 已由 Nginx-only RuntimePolicyBundle 固定加入；这里只列额外可信代理。
        'trusted_proxy_cidrs' => [],
        // 默认保持空；只在明确接受完整规则跳过时增加。
        'whitelist_cidrs' => [],
    ],
],
```

**多 Worker：Nginx 单一公网入口 + WLS 内部回源（现行生产模式）**

`-c 4` 只改变内部 Worker 数，不增加任何公网端口：

- Linux Direct：自动优先 `reuseport` 独立 accept 队列，能力不可用时回退 Master-owned `shared_fd`；macOS Direct 使用 `shared_fd`。
- Windows Direct：每个 Worker 监听独立 loopback 端口，托管 Nginx upstream 列出全部端口并直接均衡；这些端口不对客户端公开。

项目托管 Nginx 是唯一公网边缘，并按平台代理到 `reuseport/shared_fd` listener 或实例绑定的 Worker loopback 端口池。当前启动不接受外部 Nginx 生命周期；如需跨机高可用，应在项目托管边缘之外另行设计基础设施，不得让公网客户端直连 Worker 内部端口。

## 5. HTTPS / SSL

- **公网 TLS**：只由 Nginx 终结，要求真实握手为 TLS 1.3；WLS 回源保持 `127.0.0.1:<高端口>` 明文 HTTP/1.1。
- **证书**：项目托管 Nginx 使用项目证书目录 `app/etc/ssl/{域名}/`，由同一托管生命周期发布和 reload。
- **HTTP 到 HTTPS**：重定向由 Nginx 统一处理；WLS 不另起公网 HTTP/TLS 监听。
- **所有权**：公网 80/443 只能属于项目托管 Nginx，不能切换到 Gateway、Caddy、WLS SSL Worker、Protocol Edge 或 native transport。

### 5.1 当前 HTTP 协议能力

当前协议必须按 surface 报告，不能再用一行“默认协议”混写公网与回源：

| Surface | 默认策略 | 证据边界 |
|---|---|---|
| Nginx 公网 HTTPS | HTTP/2 → HTTP/1.1；H3 条件启用 | 真实 TLS 1.3 + ALPN/HTTP 请求门禁；H3 必须通过 owner 绑定的 HTTP/3-only WLS health 请求，Nginx 本地响应/缓存不算，PHP cURL verifier 不可用时保持 pending |
| Nginx → WLS 回源 | HTTP/1.1 Keep-Alive | 业务请求保持连接池；仅 `/_wls/` fresh 门禁传播精确 `Connection: close`；只有显式内部 host/port 才压测这一层 |

下面的 `h1` 配置描述的是 Nginx 模式下的 WLS 回源端点，不代表公网客户端只能使用 HTTP/1.1：

```php
'wls' => [
    'http' => [
        'protocols' => ['h1'],
        'preferred' => 'h1',
        'alt_svc' => false,
    ],
],
```

- Nginx 与 WLS 之间的 HTTP/1.1 Keep-Alive 默认启用；公网 HTTP/2 多路复用由 Nginx 提供，客户端不支持 H2 时自动回退 H1。只有 Nginx loopback allowlist 保护的 `/_wls/` fresh 探测可传播精确 `Connection: close`，业务位置不破坏 upstream Keep-Alive。
- HTTP/3 只检查项目托管 Nginx 的公网链路：必须同时满足 HTTP/3/QUIC 配置与模块、UDP 监听/转发、Alt-Svc，以及 PHP cURL 能力可用时项目 owner 绑定的 HTTP/3-only 请求真实到达 `/_wls/health?detail=1` 并返回匹配的 backend identity/config generation；Nginx 本地响应或边缘缓存不构成证据，客户端 verifier 不可用时明确保持 pending。
- Nginx shared session cache/ticket 已配置；正式门禁使用 `fresh-share-two-connection-pair-v1`，要求至少 8 个有效 fresh-TCP probe、`failed=0`、恢复握手 P95 ≤ 50ms，并把 same/cross Nginx Worker 恢复绑定到每对 issuer/probe PID。单个有效 Nginx Worker 的 cross 状态为 `not_applicable`；HTTP/3/QUIC Session Resumption 仍未验证。
- Nginx reload 的连续性是另一项门禁：`fresh-share-across-nginx-reload-v1` 必须用 reload 前 issuer 的同一个 SSL share，在新 generation 和不同 Nginx Worker PID 上返回 `r`，同时保持 Master PID/证书摘要一致，恢复握手 ≤ 50ms。普通 8/8 same/cross 样本不能替代这项跨代证明。
- `server:doctor` 分开报告公网 Nginx 与 WLS H1 回源，不从遗留 native/Caddy 代码推导公网能力。

验证示例：

```bash
nginx -V 2>&1
curl -k --http1.1 https://example.com/
curl -k --http2 https://example.com/
# 仅在 nginx -V 已确认 HTTP/3/QUIC 模块后执行
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

### 5.2 Nginx TLS 1.3、HTTP/3 与会话复用边界

公网 TLS 与协议能力只认 Nginx 的配置、编译能力和真实端点证据：

- 托管 Nginx 默认要求 TLS 1.3，并以 ALPN `h2` 为首选；不支持 H2 的客户端自动回退 HTTP/1.1。Nginx 到 WLS 始终使用明文 HTTP/1.1 Keep-Alive。
- 外部/宿主机 Nginx 不属于当前受支持启动路径；所有公网协议门禁只认本实例项目托管 Nginx。
- HTTP/3 只有在 Nginx 配置和编译能力、同一公网端口的 UDP、Alt-Svc、证书，以及项目 owner 绑定的 HTTP/3-only 请求真实穿过 Nginx 到达 WLS health、返回匹配的 backend identity/config generation 时才确认。PHP cURL verifier 不具备 HTTP/3 能力时明确报告 pending；Nginx 本地响应、边缘缓存或“配置存在”都不能写成运行时已验证。
- 普通 `server:start`、reload 和 restart 不下载、不安装、不编译任何协议组件或 Nginx。托管 Nginx 缺失时必须先显式执行 `server:nginx:install`。
- 托管配置使用 Nginx shared session cache/ticket 机制；`fresh-share-two-connection-pair-v1` 为每一证明对新建 SSL session share，只允许一个 fresh issuer 与一个 fresh-TCP resume probe。发布条件是有效 probe ≥ 8、失败为 0、恢复握手 P95 ≤ 50ms；多个有效 Nginx Worker 必须同时得到 pair-bound same/cross 恢复，单个有效 Nginx Worker 的 cross 状态为 `not_applicable`。该门禁只证明 Nginx 公网 TCP/TLS 恢复，不证明 PHP Stream、WLS-native 或纯 PHP Ticket，也不覆盖 HTTP/3/QUIC Session Resumption。
- graceful reload 额外使用 `fresh-share-across-nginx-reload-v1`：reload 前 fresh issuer 和 reload 后 fresh-TCP probe 共用同一个仍存活的 SSL share；probe 必须命中新 Nginx Worker PID 并返回 `r`。Doctor 会同时输出旧/新 Worker PID、恢复握手耗时和前后 config generation；缺任一证据时显示 pending/failed，而不是沿用普通 Session 样本。
- 2026-07-14/15 文档中出现的 `Reused`、WLS-native H2/H3 与 PHP Stream Session 证据只保留为历史记录；它们不证明当前 Nginx-only 数据面，也不能升级为当前发布结论。

验证当前公网 Nginx surface：

```bash
nginx -V 2>&1
openssl s_client -connect example.com:443 -servername example.com -tls1_3 -alpn h2
curl -k --http2 https://example.com/
curl -k --http1.1 https://example.com/
# 手工复核只能观察 reconnect；正式门禁由 verifier 归因同/跨 Nginx Worker
openssl s_client -connect example.com:443 -servername example.com -tls1_3 -reconnect
# 仅在 nginx -V 已确认 HTTP/3/QUIC 模块后执行
curl -k --http3-only https://example.com/
```

`server:benchmark --instance <name>` 默认测量 owner 绑定且 live 验证通过的项目托管 Nginx 公网端点，并记录实际 H2/H1/H3、TLS 1.3 与 QPS。每个物理 lane 依赖 `curl_multi` 自带的连接和 TLS Session cache，不使用额外 `CURLSH`；客户端缓存能力不等于服务端恢复，Nginx TCP/TLS Session Resumption 只采用 owner-bound Doctor/verifier 的恢复握手证据，而且不能推导 QUIC Session Resumption。百万请求的 latency 不采样，而是写入有界分片并精确外部归并；Benchmark report 仍为 schema v4，分位数、QPS 和门禁语义不变。只有显式内部 host/port 才测 WLS HTTP/1.1 回源，并必须标记 `wls_endpoint`。

## 6. 常用命令速查

| 命令 | 说明 |
|------|------|
| `php bin/w server:start [name] -p 9981` | 启动 WLS loopback H1 回源并在 READY 后启动、验证项目托管 Nginx |
| `php bin/w server:start -c 8` | 指定 Worker 数量 |
| `php bin/w server:start --install-deps` | 显式允许本次调用 `env:install`；可能联网、运行包管理器/PECL并修改 PHP 配置 |
| `php bin/w server:nginx:install` | 显式安装项目托管 Nginx；普通 start/reload/restart 不会调用 |
| `php bin/w server:start` | 启动已配置的 WLS/Nginx；托管二进制缺失时失败并提示显式安装 |
| `php bin/w server:start -d` | 守护进程模式 |
| `php bin/w server:start --cli` | **拒绝**：Nginx-only 启动不提供 CLI Server 分支 |
| `php bin/w server:status [name]` | 查看实例、Master/Worker、实际 RuntimeSelection、listener/event/SSL 与 policy digest |
| `php bin/w server:benchmark --instance <name>` | 以 endpoint schema v4 归因实例，输出 Benchmark report schema v4 |
| `php bin/w server:start -p 9981` | 启动项目托管 Nginx 与内部明文 H1 回源 |
| `--no-nginx` | **拒绝**：Nginx 是唯一公网边缘，不能跳过 |
| `php bin/w server:nginx:install\|start\|stop\|reload\|status` | 项目托管 Nginx 生命周期命令（`managed=true`） |
| `php bin/w server:doctor` | 报告公网 Nginx、H2/H1、H3 readiness 与 WLS H1 回源 surface |
| `php bin/w server:stop [name ...]` | 停止一个或多个 WLS 实例，例如 `server:stop api worker`；`managed=true` 时会先停托管 Nginx |
| `php bin/w server:start -r` | 平滑重启 |

## 7. 故障排查

| 现象 | 可能原因 | 处理 |
|------|----------|------|
| 访问域名 404 或非预期网站 | 后台未配置该域名或 URL 不匹配 | 在网站管理中为对应网站添加该域名，注意协议与端口 |
| 无法访问 | Nginx 未监听公网端口、证书/防火墙错误或回源未就绪 | 检查 Nginx 80/443、`nginx -t`、证书和 `server:status`；WLS 保持 loopback 回源，不改成公网监听 |
| Nginx 80/443 绑定失败 | 端口被占用或 Nginx 进程权限不足 | 处理 Nginx 的端口/权限；不要让 WLS 抢占公网端口 |
| 端口被占用 | 已有进程占用该端口 | 使用 `server:stop` 停止对应实例，或 `-p 9981` 等改端口 |
| Nginx 502 | WLS 未启动或端口错误 | 执行 `php bin/w server:status` 确认 WLS 监听端口，与 Nginx `proxy_pass` 一致 |
| 子进程停在 STARTING 且 PID=0 | 批量 launcher 创建后 `pcntl_exec` 失败或子进程在注册前退出 | 查看对应 `var/process/<process>.log`；launcher 会记录脱敏后的 errno、PHP 可执行文件名和脚本名，不再静默等待到 READY 超时 |
| 启动预检报 `compiled_factories.php` 构造参数为 `null` | 旧反射编译器把对象默认值错误降级为 `null` | 更新代码后执行 `php bin/w reflection:compile`；新编译器会让无法安全字面量化的类回退到一次性反射，且预检失败返回非零退出码 |
| Worker 异常退出 | 进程崩溃 | Master 默认启用，会自动重启异常 Worker |
| `server:reload` 超时、断线或 Master 明确失败 | 控制面没有完成终态，或实例启动预算高于默认估算；**仍有在途写缓冲/Fiber/半包请求**时会保留旧 Worker 并返回非零（「保留排水」） | 命令会返回非零退出码；先用 `server:status` 核实状态。Master 排水等待 ≥ Nginx upstream idle + 5 秒（默认 10 秒），发给 Worker 的软期限再减 5 秒余量；空闲 keep-alive 会在 Worker 软期限主动关闭，不再单独导致失败。如需延长可配置 `wls.orchestrator.reload_drain_timeout_sec` / `reload_wait_timeout_sec`（只会提高、不会压低安全估算）。停机型逃生口仍是 `server:reload -f`。 |
| TLS 1.3 / H2 门禁失败 | Nginx 二进制、配置、证书或真实握手不满足要求 | 检查 `nginx -V`、`nginx -t`、证书指纹、TLS 1.3 与 ALPN；不得回退到 WLS/Caddy 终结 |
| H3 未 READY | Nginx HTTP/3/QUIC 配置、UDP、Alt-Svc、证书或 owner 绑定的 HTTP/3-only WLS health 请求任一失败；也可能是 PHP cURL verifier 不具备 H3 能力 | 确认请求未被 Nginx 本地响应/边缘缓存截断，并核对 backend identity/config generation；失败时保持 H2/H1，verifier 不可用时保持 pending |
| TLS Session 恢复门禁失败 | `fresh-share-two-connection-pair-v1` 未达到有效 probe ≥ 8、`failed=0`、P95 ≤ 50ms，或 pair-bound same/cross Worker 状态不满足当前 Worker 数 | 核对 shared cache/ticket 与 issuer/probe PID 证据；不要用该结果宣称 QUIC Session Resumption |
| Nginx reload 后 TLS 连续性门禁失败 | `fresh-share-across-nginx-reload-v1` 未保留同一 SSL share、Master/证书身份变化、generation 未变化、新旧 Worker PID 相同、结果不是 `r` 或握手 > 50ms | 保留旧 owner/recovery 证据并检查 shared cache/ticket 与 graceful reload；不能用 reload 后重新创建的 8/8 样本替代跨代 Session |
| 跨平台复制后模板仍指向 `/Users/...` 或 `C:\\...` | `modules.php`、路由或模板缓存由另一平台生成 | 运行时会用模块稳定 `path` 重定位到当前 `BP/app/code`，并按 OS + BP 隔离模板缓存；若目标模块目录本身不存在，重新同步代码后再启动 |
| Windows 复制项目后 SQLite 仍指向原系统绝对路径 | SQLite 配置保存了另一平台下 `app/` 或 `var/` 内文件的绝对路径 | 运行时只在当前项目存在同后缀真实文件时重定位到当前 `BP`；外部数据库路径、`:memory:` 与 `file:` URI 保持原样 |
| Windows UNC 项目启动慢但本地磁盘正常 | SMB/Parallels 共享目录的元数据与大量 PHP include 延迟 | WLS 自动识别 UNC：首页 READY 单次预算使用 60 秒（本地盘 30 秒），Orchestrator 默认基线使用 150 秒（本地盘 90 秒），总启动仍受 300 秒硬上限约束，且显式配置优先。UNC 只作为兼容性验证；正式 QPS、冷启动和 Worker 批量启动门槛仍必须在 Windows 本地磁盘副本上测量 |
| 需要定位 WLS 冷启动长尾 | 旧 trace 只有秒级时间，无法区分 CLI 引导、配置、依赖、证书、编译、策略和 Master 内部阶段 | 仅诊断时设置 `WLS_STARTUP_TRACE=1`；`var/log/wls-startup-trace.log` 的每条记录包含进程内 `sequence/mono_ns/total_ms/delta_ms/process_elapsed_ms/memory_mb`。比较同一 PID 的 `delta_ms`，并用 `process_elapsed_ms - total_ms` 识别首条 trace 之前的 PHP/CLI 引导；关闭变量后不计时、不写 trace。 |

---

**版本：** 2.0.0-dev
**更新时间：** 2026-07-27
**状态：** 公网入口已收敛为唯一项目托管 Nginx，WLS 固定 loopback 明文 H1 回源；所有平台 `auto` 都使用内部 Direct，Windows 为 `worker_ports + stream_select`，Linux 为 `reuseport + event` 优先且在能力不可用时回退 `shared_fd + event`，macOS 为 `shared_fd + event`。最新独立实例已在 macOS、Parallels Windows 11 ARM（PHP 8.4.23 x64-on-ARM）和 OrbStack Linux ARM64 上完成首页 Process FPC、TLS 1.3、H2/H1 与 Nginx reload Session 连续性验证；真实 H3 只在 verifier 可用且通过 owner-bound QUIC 门禁时记为完成。2026-07-27 的 2/4/8/16 矩阵中，Windows 8 Worker 首轮 H2 请求 P95 550.715ms 未过门槛；新独立实例随后两轮相同稳态门禁分别为 1,224.52 QPS/P95 258.483ms 与 1,229.04 QPS/P95 257.145ms，均通过且 Worker 完全均衡。首轮失败样本仍保留。Linux `shared_fd` 的 fresh H1 公平性不稳定；同机 A/B 的 `reuseport` 两轮 `max/min=1.171/1.166` 均通过，新的 auto 实例为 H2 18,208.24 QPS/P95 17.734ms、fresh H1 `max/min=1.145`，因此 auto 已改为 reuseport 优先。最终 16 Worker auto/reuseport 复验进一步完成显式 Nginx 公网端口 H2 10,000/10,000（16,170.14 QPS、P95 28.668ms、99.84% 复用）、公网 fresh H1 10,000/10,000（2,353.18 QPS、TLS P95 53.751ms、Worker `max/min=1.245`）和内部唯一实例归因的正式公平性门禁 `max/min=1.237 <= 1.3`；两份 Docker 公网端口报告为 `manual_unattributed`，不替代 `optimal` Doctor 的 owner 证据。同一项目托管 Nginx 的独立 aioquic 门禁完成真实 H3 160/160，reload 后 H3 再次 200；内置 Browser 的首页标题/H1/正文/DOM 正常且 console warning/error 为 0。`fresh-share-across-nginx-reload-v1` 已在三平台分别证明同一 TLS 1.3 Session 跨 Nginx Worker generation 恢复，最终 Linux 样本为 PID 414 → 724、1.634ms。Windows 官方预编译 Nginx 无 H3 模块时保持 H2/H1。上述证据不证明 PHP Stream、WLS-native、纯 PHP Ticket 或 HTTP/3/QUIC Session Resumption；发布提交仍需在多人共享脏工作区中按精确范围单独授权和审查。

动态路径预热默认只包含首页 `/`。业务模块需要预热商品、分类或账户页面时，应显式配置 `wls.worker.dynamic_critical_paths` / `wls.worker.dynamic_hot_paths`，或通过 `Weline_Server::dispatcher::warmup_paths` 发布真实路由；Server 不内置任何演示业务 URL。
