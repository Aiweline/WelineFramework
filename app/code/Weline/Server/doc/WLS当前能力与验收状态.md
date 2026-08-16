# WLS 当前能力与验收状态

> 更新时间：2026-08-16。本文是 WLS **当前能力与验收状态**的权威入口。架构文档描述长期契约，带日期的报告与冻结记录描述历史证据；两者都不能替代本文的当前判定。

## 状态口径

| 状态 | 含义 |
|---|---|
| 已验证 | 当前源码已在所列环境完成真实运行验证；结论只覆盖明确写出的 surface 与环境。 |
| 已实现，待验证 | 实现或静态合同已存在，但缺少目标平台、系统服务、冷重启或专用容量环境的真实证据。 |
| 未实现 | 当前实现不提供该能力；不得写成 pending、条件支持或已完成。 |
| 外部环境延期 | 代码内无法独立闭合，等待用户提供公网、专用 Runner 或生产级平台环境。 |

## 当前结论

当前状态为 **LOCAL_RUNTIME_VERIFIED / GA_BLOCKED_EXTERNAL**：

- 当前源码的纯 WLS 直接数据面已在 macOS、Linux 和 Windows QEMU 环境完成各 100 万次真实 HTTP/2 请求，三份报告均为 0 错误。
- 该结论只证明直接 WLS 高端口的 TLS/HTTP/2 请求响应与本轮运行稳定性，不等于宿主 Gateway、公网 80/443、真实 CA/DNS、系统服务冷重启或全部高级协议已经验收。
- Windows 结果来自 Windows 11 ARM64 QEMU guest 中的 x64 兼容执行，不得写成物理 x64 Windows、MSVC 构建或 SCM 冷重启已通过。
- 非 WLS 模块的数据库迁移等问题只记录为外部依赖，不再扩大 WLS 的实现范围。

## 当前源码真实运行证据

| 平台 | 环境与 surface | 结果 | 性能 | 报告与 SHA-256 |
|---|---|---|---|---|
| macOS | 本机，纯 WLS 直接 HTTP/2 health | 1,000,000 / 1,000,000，0 error | 15,321.14 QPS；P95 26.503 ms；65.269 s | `var/log/wls/benchmark_report_20260815_211429_465215_wls-health_pid93850.json`; `1950814d6f2eb351f6ead936bddc713a3a7a00862f3bc3c2a9b6e693eeba0689` |
| Linux | Kali/Orb，纯 WLS 直接 HTTP/2 health | 1,000,000 / 1,000,000，0 error | 13,013.76 QPS；P95 29.377 ms；76.842 s | `var/log/wls/benchmark_report_20260815_212037_699180_wls-health_pid10429.json`; `e4156360fc0ce25b1b6725a4186f2f44d6e2b58d5b634d84d94901743f6a2e9f` |
| Windows | Windows 11 ARM64 QEMU guest，x64 兼容执行，纯 WLS 直接 HTTP/2 health | 1,000,000 / 1,000,000，0 error | 4,620.68 QPS；P95 136.531 ms；216.418 s | guest: `C:\wls-million-current-20260815-v90\var\log\wls\benchmark_report_20260815_210414_812832_wls-health_pid8988.json`; `976061de78bd078b18a0622cd3a2f7a179904027ccc7ef3672588138e098a632` |

三份报告均为 HTTP/2、4 个物理 lane、quality gate 通过。它们没有验收 managed Nginx/Gateway 公网路径，也没有验收 fresh-worker 均衡或跨 Worker TLS 会话恢复。

## 能力矩阵

| 能力 | 当前状态 | 边界 |
|---|---|---|
| 纯 WLS TLS 1.3 + HTTP/2/HTTP/1.1 高端口 | 已验证 | 普通请求/响应已覆盖；仅覆盖各报告所列直接 WLS endpoint。 |
| 普通 HTTP/2 多路请求/响应 | 已验证 | 不包含 SSE 的持续 DATA-frame 流。 |
| 纯 WLS HTTP/3 / QUIC | 未实现 | HTTP/3 只能由具备对应模块的 managed Nginx/Gateway 提供。 |
| HTTP/2 SSE DATA-frame 流式输出 | 未实现 | 当前 SSE 文档不得被解释为 H2 SSE 已支持。 |
| managed Nginx/Gateway HTTP/3 | 已实现，待验证 | 必须由同一 owner/generation 绑定的真实 HTTP/3-only 请求证明；配置存在或静态合同不算通过。 |
| 纯 WLS TLS Session Ticket / 跨 Worker 恢复 | 已实现，待验证 | 当前没有可用于发布结论的跨 Worker 真实证据，不能写“已支持”。 |
| macOS root system-domain LaunchDaemon 冷重启恢复 | 已实现，待验证 | 本轮未重启 macOS；残留进程清理不等于冷重启证明。 |
| Windows MSVC x64、SCM 自动恢复与冷重启 | 已实现，待验证 | QEMU x64 兼容运行和静态/MinGW 合同不能替代 MSVC/SCM 真机证据。 |
| Windows 专用 10 GiB / 65,536 inode 容量门禁 | 已实现，待验证 | 需要专用 Windows Runner 的真实结果。 |
| 公网 CA/DNS、真实 443 首签 | 外部环境延期 | 按用户决定，待提供上线测试环境后最后执行。 |
| fresh-worker 均衡、跨 Worker 会话恢复的专项性能门 | 已实现，待验证 | 本轮百万报告不能据此宣称通过。 |

## 文档使用规则

- [README.md](README.md) 与本文共同构成当前导航和状态入口。
- [WLS架构图.md](WLS架构图.md) 描述长期架构契约；“设计存在”不等于“当前已验证”。
- [WLS模式部署指南.md](WLS模式部署指南.md) 描述可操作配置；协议状态必须服从本文。
- [WLS-Gateway使用指南.md](WLS-Gateway使用指南.md) 中的旧测试数字只作为历史回归证据。
- [开发日志.md](开发日志.md) 保留冻结 checkpoint 和历史结果；若其历史状态与本文冲突，以本文的当前状态为准。
- 公网和系统级证据完成后，必须同时更新本文、开发日志和相应验收记录，不能只改一处。
