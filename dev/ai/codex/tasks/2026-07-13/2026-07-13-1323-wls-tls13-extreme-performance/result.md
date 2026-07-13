# 结果

状态：`macos_linux_release_gates_passed_windows_fpm_pending`

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
