# SSE 可恢复后台任务架构

## 目的与边界

SSE 是任务事件的订阅传输，不是任务执行器。浏览器关闭、SSE 断开、短暂网络抖动或 HTTP Worker 重载，均不应让已启动的业务任务停下。

原生 SSE 只提供连接、`retry`、事件 `id` 和 `Last-Event-ID` 重连语义；它不能保存 PHP 调用栈、Fiber、Closure、Generator、资源句柄或数据库连接。本架构因此只保存应用级 JSON 检查点、事件和幂等副作用账本，绝不声称恢复 PHP 运行时快照。

本能力不依赖 `Weline_Queue`，不写 `weline_queue`，也没有连接内执行回退。

## 生命周期

```text
浏览器 runtime_task.start
  -> 持久化 task + page lease
  -> 独立 CLI Runner
  -> checkpoint / event / effect ledger
  -> Watchdog 监督、失联停止或崩溃恢复

服务端 system runtime starter
  -> 持久化 system-owned task（无 page lease）
  -> 独立 CLI Runner
  -> checkpoint / event / effect ledger
  -> Watchdog 监督、崩溃恢复

浏览器 StreamHandle
  -> runtime_task.events ticket
  -> SSE replay (Last-Event-ID)
  -> 实时订阅
```

- `runtime_task.start` 仅允许服务端注册的 `type_code`；浏览器不能提交 PHP 类名、策略或业务幂等键。
- Runner 在独立进程中执行。HTTP 请求、SSE Fiber 和 Queue 都不执行任务业务逻辑。
- Watchdog 监督 Runner 身份、心跳、租约和 fencing generation；Runner 死亡时从最新检查点启动新 generation。
- 每个浏览器标签有独立租约。任一有效租约存在，任务继续；所有租约过期后请求协作停止，宽限期后才终止进程树。
- `StreamHandle.close()` 只退订并停止当前标签续租；只有 `StreamHandle.cancel(reason)` 才显式请求取消。
- `system-owned` 是受限的服务端启动模式，供 Cron 等无人值守维护工作使用：它没有浏览器租约，也不会因不存在客户端而过期；只有受信任的 system principal 可启动，且不会通过浏览器 QueryProvider 暴露。它同样由 Runner/Watchdog 执行和恢复，不是 Queue 回退。

生产默认值：客户端租约 10 分钟，续租间隔 30 秒，终态保留 24 小时，单任务最多 50,000 个事件或 50 MiB 事件负载。Watchdog 会周期性清理已过 `retain_until` 的终态任务及其附属持久化数据。

## 状态与恢复

```text
starting -> running -> completed | failed
                    -> recovering -> running
                    -> cancel_requested -> cancelled | expired
```

`recovery_unsafe` 和 `event_backlog_limit` 是终态。终态绝不可重新变为 `running`。

执行器必须在每一个可重放安全步骤后保存 checkpoint；批处理每项保存一次，最长不得超过 15 秒没有 checkpoint。外部副作用前保存 `before_*`，确认结果后保存 `after_*`。外部系统支持幂等键时使用 `task_id:effect_key`；无法确认的副作用标记 `unknown`，恢复时进入 `recovery_unsafe`，不得盲目重放。

## 事件与 SSE 协议

- 每个持久事件的 `sequence` 严格递增，且 SSE `id:` 等于该 sequence。
- Runtime task 的 ID 必须是持久整数 sequence；观察型订阅可使用最多 128 个可打印 ASCII 字符的稳定不透明 cursor（例如 `file-identity:byte-offset`），不得含空白或控制字符。
- `Last-Event-ID` 只跟随持久业务事件；心跳是注释帧，不推进游标。
- 重连必须重新申请一次性 stream ticket，并提交最后连续处理的 sequence。
- 游标落后于压缩边界时，服务器先发送无 ID 的 `runtime_reset`，随后发送带真实 sequence 的 `runtime_snapshot`，最后发送增量事件。
- `completed`、`failed`、`cancelled`、`expired`、结果、错误和不可重建业务事件不得压缩；终态事件只持久化一次。
- progress、log、token、chunk 事件可在 80% 配额后以 checkpoint 快照为锚点压缩。若不可压缩事件触及硬上限，任务进入 `event_backlog_limit`，不静默丢失数据。

## 保留与定期清理

- 活动任务不会按时间删除；其事件负载受单任务 50,000 条 / 50 MiB 硬上限和压缩规则保护。
- 每个终态任务都持久化 `retain_until`（默认终态后 24 小时）。到期后删除任务主记录以及 checkpoints、events、leases 和 effect ledger，重连窗口在保留期结束后自然失效。
- WLS 的单实例 Runtime Watchdog 与非 WLS 的 `runtime:task:watch --daemon` 使用相同维护路径：正常每 60 秒扫描一次，每次至多删除 500 个终态任务。若本批刚好满额，视为可能仍有积压，5 秒后继续下一批；不会无限循环或阻塞 Runner 监督。
- 清理错误只记录聚合失败计数并在 5 秒后重试，不能使 Watchdog 停止监督活跃 Runner。`runtime:task:watch --once` 的 JSON 报告包含 `terminalTasksPurged`、`terminalTaskCleanupFailures` 和 `terminalTaskCleanupBacklog`。
- Installer 是无主数据库的 bootstrap 例外：其 `var/runtime/install-sse` 文件 journal 终态保留 24 小时，并在每次创建新的安装任务时清理已过期的终态目录；它不接入主 Runtime 或 Queue。

## 持久化时钟约定

Runtime 表由网站请求 Worker、独立 CLI Runner 和 Watchdog 共同读写，三者不能依赖 PHP 进程的默认时区。`weline_runtime_task`、lease、checkpoint、event 与 effect ledger 的 datetime 一律以 UTC `Y-m-d H:i:s` 持久化，并以 UTC 解析；租约比较、Watchdog 心跳、`retain_until` 清理和 `TaskSnapshot` 都使用同一时钟约定。

- 禁止在 Runtime 持久化链使用未固定时区的 `date()` 或 `strtotime()`。
- 旧版本中若一个请求 Worker 用本地时区创建任务、CLI Runner 用 UTC 更新任务，会出现 `updated_at < created_at`。读取旧记录时只归一化快照元数据的时间顺序，以保证 replay/status 可用；不改变任务状态、事件序号、owner 或副作用账本。
- 新写入始终为 UTC，因此旧记录被下一次正常生命周期写入后自然收敛，无需批量改写活动任务。终态清理继续只以 canonical UTC `retain_until` 为准，避免保留数据因时区偏移无限增长或被过早删除。

## 前端调用

生产页面使用 `Weline.Api` 模块，不使用 `fetch`、XHR、axios 或原生 `EventSource`。先加载完整模块，避免在 Theme 的延迟加载代理上把同步的 `resource()`/`createStream()` 误当成 Promise：

```javascript
const api = await Weline.load('api');
const task = await api.resource('runtime_task').start({
    type_code: 'module.operation',
    input: { /* business parameters only */ }
});

const stream = api.createStream(task.stream_channel, {
    task_id: task.task_id,
    lease_id: task.lease_id
});
stream.addEventListener('progress', renderProgress);
stream.addEventListener('completed', renderDone);
await stream.start();

// detach only
stream.close();

// explicit, idempotent task cancellation
await stream.cancel('user_requested');
```

`createStream()` lets the page register listeners before the first durable replay. The handle persists its task/lease/cursor state in `sessionStorage`, renews only its own lease, reconnects with a fresh ticket and exponential backoff, and never turns browser `offline` or a transport error into cancellation.

The runtime SSE controller is registered from `Weline_Api/Api/Framework/Stream.php`, not `Controller/`. This is required so route generation places `/api/framework/stream` and its prefix-stripped `framework/stream` lookup alias in the frontend REST router; run `php bin/w setup:upgrade --route` after changing that registration. The two exact paths are browser-transport exceptions to API-token/ACL preflight only: the controller still requires same-origin plus a single-use, worker-session-bound ticket and, for runtime tasks, an owner/area/lease/cursor binding.

## Migration rule

There is no old SSE execution compatibility route. When migrating a production execution endpoint, remove its connection-bound execution path and replace every production caller in the same change with `runtime_task.start` plus `Weline.Api.createStream()`. Observation-only channels are allowed to remain stream-only, but must use durable source IDs/cursors and may not start business work.

## Installer bootstrap exception

Before installation completes, the main database, `Weline.Api`, and Runtime Watchdog do not exist. `Weline_Installer` therefore uses a deliberately isolated controlled child process plus `var/runtime/install-sse/{task_id}/state.json` and `events.jsonl`. Its SSE endpoint only replays the file journal with sequence IDs; it never executes environment repair in the request. It has an explicit random control-token cancellation route and 24-hour terminal retention, but it is not a Fiber/call-stack snapshot and does not use Queue or the normal task runtime. See `Weline_Installer/doc/环境修复后台任务.md`.

## Operational checks

- Run `php bin/w runtime:task:inspect --task-id=<task-id>` to inspect persistent state.
- Run `php bin/w runtime:task:watch --daemon` outside WLS when no WLS service provider is active.
- Run `php bin/w runtime:task:watch --once` for a bounded liveness and retention-cleanup sweep; inspect the JSON report instead of deleting Runtime tables manually.
- Runner and Watchdog processes must be dedicated to runtime supervision; do not tie them to an SSE request or queue consumer.
- 仅由 Cron/服务端代码启动 `system-owned` 任务；绝不能为其开放浏览器 start、伪造页面租约或借由 SSE 请求执行任务。
- On a WLS reload, Runner processes keep working. On full WLS shutdown, Watchdog requests checkpoint/drain and the next Watchdog start resumes eligible tasks.
