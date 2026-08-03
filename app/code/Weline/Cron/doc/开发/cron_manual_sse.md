# 计划任务后台手动运行与日志读取

## 路由

- 控制器：`Weline\Cron\Controller\Backend\Cron`（`system/backend/cron/...`）。
- 列表：`system/backend/cron/listing`。
- 页面只读请求：`Weline.Api.resource('cron').getRunHelp()`、`runLogList()`、`runLogContent()`；Controller 的 `GET …/run-help`、`…/run-log-list`、`…/run-log-content` 只保留兼容。
- 手动执行 SSE：`POST …/run-stream`；GET 变体仅为兼容入口。
- 调度日志实时尾随：`POST …/run-log-stream`；GET 变体仅为兼容入口。
- 当前实时日志由 `Process::getManagedLogProcessFilePath()` 解析，物理位于 `var/process/{stable-managed-name}.log`。历史日志位于 `var/log/cron/history/{execute-name-sha256前24位}/`，文件名严格为 `{Ymd-His}-{6位微秒}-{managed|legacy}.log`，每个任务独立保留最近 20 个文件。

## 行为

- 真实执行 `php bin/w cron:task:run <execute_name> -f`；可选后缀 → `WELINE_CRON_MANUAL_ARGS`。
- Controller 不修改长驻 WLS 的进程级环境；它为调度 CLI 构造独立环境，剥离全部大小写不敏感的 `WLS_*`，再设置 `WELINE_CRON_MANUAL_SSE=1` 和可选 `WELINE_CRON_MANUAL_ARGS`。
- 调度父进程在 READY/PID-CAS/GO 后按本轮 `task_id + run_time + launch_id` 等待 detached 业务子进程，持续转发 managed log。只有真实 SUCCESS 才返回 0；FAIL、代次丢失或未取得新代次均返回非零。任务 `execute()` 返回空时仍输出一行摘要。
- 手动 `-force` 与 SSE 不绕过 Setup/Cron 协调锁。系统升级持有 EX 时，顶层入口会在应用 bootstrap 前输出“本次计划任务已跳过且未访问数据库”并以临时失败码 75 返回，因此不会启动应用、Cli 后处理、PHP_CS 或任务子进程；升级完成后由用户重新触发。

## ACL 链路（command:upgrade 收集）

- 菜单：`Weline_Cron::system_cron`（menu.xml）
- 类级：`Weline_Cron::cron_pc_root`（父：system_cron）
- 页面/普通动作：`cron_listing`、`cron_lock`、`cron_unlock`。
- 帮助读取：`cron_run_help`；手动执行 POST：`cron_run_stream`；手动执行 GET 兼容入口：`cron_run_stream_get`。
- 日志 bin-query 与实时 POST：`cron_run_log`；旧 HTTP 列表/正文兼容路由：`cron_run_log_list`、`cron_run_log_content`；实时 GET 兼容入口：`cron_run_log_stream_get`。

角色需勾选 **计划任务** 菜单及实际使用的精确子权限。`source_id` 不得跨不同方法复用，否则路由收集去重会使其中一部分入口变为未保护路由。

## 安全

- SSE POST/GET 入口均先经过后台 ACL，再校验 CSRF，最后才解析任务或触碰日志文件。
- 帮助和日志 QueryProvider 必须同时声明 `auth=backend` 与精确 `backend_acl`，不能依赖 same-origin 代替权限。
- 历史日志读取只接受当前任务哈希目录中的严格 basename grammar，拒绝路径分隔符、`..`、NUL/控制字符和越界 symlink；正文最多 2 MiB。
- 实时 tail 使用固定 96 KiB 读取块，以 inode/ctime 和末尾 64 字节连续性同时识别 rename、替换及截短；进程身份 unknown 按“可能仍运行”处理，不得提前关闭 SSE。
