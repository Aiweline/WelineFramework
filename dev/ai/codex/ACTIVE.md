# Active Task

- Updated: 2026-03-21 22:58
- Task File: `dev/ai/codex/tasks/2026-03-21/2026-03-21-1613-wls-control-plane-unification.md`
- Status: completed

## Current Goal

实施 WLS 控制面统一修复，收口 `reload/cache/ssl reload` 入口与语义，修复 CLI/observer/FileWatcher/backend/query 在 WLS 运行时提示失真、旧 helper 分叉和异步 reload 被发起端断开打断的问题。

## Latest Progress

- 已扩展 `IpcControlGatewayInterface` / `IpcControlGateway`，新增显式 `reloadAsync()` 与 `cacheClear()`，并统一通过同一套 NDJSON 读写逻辑返回即时 `command_result`。
- 已新增 `BroadcastControlDispatchService`，统一负责发现运行中的实例、按实例发送 async reload / cache clear / ssl cert reload，并汇总 `attempted/succeeded/failed_by_instance/message`。
- 已调整 `ServiceOrchestrator`：`ACTION_RELOAD` 不再属于帝王指令，收到 ACK 后异步继续执行；`ACTION_RELOAD_WAIT`、`ACTION_STOP`、维护模式与 rolling restart 保持原有绑定语义；同时修复了 SSL reload 分支误用 `$message['domains']` 的 bug。
- 已将 `MasterProcess::sendReloadCommand()`、`sendSslCertReloadCommand()`、`sendStatusQuery()` 收口为兼容层，内部复用新 gateway。
- 已迁移调用方到统一调度服务：`CliCommandExecutedObserver`、`CacheFlushedObserver`、`ConfigChangedObserver`、`FileWatcher`、`SslCertificateService`、`server:reload` 的 async 分支、后台 `ServerManager` 的 `reload/restart`、`ServerQueryProvider` 的 `reload/restart`。
- 已新增聚焦单测：`IpcControlGatewayTest`、`BroadcastControlDispatchServiceTest`。

## Verification

- `php -l` 已通过本次变更涉及的 13 个 PHP 文件。
- `vendor/bin/phpunit --no-coverage app/code/Weline/Server/Test/Unit/Control/IpcControlGatewayTest.php app/code/Weline/Server/Test/Unit/Control/BroadcastControlDispatchServiceTest.php`
  - 结果：`4 tests / 17 assertions / 0 failures`
  - 说明：runner 仍报告 `PHPUnit Deprecations: 1`，为现有测试配置层面的残留，不属于本次改动引入。
- 实机尝试 `php bin/w server:reload -n` 时，CLI 在 2026-03-21 22:57 提示“未检测到运行中的 WLS Worker”，因此未能在当前会话下完成在线 reload 行为回归；也因此无法在本轮直接观察新的 runtime 日志序列。

## Next

- 若需要继续做在线回归，先恢复/启动当前实例，再验证：
  - `php bin/w server:reload -n`
  - `php bin/w cache:clear -f`
  - `php bin/w server:ssl:reload -d <domain>`
  - 后台/API/query 的 reload/restart
- 重点确认 `var/log/wls/wls.log` 不再出现 `帝王指令发起端 IPC 已断开 (reload)`。
