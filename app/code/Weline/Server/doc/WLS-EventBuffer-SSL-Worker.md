# WLS EventBuffer SSL Worker 实验结论

## 背景

WLS 保留 `stream_socket_enable_crypto()` SSL worker 作为默认稳定路径，并新增实验配置 `wls.ssl.engine=event_buffer`。

实验目标是验证 PHP-only 架构下，是否可以用 `EventBufferEvent::sslSocket(..., EventBufferEvent::SSL_ACCEPTING)` 把 TLS accept、handshake、read、write 交给 libevent SSL bufferevent，减少用户态手动推进 TLS 握手的抖动。

PHP 官方文档说明 `EventBufferEvent::sslSocket()` 用于在 socket 上创建 SSL buffer event，`socket` 参数可以是 stream/socket resource、numeric file descriptor 或 `null`；`EventBufferEvent::SSL_ACCEPTING` 表示服务端 SSL 协商状态。

- https://www.php.net/manual/en/eventbufferevent.sslsocket.php
- https://www.php.net/manual/en/class.eventbufferevent.php

## 当前实现

- `wls.ssl.engine=stream` 启动现有 `worker_ssl.php`，这是默认值。
- `wls.ssl.engine=event_buffer` 启动 `worker_ssl_event.php`。
- `stream` 与 `event_buffer` 是显式 engine，不做失败后静默降级。
- `event_buffer` worker 首版只使用默认/通配证书，不声明已经完成 per-connection SNI 切换。
- `event_buffer` worker 热路径不扫描磁盘、不查 DB、不 reload JSON。

## Windows 结论

本机环境：

- PHP 8.4.16 TS x64
- PECL event 3.1.4
- libevent headers 2.1.8-stable
- OpenSSL 3.0.18
- Windows 原生 PowerShell

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

- Windows 原生开发环境继续使用 `wls.ssl.engine=stream`。
- 若 fresh-connect 70ms 是硬目标，Windows 原生 PHP 内置 TLS 不应继续无界加复杂度。
- 可选生产路径仍是外部 TLS 终止，例如 nginx/Caddy。
- Linux 或后续 event 扩展版本可重新验证 `event_buffer`，但必须先用最小 SSL bufferevent server 通过 smoke，再接入 WLS。

## 最小验收脚本

验证顺序：

1. 启动最小 `EventListener + EventBufferEvent::sslSocket()` SSL server。
2. 用 `curl -k https://127.0.0.1:<port>/` 触发 TLS handshake。
3. 确认进程不会 native 退出，且能返回固定 HTTP 200。
4. 只有最小脚本通过，才允许开启 WLS `event_buffer` worker 浏览器验证。

当前 Windows 结果是不通过。
