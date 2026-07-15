# 结果

状态：`validated_cross_platform_candidate_windows_scale_pending`

已落地三个定向修复：每 Worker 动态预热串行门禁、READY-only 单槽恢复提交、Darwin shared listener 500us busy cooldown。失败实验（1000us cooldown、一次 Worker 重连风暴、动态首渲染 310.95ms）均保留在验证记录中，没有作为通过数据删除。

当前 macOS 实例的 QPS、动态首渲染、百万请求和压测中单 Worker 故障恢复均已取得可复核报告；Browser 验收、PHP 语法、架构检查、框架编译、策略检查和 GitNexus `detect_changes(master)` 已完成。测试实例和临时攻击白名单均已清理。

后续重复实现审计纠正了上一版结论：5 个 warmup 失败中包含一处真实的 READY 后重复 bootstrap 缺省值回归，其余是 Framework 通用 header/首页契约已经替代业务硬编码后的旧断言；同时恢复队列确有 service PID 覆盖 tracking root PID 的实现回归。两处实现已收口，readiness v3 测试夹具也已同步。当前 `WlsRuntimeInternalWarmupInputTest` 为 23/23、52 assertions，`ServiceOrchestratorStartupTest` 为 93/93、440 assertions。

macOS 回归验证由两个独立实例共同完成：`1645` 负责 Browser 首页/带 Key 登录页可见性与 Console 0 error/warn，`1710` 负责 4 Worker Direct READY、46.52ms 动态首页、后台 Key 404/200 对照和 TLS 1.3 协商；没有把被 Chrome 客户端拦截的 9882 页面伪记为可见性通过。临时白名单与全部测试进程已清理。Windows/Linux 原生平台门禁仍未执行，因此整体跨平台计划不标记 complete。

核心提交 `83ccfe358` 已以 fast-forward 方式同步到 `codex/wls-tls13-extreme-performance`、`dev`、`master`，Gitee/GitHub 六个远端引用一致；未生成重复 merge/cherry-pick 提交，隔离 worktree 已切回并保留功能分支。

发布扩散没有伪报成功：分项脚本不识别 worktree `.git` 文件，随后逐站预检发现 `App/Skill` 不是 Git 仓，`摩托车` 17 项脏改动、`Official-Site` 1285 项、`WeShop` 1354 项，均未执行 `core:update`。分仓 `--all` dry-run 覆盖 32 个映射仓：6 个 no-change、25 个 dirty 拒绝覆盖、`ThemeFancy` 历史仓名无法 clone；不存在可合法提交的干净差异仓，因此未强行 mirror/tag/push。

当前实现仍有一个结构性缺口：三个 Worker 入口还不是薄 Transport Adapter，分别约 5.5K/6.5K/2.1K 行；只完成了 HTTP wire helper、策略内核、FPC/static、控制面和 telemetry 的共享。该缺口与 Linux/Windows 原生矩阵进入下一阶段，不能通过重复运行 macOS 百万压测代替。

公开协议层现已补齐自动协商：同一 WLS HTTPS 端口可实际接收 HTTP/3、HTTP/2 和 HTTP/1.1，兼容客户端自动回退，并保留 TLS session reuse 与连接多路复用。实现没有把第二套安全/FPC 规则放进 Caddy；所有请求仍必须通过同一 WorkerPolicyKernel。Direct 精确定义为无 WLS Dispatcher，Dispatcher 模式保留 Windows 默认行为。macOS 专用实例已验证两种拓扑、三种协议、私有 Worker 认证、后台 Key 与 Process FPC；Linux/Windows 原生安装器及 HTTP/3 网络矩阵仍未由当前机器证明。

协议边缘的连接池身份语义也已校正：连接级 PROXY v2 不能代表复用连接里的多个公网客户端，因此 Direct Worker 和内部 Dispatcher 只把已启用 edge 配置下的 loopback peer 作为 transport whitelist，保留实例级连接门禁；逐请求真实客户端身份、安全 Ban 和限流仍由 WorkerPolicyKernel 使用实例 token 认证后的 envelope 执行。该设计避免所有用户被聚合成 `127.0.0.1` 后误伤，同时不关闭安全规则。

最终 macOS 数据面复核通过 h1/h2/h3 实际 version、TLS 1.3 session reuse、HTTP/2/3 单连接多 stream、FPC HIT、后台 Key 404/200 和私有入口 403。最新五轮首页中位数为 Direct 7,853.61 QPS / p95 6.082ms、Dispatcher 5,775.71 QPS / p95 9.052ms，Direct 分别改善 35.98% / 32.81%，两项 20% 拓扑门槛均通过。默认 `3000/60s/IP` 对单源首页压测返回 429 是预期限流，临时白名单已经恢复并重新发布正式策略。

READY 动态首页的旧失败也已收口：冷链第一次有效渲染超过 70ms 时不再直接写入 READY，而是在同一有界事务内复验已经填充的进程缓存。Direct 完整停启约 2 秒后，4 Worker 最终回执为 16.48–18.70ms；Dispatcher rolling reload 为 12.08–21.24ms，全部 `attempts=2`、FPC BYPASS、低于 70ms。Linux/Windows 原生安装器、平台拓扑和长稳矩阵仍没有当前机器上的权威证据，因此不把跨平台总计划伪报为全部完成。

当前协议边缘代码代也已完成故障中压与百万长稳：压测中终止一个 Worker 的 100,000 请求为 0 错误，替补约 1.389 秒 READY；随后 1,000,000 请求为 0 错误、p99 51.702ms、max 518.477ms，四槽分布均衡且没有异常重启。追加 100,000 请求后 Worker RSS 不再增长。Browser 同代验证首页和带 Key 登录页可见、控制台 0 error/warn；正式安全策略已经恢复，生产 9981 未被操作。

两个专用实例在自动验证后均已停止，公开与私有测试端口已释放；生产 9981 未被启动、重载、停止或发布策略。

封禁误伤已作为独立 P0 正确性问题收口：限流与攻击 Ban 语义分离，默认 UA 规则只封禁高置信扫描器，静态资源 fan-out 不再被当成路径扫描。运行中 default 实例已原子发布新策略并恢复，无需重启；源码级验证确认正常自动化请求不再拖累同一出口下的全部页面，同时 sqlmap 等真实扫描仍会触发 403 与共享 Ban。

随后完成第一批 Worker Adapter 去重：三个入口已共享 `worker_runtime_common.php`，HTTP 与 stream TLS 独立实例均通过真实启动、READY、动态首页与热态压测；EventBuffer 只验证公共启动参数加载和语法，继续保持不可启用实验边界。当前代 TLS 1.3 的 100,000 请求为 0 错误、9,170.95 QPS，fresh TLS 20,000 请求为 0 错误、1,707.16 QPS，公开动态首页覆盖全部四 Worker且最大 42.41ms。该进展仍不足以把完整目标标记 complete：Worker 主循环尚未统一，5 轮高噪声 macOS p95 中位为 5.965ms，Linux/Windows 原生平台矩阵仍缺权威证据。

本轮最终把翻译缓存收敛为 Worker 自带内存优先的动态词级模型。模块 CSV 和最终译文分别拥有 Worker L1；L1 失效才访问 Shared Memory，Shared miss 才解析本模块 CSV或按 `md5(word+locale)` 查询一行数据库。无 `source_module` 的 21,055 条历史词仍兼容，但永远不会再作为约 1.67MB 的全 locale 帧发送。无归属词实测首次 14.183ms、同 Worker 二次 0.002ms，常驻表只增加实际访问的 1 项。

同轮修复了实例读取误清理运行 Master 的竞态，并补齐非 Weline vendor 模块名保持。定向 Parser、Status、ServiceOrchestrator 测试分别为 1/1、9/9、93/93；Stop 的既有夹具因自身匿名类签名落后在加载期失败，未修改测试文件也未伪报。最终 16 Worker reload 后动态 READY 为 10.81–21.77ms，首页 2,500 请求 0 错误、7,090.55 QPS，health 100,000 请求 0 错误、12,286.74 QPS，Master/Worker 全程 17/17。h1/h2/h3、TLS resumption、Process FPC、后台 Key 404/200 与 Browser 0 error/warn 再次通过；历史大帧计数未增加。

自动验证实例 9896/9900 已通过统一 stop flow 停止，TCP/UDP listener 与抽查 PID 全部释放；生产 9981 未操作。

最终核心提交为 `8fdcd24ce`，Gitee/GitHub 的 `codex/wls-tls13-extreme-performance` 与 `master` 已全部 fast-forward 到该 SHA；没有 force、reset、分支删除或重复 merge commit。`dev` 上另一智能体的 `9d3f8f276` 保持原样，未覆盖。分项/分仓仍遵循多人脏工作区保护，没有把无法安全扩散的步骤伪报为成功。

本地 `master` 引用也在未检出的前提下纯快进到同一 SHA；主工作区和功能 worktree 均保留，未触碰另一智能体正在修改的文件。

2026-07-14 追加完成公网 TLS 契约与持久会话票据收口。默认协议边缘不再硬编码一套独立 TLS 版本/曲线；Caddyfile 会编译为原生 JSON并使用实例隔离 distributed STEK。真实 OpenSSL 3 验证同一 TLS 1.3 ticket 跨滚动 reload、重复 upstream 激活和协议边缘完整进程重启仍为 `Reused`。变更后 h1/h2/h3、TLS 1.2 fallback、TLS 1.3、FPC、后台 Key 和私有入口安全矩阵全部通过；两组 100,000 请求均 0 错误，其中一组与 rolling reload 重叠。当前 macOS 门禁通过，Linux/Windows 原生 runner 仍未执行，状态继续保持 `macos_protocol_edge_release_gates_passed_cross_platform_pending`，不伪报跨平台全部完成。

同日按用户补充要求把 I18n 的“Worker 自带内存优先”从逻辑语义收紧为真实热路径：常驻 scope/module L1 在 Shared Memory、模块元数据和文件 stat 之前返回。cache epoch 后首次访问仍以文件版本构造跨 Worker 安全的 Shared key，之后全部恢复为本进程数组；动态 exact-word L1 继续只随实际词增长。专用实例 reload、cache epoch、4 Worker 动态渲染、三协议、后台 Key、Browser、静态/架构门禁均通过。第一位 cache rebuild owner 实测 78.63ms，随后全部 Worker 为 8.93–17.32ms；没有把该冷点隐藏成 `<70ms` 全部通过，正式热 first-render 为 5.49ms。

本轮两次提交 `0e103d699` / `0ce4c6e73` 已同时快进 Gitee、GitHub 的 `codex/wls-tls13-extreme-performance` 与 `master`，四个远端引用一致。没有 force、merge commit、reset、文件恢复或分支/worktree 删除；多人共享的 `AGENTS.md/CLAUDE.md` 原样保留为未暂存状态。

自动验收实例 `ai-test-wls-tls-20260714-011246` 已正常停止，公网、私有 Worker 和控制端口均释放，关联进程全部退出；生产 9981 和其他智能体实例没有被 reload/stop/清理。

2026-07-14 又完成 Linux 与最终 macOS 代码代复核。Linux 真实安装 ext-event、探测 SO_REUSEPORT/HTTP3、完成 10 次冷启动、单槽恢复和 1,000,000 + 100,000 请求长稳；百万轮 0 错误、11,237.76 QPS、p95 21.118ms，恢复 READY 856ms。macOS 最终轮 h1/h2/h3、TLS 1.3 ticket 跨 reload 复用和 h2/h3 单连接多路复用均通过，首页 11,093.33 QPS / p95 4.225ms，health 100,000 与 fresh TLS 2,000 请求均 0 错误。

启动链路的边界修复包括：Linux event ini 加载顺序与新进程验证、发行版 Caddy 的真实 HTTP/3 listener probe、协议边缘有界重启与诊断输出、每实例非临时 admin 端口、单调时钟。动态预热尝试数 3→1 的失败实验导致 Linux 冷启动约 11–16s，已经回退并如实保留。`var/` 被明确为节点本地目录，避免不同内核/主机争用同一 shared-service token。

本轮仍不能声明完整跨平台发布：Windows 原生 `auto -> dispatcher`、Direct/independent 拒绝、event DLL ABI 与长稳矩阵，以及 FPM 对照没有当前 runner 的权威证据。功能分支和隔离 worktree继续保留，不删除、恢复或覆盖其他智能体文件。

最终收口门禁全部通过：PHP 语法、8 项 benchmark 定向测试、Semgrep 新增 0 finding、架构检查、框架编译和 12 条运行时策略检查。专用 macOS 实例停止前仍为 Direct 4/4 READY，动态首渲染 9.35–9.50ms；随后通过标准 stop flow 完整释放 9930、28133–28136、38133 和全部关联 PID。另一智能体 9890 实例仍在，未被操作。

核心代码与架构文档提交 `848c2c0f9` 已同步到 Gitee/GitHub 的功能分支和 master，四个远端引用一致且全部是 fast-forward。本地 master 在未被任何 worktree 检出的前提下原子快进；功能分支、隔离 worktree和其他智能体的未暂存文件全部保留。

2026-07-14 补齐了当前代码的 FPM/WLS 同机对照。同一 Host 与默认站点 Cookie 下，首页除请求 ID 外归一为完全相同的字节，静态 SVG 的 SHA-256 也一致；裸后台登录路径两端均 404，合法 Key 路径均 200。FPM c32 五轮 QPS 中位 73.09 / p95 472ms，WLS c32 五轮 QPS 中位 15,784.70 / p95 3ms，全部正式轮均 0 错误、0 非 2xx。Browser 下两端首页的标题、H1、7 个区块、18 个入口链接一致，Console error/warn 为 0。

FPM 对照因此已关闭。跨平台总计划仍不能标记完成：当前没有可用的 Windows 原生 VM、主机或 CI runner，尚无法真实验证 `auto -> dispatcher`、Direct/independent 启动前拒绝、event DLL ABI 匹配、批量启动和长稳。这是唯一剩余的发布证据缺口，没有用静态 Windows 分支检查或 macOS/Linux 数据伪装通过。

同轮 Windows 静态门禁发现停止命令的平台判定是 private，导致定向测试不能覆写平台驱动。修复仅放宽为 protected；GitNexus 评估 LOW，30 项 Windows/Runtime/Socket 定向测试全通过。随后真实启动 Direct 2 Worker 实例，首页 200、裸后台 404，再用实际 `server:stop` 完整释放 Master、Worker、控制端口和自治共享 sidecar。这关闭了一个可静态发现的 Windows 分支缺口，但仍不把 macOS 上的模拟覆写当成 Windows 原生验收。

2026-07-15 又完成 Native TLS profile 与 Windows Dispatcher 启动闭环。Go Edge 的 `performance` 现在固定 `X25519,P-256`，`system` 才采用 Go 默认组；有效 profile 已贯穿 Start、实例记录、Endpoint、Master IPC、Native 配置和 benchmark 元数据。Windows 的真实启动故障定位为 Native Edge 在 bind 前发送的认证 loopback `/_wls/health` 被 Dispatcher 301 到尚未 READY 的公开 HTTPS 端口。Dispatcher 现在仅对 loopback、精确请求行、完整且不超过 8 KiB 的头块、唯一且常量时间匹配实例 token/client-protocol 的内部探针透传，WorkerPolicyKernel 随后再次鉴权；普通明文请求仍保持 HTTPS 重定向和安全规则。

Parallels Windows 11 ARM64 的当前代码单 Worker实例约 3 秒达到 Master + Dispatcher + Native Edge + Worker 全部 READY，h1/h2/h3 各 100/100 返回实际 1.1/2/3；TLS 1.3 / CHACHA20-POLY1305 / X25519 首连与二连为 `resume=false/true`，动态首渲染 65.72ms，首页 Process FPC、后台 Key 404/200 均通过。显式 direct/independent 在创建子进程前拒绝。当前 VM 同时有 5 个非本任务 PHP cron 长期占用约 5/6 CPU；4 Worker 受污染轮的 `batchCreate` 为 809ms，首个 Worker 2.948s READY，但其余进程遭调度饥饿，故不把该轮当作空闲 Windows 的 4/16 Worker或 QPS发布门槛，也未停止、暂停或修改这些外部任务。

macOS 当前代码专用实例 `ai-test-tls13-h3-20260715-033139` 完整停启约 2 秒达到 Direct 4/4 预热 READY，h1/h2/h3、Process FPC、后台 Key 404/200 全部通过。OpenSSL 3 的 TLS 1.3 ticket 在同进程二连及完整 Master + Native Edge 重启后都显示 `Reused`。HTTP/2 health 1,000,000 请求为 0 错误、15,720.26 QPS、p95 13.675ms、p99 18.969ms、max 228.168ms，四 Worker `max/min=1.003`；HTTP/3 health 100,000 请求为 0 错误、12,845.60 QPS。首页 Process FPC 的 HTTP/2 / HTTP/3 各 100,000 请求均 0 错误，分别为 17,199.47 / 10,447.17 QPS。临时 loopback 白名单已经恢复为空，正式策略 digest `f58c7af630ac5ea37560d7b9e5d892ddd26c56ee9b8eb70ec8e0dbf50a6464e1` 已由全部关键进程两阶段 ACK 为 active。

Browser 同代验收只访问专用 `https://127.0.0.1:10977/`：首页标题/H1、文档/API/快速开发/优势/后台入口可见；带 Key 登录页显示完整中文登录表单，两页 Console error/warn 为 0。Browser 对裸 `/admin/login` 的直接导航被客户端本地策略拦截，因此没有伪报页面可见；同代真实 HTTPS 已独立验证裸/带 Key 为 404/200。生产 9981 未操作。

当前候选代码已经取得 macOS、Linux、Windows ARM64 的真实协议和启动证据，但跨平台总计划仍不标记 complete：Windows 空闲环境的 4/16 Worker 冷启动、长稳和完整性能矩阵仍需在不受外部长期任务占满 CPU 的窗口复验。此状态明确区分“实现与核心协议通过”和“全部发布规模门槛完成”。

最终源码门禁为全绿：PHP 语法、Go `gofmt/test/vet/build`、`git diff --check`、Semgrep 168 条规则/11 个目标 0 finding、architecture:check（83 模块/4046 PHP/7173 引用/0 finding）、framework:compile（39 Provider/0 deferred）和 12 条运行时策略检查全部通过。自动验收后已通过统一 stop flow 停止 `ai-test-tls13-h3-20260715-033139`；10977 TCP/UDP、29180–29183、39180 与对应 Master/Edge/Worker PID 均已释放，生产 9981 和其它实例未操作。

同日继续完成一个不触碰请求热路径的 P4 最小批次：三个 Worker 入口对 Framework Runtime bootstrap 和 FPC fast-path 构造改用同一公共 helper；各 Transport Adapter 的日志、SSL trace、异常边界、listener、握手、EventBase、连接表和写缓冲保持原状。专用 10979 实例约 2 秒 4/4 READY，动态首渲染 10.42–10.84ms；实际 h1/h2/h3、TLS 1.3 ticket `New -> Reused`、Process FPC、后台 Key 404/200 均通过。HTTP/3 10,000 请求和 HTTP/2 100,000 请求均 0 错误，分别为 14,557.04 / 14,594.52 QPS。Browser 首页与带 Key 登录页可见且 Console 0 error/warn，静态/架构/策略/Semgrep 门禁全绿，实例和端口已清理。

发布边界保持透明：核心提交 `ac55605ba` 已纯快进到 Gitee/GitHub 的功能分支与 master，本地未检出的 master 也原子快进。分项目标没有安全可更新站点；分仓 32 个映射仓中 6 个 no-change、25 个 dirty、ThemeFancy 远端不可克隆，因此没有创建新 tag、push 或 Packagist 刷新。Windows VM 的 5 个外部 PHP 进程仍持续占用约 480%/600% CPU，空闲机 4/16 Worker 规模门槛继续保留，未终止外部任务或伪造数据。

2026-07-15 又完成一批低风险 P4 去重：后台未登录回跳地址的纯请求规范化由 HTTP/stream-TLS 两份实现收敛为 `worker_runtime_common.php` 的唯一实现，TLS 分支补齐 WLS 原始公开端口语义。入口/公共文件当前为 5,037 / 6,028 / 2,053 / 519 行，四文件净减少 87 行；传输、TLS、HTTP/2/3、FPC 和安全策略路径未改变。

专用 10980 实例约 2 秒达到 5/5 READY，四 Worker 动态首渲染 10.48–10.93ms。H1/H2、后台 Key 404/200、保护页 302、TLS 1.3 fresh-connection session reuse 均通过；HTTP/3 10,000 请求为 13,514.04 QPS / p95 4.057ms，HTTP/2 100,000 请求为 13,888.49 QPS / p95 15.596ms，全部 0 错误且 max 低于 52ms。Browser 首页与中文后台登录表单可见，Console 日志为空；架构、编译、策略、Semgrep 与 diff 门禁全绿。实例、端口和 PID 已全部清理，生产 9981 未操作。

当前状态仍是 `validated_cross_platform_candidate_windows_scale_pending`：实现和 macOS/Linux/Windows ARM 单 Worker/4 Worker协议证据已经存在，但受外部 PHP 长期占满 Windows VM CPU 影响，空闲环境 4/16 Worker 冷启动、完整 QPS 与长稳发布门槛仍不能如实关闭。
