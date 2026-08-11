# Setup / Cron 数据库访问协调

## 适用范围

本协议协调同一应用实例的 `setup:upgrade` 与 `cron:task:run`，避免升级 DDL、`upgrade_after` 收集器、Cron 调度父进程和任务子进程同时访问同一数据库。协调文件是稳定 inode `var/process/setup_database_access.lock`；文件存在不表示锁忙，不得删除“旧锁文件”，只能根据实际非阻塞 `flock` 结果判断活动租约。

## 两级租约所有权

- 顶层 `bin/w` / `bin/m` 在应用 bootstrap 前取得门禁：非 hot `setup:upgrade` 使用 EX；Cron 以及 `setup:upgrade --hot/--help` 使用 SH。
- 顶层入口租约贯穿 bootstrap、命令主体、`command_executed`、footer、可选 follow-up、异常渲染和全部 shutdown 回调；直到 PHP 进程退出后才由运行时关闭描述符。
- 绕过顶层入口直接执行命令时，命令本地取得租约，并通过 `CommandResult` finalizer 在 Cli post/footer 完成后释放；直接调用者必须在自己的 `finally` 中调用 `finalize()`。
- 打开锁文件或获取锁出现真实错误时必须 fail-closed，不得伪装成普通 contention。fork child 只关闭继承副本，不得解锁或延长父进程租约。

## 锁忙路径

- 门禁必须在任何 Model、Cache、Event、Phrase Parser、PID 探测或派生进程之前判定。
- 提示只通过 `DatabaseFreeTranslator` 读取 `env.php` 文本和模块 i18n CSV；未持锁分支禁止调用 `__()` 或任何可能触发数据库的服务。
- Setup EX 忙时，Cron 自动调度返回 0 表示本轮安全跳过；显式 `-process` / `-force` / 后台手动运行返回临时失败码 75。
- Cron SH 忙时，Setup 返回 75。顶层早期返回不启动应用、Cli 后处理、PHP_CS 或任务子进程。
- `CronTask` 不得由 `Run` 构造器注入，只能在持有 SH 且受管子进程获得 GO 之后延迟解析。

## 调度父子协议

0. 系统 crontab 生成脚本必须先取得当前项目 `var/cron-main.lock` 的非阻塞 advisory lock；macOS 使用 `lockf -k -t 0` 持锁到调度父进程退出，Linux 使用 `flock -n`。随后它还必须在 PHP bootstrap 前取得 `var/process/setup_upgrade.lock`：Linux 使用非阻塞共享 `flock`，macOS 使用非阻塞 `lockf`。这层兼容围栏保证尚未接入顶层数据库访问租约的旧 `setup:upgrade` 实现持有排他锁时，系统 Cron 也不会进入数据库。上一分钟调度父进程仍在运行或 Setup 正在升级时，本分钟安全跳过；两种工具均不可用时 fail-closed，禁止无锁扫描。锁文件存在本身不表示锁忙，只能根据实际获取结果判断。`cron-main.lock` 只串行化调度父进程，升级兼容围栏也不替代每个任务独立的数据库共享锁与 handoff token。
1. 父调度器以 `id + 原 status + 原 run_time + 原 launch_id + 原 pid` 围栏认领任务，原子写入本轮唯一的 `BLOCK + pid=0 + run_start + launch_id`；认领失败不得启动子进程。
2. 子进程必须同时携带稳定 `--name`、本轮 `--launch-id` 和 `--cron-run-start`。POSIX 派生器在 fork 后先让 child 等待本地 exec gate；父进程提交该 PID 的 exact managed lease 后才发 GO，提交或 GO 失败时 child 在 exec 前自行退出，父进程禁止向未登记 raw PID 发信号。它取得 SH 后发布 `READY(pid)`，但在收到父进程决定前不得解析业务 Model 或进入业务代码。本机冷启动 READY 预算为 30 秒。
3. 父进程确认 READY PID 与启动器返回的真实 PHP PID 一致，再以 `id + run_start + launch_id + BLOCK + pid=0` 做 CAS，写为 `RUNNING + pid`。CAS 成功后才发布 GO。
4. READY 超时、PID 不一致、CAS 丢失或 GO 发布失败时，必须先发布 ABORT。POSIX 即使从 kernel argv 唯一匹配 `name + launch-id + cron-run-start`，在没有 pidfd/稳定进程句柄时也禁止 probe 后按裸 PID 发信号，必须返回 `termination_unavailable_without_stable_handle` 并保留 lease/PID，依赖 child gate 或自然退出；只有具备稳定句柄的平台才可发送一次终止信号并用本轮状态/PID分型围栏写 FAIL。失读、缺失、重复或终止结果无法确认时同样必须保留 PID。
5. 子进程执行前再校验 `id + run_start + launch_id + RUNNING + pid`；成功和异常结果都使用同一代围栏写入。异常先保留当前 PID，只有身份探测确认 exited/mismatch 后才清零，迟到子进程不得覆盖父进程已保留的 FAIL/PID。

`BLOCK + pid=0 + 合法 launch_id` 是父调度器拥有的 READY 启动窗口；包括 `-force` 在内的其他调度器都不得扫描、收养或盗取。人工锁定使用空 `launch_id`，仍允许显式 force 解锁。已保存 PID 必须使用 running / exited / unknown 三态探测并校验本代三个实时参数；unknown 时保留 PID 并跳过，身份不匹配时不得发送信号。

SQLite 连接不允许父调度器在上一代受管子任务仍运行时继续认领或启动下一项；父进程必须在发布 `GO` 后按精确代次等待 `SUCCESS/FAIL + pid=0 + launch_id=''`，再处理下一任务。父进程还必须用 `waitpid(WNOHANG)` 回收自己已退出的 POSIX child；若数据库仍是本代 `RUNNING + PID`，按精确代次 CAS 为 FAIL 并释放本代 lease，避免 zombie 在 `kill(pid, 0)` 下持续表现为存活而卡死整轮调度。该单飞约束只适用于 SQLite；MySQL、MariaDB 和 PostgreSQL 保持独立任务并发。普通调度不转发子任务日志，后台手动 SSE 仍按原协议转发。

## 日志和清理

- 当前实时日志由 `Process::getManagedLogProcessFilePath()` 解析，物理位于 `var/process/{stable-managed-name}.log`。
- 下一轮启动前，上一轮托管日志归档到 `var/log/cron/history/{execute-name-sha256前24位}/`，文件名严格为 `{Ymd-His}-{6位微秒}-{managed|legacy}.log`；每个任务独立保留最近 20 个文件。
- Windows 的独立 stderr 使用 64 KiB 分块合并进当前托管日志，完整成功后才删除源文件。启动日志必须隐去 handoff token 和 launch-id。
- 子进程退出和父进程取消路径都必须清理精确托管 lease/index、READY/GO/ABORT 文件和 Windows stderr 临时文件，不得随调度轮次增长。
- 后台手动 SSE 通过每个子进程独立环境传递 `WELINE_CRON_MANUAL_*`，必须剥离大小写不敏感的 `WLS_*`。调度父进程在 GO 后按同一 `task_id + run_time + launch_id` 尾随 managed log，直到真实业务 SUCCESS/FAIL；不得在仅完成派生时报告“执行完成”。

## 验证要求

- 锁忙早退必须证明数据库文件/连接未创建、无 footer、无 PHP_CS、无子进程。
- 并发父调度器必须证明同一任务只增加一次 `run_times`。
- SQLite 必须证明同一父调度轮次内受管子任务没有执行重叠；MySQL/PostgreSQL 必须证明未被意外串行化。
- 子进程完成后必须证明 PID 为 0、托管索引为空、交接文件为空、当前/历史日志不包含明文 32 位 token。
- 最终验收使用同一份冻结源码覆盖 SQLite、MySQL 和 PostgreSQL；Windows 还需真实运行矩阵，不得用静态 PowerShell 脚本检查代替。
