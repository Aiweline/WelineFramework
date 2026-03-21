# 2026-03-21 16:13 WLS 控制面统一修复

## Goal

统一 WLS 控制面入口与语义，修复命令行通知、缓存清理、重载、SSL 证书刷新等场景下的 reload/cache 控制失效问题，并让 CLI 与 WLS 运行状态提示保持一致。

## Context

- 用户反馈：`WLS 重载：所有通知方式均失败，请检查 WLS 是否运行中`，命令行通知、缓存清理与重载等行为没有和真实 WLS 状态保持一致。
- 已确认根因不在“WLS 一定没启动”，而在于：
  - 控制面新旧两套入口并存。
  - `ACTION_RELOAD` 之前被当作“帝王指令”并绑定发起端 IPC 生命周期，发起端断开会中止本轮 reload。
  - 多个调用方硬编码 `default`，没有广播实际运行中的实例。

## Implementation Summary

1. 控制面基础层
- 扩展 `IpcControlGatewayInterface` / `IpcControlGateway`
  - 新增 `reloadAsync(string $instanceName, string $reloadType, float $timeout = 3.0): array`
  - 新增 `cacheClear(string $instanceName, float $timeout = 3.0): array`
  - 统一通过同一套 NDJSON 循环读取 `command_result`
- 新增 `BroadcastControlDispatchService`
  - 发现运行中的实例
  - 按实例发送 async reload / cache clear / ssl cert reload
  - 汇总 `attempted/succeeded/failed_by_instance/message`
- `MasterProcess::sendReloadCommand()`、`sendSslCertReloadCommand()`、`sendStatusQuery()` 已改为兼容层，内部复用新 gateway

2. Orchestrator 语义修正
- `ACTION_RELOAD`
  - 不再属于帝王指令
  - 发送 `Reload initiated` ACK 后继续异步执行
  - 发起端断开不再打断执行
- `ACTION_RELOAD_WAIT`
  - 保持等待完成与进度输出语义
- `ACTION_STOP`、`ACTION_MAINTENANCE_ENABLE`、`ACTION_MAINTENANCE_DISABLE`、`ACTION_ROLLING_RESTART`
  - 保持原有绑定和取消语义
- 修复 `ACTION_SSL_CERT_RELOAD` 分支误用 `$message['domains']` 的 bug，改为读取 `$msg['domains']`

3. 调用方迁移
- 已迁移到 `BroadcastControlDispatchService`
  - `app/code/Weline/Server/Observer/CliCommandExecutedObserver.php`
  - `app/code/Weline/Server/Observer/CacheFlushedObserver.php`
  - `app/code/Weline/Server/Observer/ConfigChangedObserver.php`
  - `app/code/Weline/Server/Service/FileWatcher.php`
  - `app/code/Weline/Server/Service/SslCertificateService.php`
  - `app/code/Weline/Server/Console/Server/Reload.php` 的 async 分支
  - `app/code/Weline/Server/Controller/Backend/ServerManager.php`
  - `app/code/Weline/Server/extends/module/Weline_Framework/Query/ServerQueryProvider.php`
- 自动通知场景已去掉 `default` 硬编码，改为广播全部运行实例
- 后台/API/query 的 reload/restart 现在能返回实例级失败原因，而不是统一提示“请检查 WLS 是否运行中”

## Changed Files

- `app/code/Weline/Server/Service/Control/IpcControlGatewayInterface.php`
- `app/code/Weline/Server/Service/Control/IpcControlGateway.php`
- `app/code/Weline/Server/Service/Control/BroadcastControlDispatchService.php`
- `app/code/Weline/Server/Service/ServiceOrchestrator.php`
- `app/code/Weline/Server/Service/MasterProcess.php`
- `app/code/Weline/Server/Observer/CliCommandExecutedObserver.php`
- `app/code/Weline/Server/Observer/CacheFlushedObserver.php`
- `app/code/Weline/Server/Observer/ConfigChangedObserver.php`
- `app/code/Weline/Server/Service/FileWatcher.php`
- `app/code/Weline/Server/Service/SslCertificateService.php`
- `app/code/Weline/Server/Console/Server/Reload.php`
- `app/code/Weline/Server/Controller/Backend/ServerManager.php`
- `app/code/Weline/Server/extends/module/Weline_Framework/Query/ServerQueryProvider.php`
- `app/code/Weline/Server/Test/Unit/Control/IpcControlGatewayTest.php`
- `app/code/Weline/Server/Test/Unit/Control/BroadcastControlDispatchServiceTest.php`
- `app/code/Weline/Server/Test/Unit/Observer/CliCommandExecutedObserverTest.php`

## Verification

- `php -l` 通过本次修改涉及的 PHP 文件
- `vendor/bin/phpunit --no-coverage app/code/Weline/Server/Test/Unit/Control/IpcControlGatewayTest.php app/code/Weline/Server/Test/Unit/Control/BroadcastControlDispatchServiceTest.php`
  - 结果：`4 tests / 17 assertions / 0 failures`
  - 备注：runner 仍报 `PHPUnit Deprecations: 1`，属于仓库现有配置问题

## Runtime Follow-up

- 已确认 `verify_http` 实例实际处于运行中：
  - `var/server/instances/verify_http.json` 中 `master_enabled=true`
  - `master_pid=52436`
  - Worker / Dispatcher / Session / Memory 均为 `ready`
- `php bin/w server:reload verify_http -n`
  - CLI 立即返回 accepted
  - 后续 `verify_http.json` 中 Worker PID 从 `53520/32532` 更新为 `52908/53168`
  - `var/log/wls/wls.log` 只出现预期的 `drain -> shutdown -> restart -> ready` 轨迹，未再出现 reload 发起端 IPC 断开中止日志
- `php bin/w cache:clear -f`
  - 暴露一个运行时假失败：CLI 提示 WLS 缓存清理超时，但 `wls.log` 同时记录两个 Worker 已收到 `cache_clear` 并完成执行
  - 根因是 `CliCommandExecutedObserver` 与 `CacheFlushedObserver` 对 `cache:` 命令重复派发，不是 WLS 不可用
- 已追加补修：
  - `CliCommandExecutedObserver` 不再对 `cache:` 命令发起二次 WLS 通知
  - 缓存清理仅由 `CacheFlushedObserver` 在真实 flush 后通知
- 回归验证
  - `php -l app/code/Weline/Server/Observer/CliCommandExecutedObserver.php`
  - `php -l app/code/Weline/Server/Test/Unit/Observer/CliCommandExecutedObserverTest.php`
  - `vendor/bin/phpunit --no-coverage app/code/Weline/Server/Test/Unit/Observer/CliCommandExecutedObserverTest.php app/code/Weline/Server/Test/Unit/Control/IpcControlGatewayTest.php app/code/Weline/Server/Test/Unit/Control/BroadcastControlDispatchServiceTest.php`
    - 结果：`6 tests / 22 assertions / 0 failures`
  - 再次执行 `php bin/w cache:clear -f`，已不再输出误导性的 WLS 超时提示
- 直接使用 PowerShell TCP 探针验证控制面回包
  - `cache_clear` 返回 `{"type":"command_result","success":true,"message":"Cache clear broadcast sent"}`
  - `ssl_cert_reload` 返回 `{"type":"command_result","success":true,"message":"SSL cert reload broadcast sent"}`

## Resume Notes

- 当前代码收口、单测与关键 runtime 验证已经完成。
- 历史提交 `e1db3f25` 覆盖控制面统一改造；本轮继续验证中发现并修复了 `cache:` 命令重复派发导致的假失败提示。
- 如需继续收尾，优先将本轮 observer 补修与单测单独提交，避免和工作区其他脏改动混在一起。
