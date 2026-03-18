# 计划任务后台手动运行（SSE）

## 行为

- 列表页 **运行** 打开弹层：真实执行 `php bin/w cron:task:run <execute_name> -f`，与定时调度同一入口。
- **后缀**（可选）：非空时，子进程启动前会 `putenv('WELINE_CRON_MANUAL_ARGS=' . 后缀)`。任务若需在手动运行时读参，在 `execute()` 内使用 `getenv('WELINE_CRON_MANUAL_ARGS')` 自行解析；留空则不设置该变量，与 crontab 行为一致。
- 输出经 SSE 推送到终端组件（stdout/stderr）。

## 权限

- 路由挂载 ACL：`Weline_Cron::cron_manual_run`（父级 `Weline_Cron::system_cron`）。部署后执行 `command:upgrade`（或等价 ACL 同步），为需使用该能力的后台角色勾选 **计划任务手动运行**。

## 安全

- POST 流需 CSRF（`csrf` 字段）。
- 执行名仅允许 `[a-zA-Z0-9_-]+`，白名单来自 `cron_task` 表。
