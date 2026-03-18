# 计划任务后台手动运行（SSE）

## 路由

- 控制器：`Weline\Cron\Controller\Backend\Cron`（`system/backend/cron/...`）。
- 列表：`system/backend/cron/listing`；帮助：`GET …/run-help`；手动执行 SSE：`POST …/post-run-stream`。
- 调度日志（系统 cron 跑的输出）：`GET …/run-log-list?execute_name=`、`GET …/run-log-content?execute_name=&file=`、`POST …/post-run-log-stream`（实时尾随当前 `var/cron/{execute_name}.log`）。历史在 `var/cron/history/{execute_name}/`（每任务最多保留约 200 个文件）。

## 行为

- 真实执行 `php bin/w cron:task:run <execute_name> -f`；可选后缀 → `WELINE_CRON_MANUAL_ARGS`。
- 子进程会设置 `WELINE_CRON_MANUAL_SSE=1`：`w_log` 的 notice/info/warning/error 等会同步写到 **stderr**，SSE 终端可见；任务 `execute()` 若返回空仍会打印一行摘要提示。

## ACL 链路（command:upgrade 收集）

- 菜单：`Weline_Cron::system_cron`（menu.xml）
- 类级：`Weline_Cron::cron_pc_root`（父：system_cron）
- 子级示例：`cron_listing`、`cron_lock`、`cron_unlock`、`cron_run_help`、`cron_run_stream`

角色需勾选 **计划任务** 菜单；若需细粒度，再勾对应子权限。

## 安全

- POST SSE 需 CSRF；执行名白名单。
