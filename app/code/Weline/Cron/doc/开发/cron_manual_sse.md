# 计划任务后台手动运行（SSE）

## 路由

- 控制器：`Weline\Cron\Controller\Backend\Cron`（`system/backend/cron/...`）。
- 列表：`system/backend/cron/listing`；帮助：`GET …/run-help`；SSE：`POST …/post-run-stream`。

## 行为

- 真实执行 `php bin/w cron:task:run <execute_name> -f`；可选后缀 → 环境变量 `WELINE_CRON_MANUAL_ARGS`。

## ACL 链路（command:upgrade 收集）

- 菜单：`Weline_Cron::system_cron`（menu.xml）
- 类级：`Weline_Cron::cron_pc_root`（父：system_cron）
- 子级示例：`cron_listing`、`cron_lock`、`cron_unlock`、`cron_run_help`、`cron_run_stream`

角色需勾选 **计划任务** 菜单；若需细粒度，再勾对应子权限。

## 安全

- POST SSE 需 CSRF；执行名白名单。
