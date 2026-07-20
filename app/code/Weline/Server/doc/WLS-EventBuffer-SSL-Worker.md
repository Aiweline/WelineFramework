# WLS EventBuffer SSL Worker 实验结论与当前边界

## 背景

WLS 保留 `stream_socket_enable_crypto()` SSL worker 作为默认稳定路径，并新增实验配置 `wls.ssl.engine=event_buffer`。

实验目标是验证 PHP-only 架构下，是否可以用 `EventBufferEvent::sslSocket(..., EventBufferEvent::SSL_ACCEPTING)` 把 TLS accept、handshake、read、write 交给 libevent SSL bufferevent，减少用户态手动推进 TLS 握手的抖动。

PHP 官方文档说明 `EventBufferEvent::sslSocket()` 用于在 socket 上创建 SSL buffer event，`socket` 参数可以是 stream/socket resource、numeric file descriptor 或 `null`；`EventBufferEvent::SSL_ACCEPTING` 表示服务端 SSL 协商状态。

- https://www.php.net/manual/en/eventbufferevent.sslsocket.php
- https://www.php.net/manual/en/class.eventbufferevent.php

## 当前实现

- `wls.ssl.engine=stream` 启动 `worker_ssl.php`，是当前 Direct 与 Dispatcher 生产拓扑的唯一受支持 TLS engine。
- `worker_ssl_event.php` 仍作为隔离的实验实现保留，不是 `auto` 可选数据面。
- 选择 `event_buffer` 时不做静默降级：Direct 在 `RuntimeStrategyResolver` 预检拒绝；带认证 PROXY v2 前缀的 Dispatcher 在预检拒绝；Windows 原生环境在 Provider 预检拒绝。
- Dispatcher 拒绝的原因是当前 EventBuffer worker 会在消费认证 PROXY v2 前缀前开始 TLS；Direct 拒绝则是共享监听与策略一致性门禁尚未完成。两者都必须明确失败，不得启动后丢失身份或策略信息。
- `stream` worker 支持用 `wls.ssl.protocols` 或 `wls.ssl.server_protocols` 固定服务端 TLS 协议列表，例如 `['tls1.2']`；未配置时仍默认启用最高可用的 TLS 1.3 + TLS 1.2 组合。
- `event_buffer` worker 首版只使用默认/通配证书，不声明已经完成 per-connection SNI 切换。
- `event_buffer` worker 热路径不扫描磁盘、不查 DB、不 reload JSON。

## 复用与多路复用语义

- HTTP keep-alive 是同一 TCP/TLS 连接连续处理请求；HTTP/2 multiplex 是同一连接并发处理多个 stream。两者都会直接减少完整 TLS 握手次数。
- HTTP/3 运行在 QUIC/UDP 上，不参与 TCP ALPN；HTTPS 响应通过 `Alt-Svc` 宣告 h3，支持的客户端随后自动升级，TCP 入口仍按 `h2,http/1.1` 协商。
- `session_ticket` 配置或收到 NewSessionTicket 只证明服务端签发 ticket，不证明下一条连接能够恢复会话。能力门禁必须用第二次握手明确显示 `Reused`。
- ALPN 配置可写入 stream context 不等于真实协商成功；能力快照分别发布 `configured` 与 `runtime_verified`，后者必须来自真实 TLS 握手选择结果。
- 默认关闭无消费者的 `capture_session_meta`，避免 fresh TLS 握手为不可观测数据分配 Session 元数据；协议与复用验证由控制面探针和 Benchmark 承担。
- 77,594B 首页在常用 1200B QUIC payload 下约 65 包；将原生写批次从 64 提升到 128 的五轮实测中位 QPS 仅 1328.73，未通过性能门禁，已恢复 64。瓶颈继续定位到响应解析/复制与 Darwin datagram router 链路。
- 2026-07-18 macOS PHP 8.4 最小验证中，复用同一个 `EventSslContext` 的第二次 TLS 1.3 握手显示 `Reused`；同一 PHP stream listener/context 的第二次握手仍显示 `New`。因此 PHP 8.4 stream 生产路径依赖 HTTP/2 multiplex 与 keep-alive 避免握手，不宣称跨连接 session resumption。PHP 8.6 可选的外部有状态 Session Cache 是另一条独立、默认关闭且必须单独取证的 stream 路径。
- EventBuffer 自动启用仍需同时通过：两次握手复用、ALPN、per-connection SNI、WorkerPolicyKernel、Direct/Dispatcher 身份、FPC/安全策略和滚动生命周期门禁；任一缺失都不得自动切换。

## Windows 结论

已验证环境：

- 原始复现：PHP 8.4.16 TS x64、PECL event 3.1.4、libevent headers 2.1.8-stable、OpenSSL 3.0.18、Windows 原生 PowerShell。
- 2026-07-18 复测：Parallels `Windows 11` ARM 虚拟机中的 PHP 8.4.23 NTS x64、PECL event 3.1.6；PHP OpenSSL 与 `EventSslContext` 均为 OpenSSL 3.0.21。
- 新环境已经消除 event/PHP OpenSSL 版本不一致，但 `EventListener` fd 与 stream accept 两种 `EventBufferEvent::sslSocket()` 入口仍出现连接 reset，进程在首个 accept 后退出。因此问题不能归因于旧扩展版本或 OpenSSL 小版本不一致。

## 崩溃日志

Windows Application Error / WER 记录：

- EventType: `APPCRASH`
- App: `php.exe`
- App version: `8.4.16.0`
- Fault module: `php_event.dll`
- Fault module version: `8.4.1.0`
- Exception code: `0xc0000005`
- Fault offsets observed: `0x000000000001e1aa`, `0x000000000001e534`, `0x000000000001e75a`
- Crash dump path example: `C:\Users\17142\AppData\Local\CrashDumps\php.exe.78416.dmp`

`php -n` 最小环境只加载 `openssl,sockets,event` 时仍然复现：

- Loaded modules: `php.exe`, `php8ts.dll`, `php_sockets.dll`, `php_openssl.dll`, `libcrypto-3-x64.dll`, `libssl-3-x64.dll`, `php_event.dll`
- Same WER signature: `php_event.dll`, `0xc0000005`, offset `0x1e1aa`
- This excludes Xdebug, OPcache, curl, PDO, framework runtime, and WLS worker code as direct crash causes.

最小复现结果：

- `EventListener` accept 后传入 `int` fd，调用 `EventBufferEvent::sslSocket()` 后 PHP 进程 native 退出。
- 手动 `stream_socket_server()` + `stream_socket_accept()` 得到 `stream` resource，再调用 `EventBufferEvent::sslSocket()`，同样在首个 TLS accept 后 native 退出。
- `EventBufferEvent::createSslFilter()` 变体也无法避免该问题。
- 按 PHP 官方 SSL echo server 示例顺序复现时，`EventUtil::sslRandPoll()` 返回 `true`，`EventBufferEvent::sslSocket()` 能返回对象，但随后第一次 `$bev->enable(...)` 会导致 PHP 进程 native 退出。
- 将顺序改成先 `$bev->setCallbacks(...)` 再 `enable(...)` 时，进程会在第一次 `setCallbacks(...)` native 退出。
- 本机扩展实际暴露的是 `EventBufferEvent::createSslFilter()`，不是手册里的 `sslFilter()`；`createSslFilter(EventBufferEvent $unnderlying, EventSslContext $ctx, int $state, int $options = 0)` 可通过反射确认。

因此，当前 Windows 原生环境下 `event_buffer` 不是可用的 WLS SSL worker 路径。为避免假启动和首请求崩溃，Provider 会在 Windows 上直接拒绝 `wls.ssl.engine=event_buffer`。

## 运行策略

- Direct、认证 Dispatcher 与 Windows 原生环境都使用 `wls.ssl.engine=stream`；这是启动前强制门禁，不是运行期 fallback。
- 若 fresh-connect 70ms 是硬目标，Windows 原生 PHP 内置 TLS 不应继续无界加复杂度。
- WLS 不引入外部 TLS 代理：Windows 继续使用稳定的 stream engine，并以 HTTP/2 multiplex、keep-alive 和连接寿命策略减少重复握手。PHP 8.6 外部有状态 Session Cache 已取得持久功能证据，但最新 x64-on-ARM 预发布样本的恢复握手 P95 为 156.236ms；固定 50ms 恢复延迟、原生稳定运行时和三平台矩阵未通过前，只能报告 durable evidence，不能宣称当前实例 active 或生产就绪。
- Linux/macOS 或后续 event 扩展版本可重新验证 `event_buffer`，但必须先用最小 SSL bufferevent server 通过 smoke，再分别完成 Direct 共享监听和 Dispatcher 认证前缀的协议门禁。

## 最小验收脚本

验证顺序：

1. 启动最小 `EventListener + EventBufferEvent::sslSocket()` SSL server。
2. 用 `curl -k https://127.0.0.1:<port>/` 触发 TLS handshake。
3. 确认进程不会 native 退出，且能返回固定 HTTP 200。
4. 只有最小脚本通过，才允许开启 WLS `event_buffer` worker 浏览器验证。

当前 Windows 结果是不通过。
