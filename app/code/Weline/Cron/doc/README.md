# Weline_Cron 模块文档

> 本 README 是 Weline_Cron 的长期模块契约；结构清单可由生成器辅助核对，专项行为以本模块 `doc/开发/` 文档为准。

## 当前入口

开发前先调用项目 MCP `prepare_project`；返回 `ready` 后，使用 `resolve_task_context` 按任务从本 README、`需求.md`、`开发日志.md` 和专题文档取得必要上下文。

## 模块定位

- 模块代码：`Weline_Cron`
- 目录：`app/code/Weline/Cron`
- 当前状态：结构化模块概览已补齐；稳定业务规则仍应继续沉淀到本模块 `doc/`。

## 公共 PHP 契约

跨模块定时任务只读展示使用 `Weline\Cron\Api\Task\CronTaskCatalogInterface`。实现返回不可变 `CronTaskRecord`，只暴露任务代码、名称、表达式、说明与上次运行时间，不暴露 `CronTask` ORM Model、字段常量或查询对象。调用模块应在 `etc/module.php.optional/requires` 与 Composer `suggest/require` 中声明 Cron，并通过编译 Provider 解析接口。

跨模块进程控制使用 `Weline\Cron\Api\Process\ProcessControlInterface`。它只发布任务名规范化、PID 存活检查、指定 PID 终止和 Cron 日志清理；平台差异和 `Helper\Process` 细节由 Cron 内部 Provider 封装。

## 代码面概览

入口文件：
- `app/code/Weline/Cron/composer.json`
- `app/code/Weline/Cron/etc/backend/menu.xml`

- `Console`：php bin/w 命令入口。 文件数：11
- `Controller`：前后台 HTTP 控制器与路由入口。 文件数：1
- `Controller/Backend`：后台控制器入口；变更前同步检查 ACL、菜单和返回路径。 文件数：1
- `Helper`：模块内辅助能力。 文件数：2
- `Model`：ORM 模型与字段 schema。 文件数：1
- `Observer`：事件观察者与订阅逻辑。 文件数：1
- `Service`：业务编排与模块服务层。 文件数：4
- `etc`：模块配置。 文件数：3
- `i18n`：国际化资源。 文件数：2
- `view/templates`：模块模板源文件。 文件数：1
- `view/tpl`：模板编译/生成产物。 文件数：0

## 开发关注点

- 存在 `Controller/`，说明模块有 HTTP 入口；控制器变更后记得同步路由升级和最接近的真实入口验证。
- 存在 `Controller/Backend`，后台页面/行为变更时应同时检查菜单、ACL、返回地址和用户提示。
- 存在 `Model/`，字段或索引变更需走模型 attribute + `setup:upgrade`，不要手改生成物。
- 存在 `Service/`，这里通常是模块业务编排层；跨模块协作优先通过已发布契约和 `w_query`。
- 存在 `Observer/`，改事件数据前应同步检查触发点和消费点。
- 存在模板源文件；出现页面问题时先追源码，不要直接改 `view/tpl`。
- 存在 `i18n`，用户可见文案改动要同步 `zh_Hans_CN.csv` 与 `en_US.csv`。
- 存在测试目录，但默认不要新增测试产物；只有用户明确要求时才进入测试修改。

## 后台读取与日志契约

- Cron 列表页的运行帮助、历史日志列表、历史日志内容统一走 `Weline.Api.resource('cron')`，operation 分别为 `getRunHelp`、`runLogList`、`runLogContent`；禁止退回原生 `fetch/ajax`。
- 三个 operation 都是 `frontend=true`、`auth=backend`、`mode=read`、`graph=false`。帮助使用精确资源 `Weline_Cron::cron_run_help`；日志读取使用既有精确资源 `Weline_Cron::cron_run_log`。
- `CronAdminReadService` 是 Controller 兼容路由与 QueryProvider 的共享业务边界；旧 `run-help`、`run-log-list`、`run-log-content` 路由只保留兼容，不得复制业务判断。
- 当前实时日志由 `Process::getManagedLogProcessFilePath()` 解析，物理位于 `var/process/{stable-managed-name}.log`。下一轮启动前，上一轮日志归档到 `var/log/cron/history/{execute-name-sha256前24位}/`，文件名严格为 `{Ymd-His}-{6位微秒}-{managed|legacy}.log`；每个任务独立保留最近 20 个文件。
- 历史读取必须先解析真实 Cron 任务，再进入该任务哈希目录并校验完整文件名 grammar；拒绝 `/`、`\\`、`..`、控制字符和越界 symlink。正文最多返回 2 MiB，结果保持合法 UTF-8并通过 `truncated` 标记截断。
- 实时日志仍走 SSE，单次文件读取最多 96 KiB；普通只读 operation 不得包装成 SSE，SSE 也不得伪装成普通 bin-query。
- 一个 ACL `source_id` 只能落一条路由记录。GET/POST 或不同 Controller 方法不得复用同一 source；历史兼容的 POST 权限 `cron_run_stream`、`cron_run_log` 保留，GET 兼容入口和日志 HTTP 读取各使用唯一 source。

## Setup 并发隔离契约

- 完整锁生命周期、锁忙早退、`task_id + run_time + launch_id + status + PID` 围栏、READY → fenced PID CAS → GO/ABORT、PID 三态探测、身份安全终止与日志清理契约见 `app/code/Weline/Cron/doc/开发/setup_cron_database_access_coordination.md`。
- Linux/macOS 生成的系统 Cron wrapper 使用当前项目 `var/cron-main.lock` 单航班运行；macOS 使用 `lockf -k -t 0` 持锁到命令退出，Linux 使用 `flock -n`，两者均不可用时 fail-closed，不允许每分钟父调度器重叠。
- 顶层 `bin/w` / `bin/m` 在 bootstrap 前取得门禁，入口租约保持到 PHP 进程退出；直接命令调用才通过 `CommandResult` finalizer 在 Cli post/footer 后释放。
- `CronTask` 只能在 SH 内延迟解析；POSIX/Windows 派生路径都必须在返回 PID 前提交 exact managed lease，受管子进程还必须在 READY 后等待父进程完成 PID 围栏并发布 GO，之后才能进入业务代码。
- SQLite 串行父调度器必须用 `waitpid(WNOHANG)` 回收自己已经退出的 POSIX child；若 child 未提交业务终态，则按 `task_id + run_time + launch_id + RUNNING + PID` 精确 CAS 为 FAIL，禁止僵尸进程让整轮调度无限等待。
- POSIX 对携带 required argv 围栏的 live child 不允许在 probe 后按裸 PID 发信号；没有 pidfd/稳定句柄时返回 `termination_unavailable_without_stable_handle`、保留 lease/PID，并依赖 ABORT handoff 或 child 自然退出。
- `upgrade_after` 仍在 Setup EX 和维护模式内执行；此时触发的 Cron 会在 bootstrap 前跳过，不访问数据库。
- `cron:remove` 必须读取并保留全部用户 crontab 条目，只移除同时匹配当前项目标识与 `cron_flag` 的条目；提交失败或回读仍存在时返回非零，成功后才删除生成脚本。

## 本模块文档资产

- `app/code/Weline/Cron/doc/开发/cron_manual_sse.md`
- `app/code/Weline/Cron/doc/开发/setup_cron_database_access_coordination.md`
- `app/code/Weline/Cron/doc/开发/windows_cron_process_management.md`

## 维护规则

- 不直接修改 `generated/`、`view/tpl/`、`routes.xml`。
- 涉及浏览器业务请求时，只使用 `Weline.Api.*` / QueryProvider 链路。
- 涉及字段结构时，用 `#[Col]` / `#[Index]` 和 `php bin/w setup:upgrade`。
- 涉及控制器路由时，用 `php bin/w setup:upgrade --route`。
- 结构清单变化时核对本 README；长期安全契约优先更新对应的 `doc/开发/` 专项文档。
