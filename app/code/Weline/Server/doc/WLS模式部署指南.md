# WLS 模式部署指南

WLS（Weline Server）是框架内置的常驻内存 HTTP 服务器，不依赖 Nginx/PHP-FPM 即可对外提供 Web 服务。**默认监听 80/443 直连省去 Nginx**，也可 `-p 9981` 等自定义端口。本文说明如何在 WLS 模式下部署及接入域名。

## 1. 模式说明

| 模式 | 说明 |
|------|------|
| **正常模式** | Nginx 监听 80/443，按 `server_name` 转发到 PHP-FPM 或 WLS；域名在 Nginx 与后台均需配置。 |
| **WLS 直连模式（推荐）** | WLS 默认监听 **80（HTTP）/ 443（HTTPS）**，无需 Nginx；根据请求的 Host 与后台「网站/域名」配置识别当前网站。 |
| **WLS 自定义端口** | 使用 `-p 9981` 等监听高端口，适合与 Nginx 反代或开发环境。 |

接入含义：**请求能到达 WLS 端口** + **在后台为该网站配置域名**。

## 2. 环境配置

在 `app/etc/env.php` 中配置 `server` 或 `servers`（多实例）：

```php
'server' => [
    'host' => '0.0.0.0',     // 默认 0.0.0.0 允许外网访问；127.0.0.1 仅本机
    'port' => 443,           // 监听端口（默认 80/443；改 9981 等可自定义）
    'worker_count' => 'auto', // 'auto' 智能模式：开发环境固定 2 个，生产环境按 CPU 核心数计算；或数字如 4、8
    'mode' => 'io',          // io | cpu
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

## 3. 启动与停止

```bash
# 启动默认实例（默认监听 80/443，HTTPS 用 443，省去 Nginx）
php bin/w server:start

# 改用自定义端口（如 9981）
php bin/w server:start -p 9981

# 指定地址与端口
php bin/w server:start --host 0.0.0.0 -p 443

# 启动（Master 进程默认启用，监控并自动重启异常 Worker）
php bin/w server:start

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

### 3.0 架构说明（多 Master / 多 Worker / 流量分发）

**多 Master（多实例）**：已支持。每个 `server:start [实例名]` 对应一个独立实例，每个实例有：

- **1 个 Master 进程**：不监听 HTTP/HTTPS，只负责重载信号（SIGHUP）、健康巡检（每 5 秒）、Worker 异常退出时重启。
- **N 个 Worker 进程**：每个 Worker **各自监听一个端口**，端口为 `port, port+1, port+2, ...`（例如 443、444、445、446）。
- **0 或 1 个 HTTP 重定向进程**（仅 HTTPS 启用时）：只监听 80，将 HTTP 301 到 HTTPS，**不监听 443**。

实例之间通过实例名区分（如 `default`、`api`），实例信息存于 `var/server/instances/{实例名}.json`，多实例互不干扰。

**Master 是否做流量分发**：**否**。当前设计下：

- **Master 不监听 443，也不做请求转发**。它只做进程管理（健康检查、重启 Worker、写重载标记）。
- **HTTPS 请求**：由各 Worker **直接监听各自端口**（443、444、445…），**没有**「单一 443 入口 → Master 或某进程再分发给多 Worker」的链路。
- 因此：**只压测或只访问主端口（如 443）时，只有 Worker #1 收到流量**，其余 Worker（444、445…）闲置。多 Worker 要真正分担流量，需外部或后续「单口入口 + 内部分发」方案。

**总结**：

| 能力 | 是否实现 | 说明 |
|------|----------|------|
| 多 Master（多实例） | ✅ 已实现 | 多实例 = 多份 Master+Workers，按实例名区分 |
| Master 监控/重启 Worker | ✅ 已实现 | 健康检查、异常重启、重载信号 |
| Master 监听 443 并分发给 Worker | ❌ 未实现 | Master 不监听任何业务端口 |
| 单端口 443 多 Worker 负载均衡 | ❌ 未实现 | 当前为多端口（443,444,445…），需 Nginx upstream 或后续 Acceptor |

若需要「只请求一个端口（如 443）且多 Worker 共同处理」，目前做法：**用 Nginx 监听 443，`upstream` 轮询/均衡到 `127.0.0.1:443,444,445,...`**；或使用单 Worker（`-c 1`）只监听 443。

### 3.1 多 Worker 端口与单口压测

WLS 默认每个 Worker 监听**不同端口**（`port, port+1, port+2...`）。**只访问或只压测主端口（如 443）时，只有第一个 Worker 响应，其他 Worker 无流量。**

- **单 Worker**：对 80/443 单口直连场景，建议 `-c 1`，仅一个 Worker 监听该端口。
- **多 Worker + 单口入口**：用 Nginx 监听 80/443，`upstream` 均衡到多个 Worker 端口（见下方示例）。
- **多 Master 共用 HTTP 重定向**：多实例时，80 端口的 HTTP 重定向进程可共用（仅一个监听 80）。

### 3.2 进程安全

框架通过 `--name=weline-xxx` 标识所有服务器进程（Worker、HTTP 重定向）。端口被占用时：
- 如果是**框架进程**（`-r` 强制重启时）：可自动杀死并重启
- 如果是**非框架进程**：不予杀死，提示用户手动处理，避免误杀系统服务

## 4. 域名接入

### 4.1 在后台绑定域名（必做）

框架根据 **HTTP Host + 完整 URL** 匹配网站，域名必须在后台配置：

1. 进入 **网站管理**（Weline Websites）
2. 编辑对应网站，在 **域名/地址** 中添加要使用的域名，例如：
   - `www.example.com`
   - `example.com`
   - 直连 WLS 端口时可用：`example.com:9981`
3. 保存后，该域名的 URL 会参与框架的网站解析，请求带此 Host 即识别为该网站。

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

**方式 D：Nginx 单口 443 + 多 Worker 均衡（单口压测/生产多 Worker）**

若 WLS 以多 Worker 启动（如 `-c 4`），Worker 分别监听 443、444、445、446。要让「只访问 443」的流量分摊到 4 个 Worker，可由 Nginx 监听 443，用 `upstream` 轮询到多个端口：

```nginx
upstream wls_https {
    server 127.0.0.1:443;
    server 127.0.0.1:444;
    server 127.0.0.1:445;
    server 127.0.0.1:446;
}
server {
    listen 443 ssl;
    server_name www.example.com example.com;
    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;
    location / {
        proxy_pass https://wls_https;
        proxy_ssl_verify off;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

这样对外只有一个 443，Nginx 将请求均衡到 4 个 Worker 端口，多 Worker 才能同时接流量。

## 5. HTTPS / SSL

- **默认**：WLS 启动时自动启用 HTTPS，无证书时对本地/内网（127.0.0.1、localhost 等）**自动签发自签证书**，证书按域名存放在 `app/etc/ssl/{域名}/`。
- **已有证书**：自动检测 `app/etc/ssl/` 下证书，或使用 `--ssl-cert` / `--ssl-key` 指定。
- **生产**：可继续用 Nginx 终结 HTTPS（80/443）反代到 WLS 高端口，或直连 WLS 80/443（需 root/setcap）。
- **HTTP 重定向到 HTTPS**：**Master 默认启用**。HTTPS 启用时，Master 会**自动启动一个独立的 HTTP 进程**（不计入 Worker 数），监听 80 端口，将 HTTP 请求 301 重定向到 HTTPS。

```bash
# 默认即 HTTPS（自动证书或 app/etc/ssl/）
php bin/w server:start

# 使用已有证书
php bin/w server:start --ssl-cert /path/to/cert.pem --ssl-key /path/to/key.pem
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
| `php bin/w server:status [name]` | 查看实例、Master PID、Worker、HTTP 重定向状态 |
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
| Worker 异常退出 | 进程崩溃 | Master 默认启用，会自动重启异常 Worker |

---

**版本：** 1.1.0  
**更新时间：** 2026-02-03  
**状态：** ✅ 已实现（默认 80/443、Master 进程、自签证书、HTTP→HTTPS 重定向）
