# spec：auto 直连失败时回退 Dispatcher，且 Dispatcher 不得为默认

- 模块：`Weline_Server`
- 状态：`ready-for-plan`（用户已确认；MCP 未挂载，按 `AI硬规则索引.md` + 分册执行）
- 关联：`doc/需求.md`（REQ-SERVER-0020）、`doc/Dispatcher分流架构设计.md`、`doc/WLS模式部署指南.md`

## 1. 澄清记录

| 问题 | 用户裁定 |
|------|----------|
| 「派遣器是回退的，不能默认就用派遣器」指什么 | **两者都做**：既清除一切「默认带 `--dispatcher`」的残留，又建立真正的自动回退——`auto` 首选 Direct，Direct 能力确实不可用时自动降级 Dispatcher，不再让操作者手动补 `--dispatcher` |
| 边缘/网关默认是否调整 | **保持 `auto`（适配器 Nginx）不动**；`auto` 出口顺序仍为宿主 Gateway → 项目托管 Nginx → 纯 WLS 高端口 |
| 本机 `default` 实例（当前 `explicit_dispatcher`） | 改，重启为 Direct（macOS `shared_fd`） |

## 2. 用户故事

- 作为本机开发者，我在 macOS/Linux 上执行 `php bin/w server:start`（不带任何拓扑参数）时，系统应当直接以 Direct 起 Worker，只有在我的 PHP 缺少 Direct 必需能力（`ext-event`、Master 共享监听 FD、`SO_REUSEPORT`）时才自动降级为 Dispatcher，并在启动输出与状态里明确告知降级原因。
- 作为运维人员，我显式写了 `--direct` 或 `wls.runtime.topology=direct` 时，系统**不得**静默降级为 Dispatcher，必须 fail-closed 并给出修复指引。

## 3. EARS

1. **WHEN** 操作者未显式指定拓扑（无 `--direct`、无 `--dispatcher`、`wls.runtime.topology` 非 `direct`）且 `wls.runtime.listener_mode` 为 `auto`，**且** POSIX 平台 Direct 能力探测失败（shared listener / reuseport 均不可用）**THEN** 系统 SHALL 把生效拓扑解析为 Dispatcher，保留 `requested_topology=auto`，`listener_mode=single`，并令 `reason_codes` 含 `auto_direct_unavailable_dispatcher_fallback`。
2. **WHEN** 操作者未显式指定拓扑，且共享监听 FD 可用但缺少 `ext-event`/事件循环（`event_loop` 解析结果不是 `event`）**THEN** 系统 SHALL 同样回退 Dispatcher，并给出含真实原因与「可安装 ext-event 后恢复 Direct」的警告。
3. **IF** 操作者显式要求 Direct（`--direct` 或 `wls.runtime.topology=direct`）或显式指定 `wls.runtime.listener_mode=reuseport|shared_fd`，**THEN** 系统 SHALL 继续 fail-closed（抛错并以非零码退出），禁止任何静默回退，错误信息须同时给出 `--dispatcher` 作为显式替代。
4. **WHEN** Dispatcher 回退已生效，`server:start` 的依赖预检拓扑与最终 `RuntimeSelection` 出现「auto→Direct 预检 / auto→Dispatcher 生效」这一唯一允许的差异 **THEN** 系统 SHALL 接受该差异、打印降级警告并继续启动，其余任何不一致仍 SHALL 拒绝启动。
5. **WHEN** 启动输出、面板提示或文档描述默认拓扑 **THEN** 它们 SHALL 表述为「默认 Direct，Dispatcher 为显式兼容或能力不足时的回退」，不得把 Dispatcher 描述为默认或推荐发布模式。

## 4. 用例（UC-1：本机 auto 启动）

- 前置：macOS/Linux，`wls.edge.mode=auto`（默认），无拓扑 CLI 参数；构造 Direct 能力缺失（如未加载 `ext-event`）。
- 主成功路径：`php bin/w server:start` → 输出「auto 直连能力不足，已回退 Dispatcher（原因）」→ Master + Dispatcher + Workers 起来 → `var/server/instances/{instance}.json` 的 `runtime_selection` 为 `requested_topology=auto / effective_topology=dispatcher / listener_mode=single / reason_codes=[auto_direct_unavailable_dispatcher_fallback]` → 真实 HTTP 请求经边缘 200。
- 分支 A：补上 `ext-event` 后再次 `server:start -r` → `effective_topology=direct / listener_mode=shared_fd`（或 Linux `reuseport`），无降级警告。
- 分支 B：`server:start --direct` 且能力缺失 → 非零退出 + fail-closed 文案，实例文件不被改写。

## 5. 非目标

- 不改 `wls.edge.mode=auto` 的出口顺序与 Nginx/Gateway 选择。
- 不改 Windows 既有规则（`auto` → `worker_ports`；纯 WLS 与 schema-6 监听权 → Dispatcher）。
- 不引入 `--no-dispatcher`/`--topology`/`--force-dispatcher` 等已移除参数。
