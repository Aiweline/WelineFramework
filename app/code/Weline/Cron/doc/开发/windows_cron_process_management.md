# Windows Cron 进程管理策略

Windows 下 `tasklist /V /FO CSV`、PowerShell CIM、wmic 等进程命令都会触发较重的系统进程枚举。计划任务每分钟由 `WScript.exe` 拉起 `php bin/w cron:task:run` 时，如果对每个 cron 任务都按命令行搜索进程，就会把 WMI Provider Host 间歇性打高。

Cron 调度器应按下面顺序管理任务进程：

1. 创建异步任务后，立即把 `pid` 写入 `weline_cron_task.pid`。
2. 后续调度优先用已保存的 `pid` 调用 `Process::isProcessRunning()` 判断存活。
3. 只有 `pid=0` 且任务处于 `block` / `running` 这类可能存在历史残留进程的状态，或用户显式强制执行时，才允许回退到 `Process::getPidByName()`。
4. `pending` / `success` / `fail` 等正常非运行状态不得按任务名扫描 Windows 进程表。

这样正常调度路径是 O(1) 的 PID 检查，不会随着任务数量增长反复触发全表进程扫描；命令行搜索只保留给异常恢复和人工强制处理。
