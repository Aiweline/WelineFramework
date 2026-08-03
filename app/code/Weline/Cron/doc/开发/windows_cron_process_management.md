# Windows Cron 进程管理策略

Windows 下 `tasklist /V /FO CSV`、PowerShell CIM、wmic 等进程命令都会触发较重的系统进程枚举。计划任务每分钟由 `WScript.exe` 拉起 `php bin/w cron:task:run` 时，如果对每个 cron 任务都按命令行搜索进程，就会把 WMI Provider Host 间歇性打高。

Cron 调度器应按下面顺序管理任务进程：

0. 顶层 CLI 在应用 bootstrap 前取得 Setup/Cron 共享门禁；直接调用命令时也必须在任何 Model、PID/WMI 探测或派生进程前取得 SH。Setup EX 忙时不得访问数据库或启动子进程。
1. 父调度器先以 `id + 原 status + 原 run_time + 原 launch_id + 原 pid` 围栏认领，把任务写为本轮唯一的 `BLOCK + pid=0 + run_start + launch_id`；认领失败不得启动。
2. 子进程同时携带稳定 `--name`、本轮 `--launch-id` 和 `--cron-run-start`；取得 SH 后发布 `READY(pid)`，但在收到父进程决定前不得进入业务代码。
3. 父进程必须确认 READY PID 与启动器返回的真实 PHP PID 一致，再以 `id + run_start + launch_id + BLOCK + pid=0` 做 CAS，写成 `RUNNING + pid`；CAS 成功后才发布 GO。
4. READY 超时、PID 不一致、CAS 丢失或 GO 发布失败时，必须先发布 ABORT；只有 CIM 实时命令行唯一匹配本代三个参数时才允许一次终止动作。缺失、重复、失读或终止结果不明均保留 PID。
5. 已保存 PID 使用 running / exited / unknown 三态探测并同时校验 DB 代次、managed lease 与实时参数。unknown 时保留 PID并跳过；身份不匹配时不得发信号。
6. `BLOCK + pid=0 + 合法 launch_id` 是父调度器持有的 READY 窗口，`-force` 也不得接管；人工锁定的空 launch_id 仍可显式 force。后台 SSE 子环境必须剥离继承的 `WLS_*`。

协调锁、交接文件、PID 围栏和日志清理的完整正文见 `setup_cron_database_access_coordination.md`。调度路径优先使用 O(1) 锁检查和已保存 PID；命令行全表扫描只保留给明确的异常恢复和人工强制处理。
