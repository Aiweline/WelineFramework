# 2026-03-21 16:13 WLS 控制面统一修复

## Goal

统一 WLS 控制面入口与语义，修复命令行通知、缓存清理、重载、SSL 证书刷新等场景下的 reload/cache 控制失效问题，并让 CLI 与 WLS 行为保持一致。

## Context

- 用户反馈：`WLS 重载：所有通知方式均失败，请检查 WLS 是否运行中`，命令行内通知不生效，缓存清理与重载等行为需要和 WLS 保持一致。
- 已确认问题根因不在于 WLS 一定没启动，而在于：
  - 控制面存在新旧两套入口并存。
  - `ACTION_RELOAD` 被当作“帝王指令”绑定发起端 IPC 生命周期，发起端断开后会中止本轮 reload。
  - 多个调用方仍硬编码 `default`，没有按运行中的实例广播。

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

## Verification

- 语法检查
  - `php -l` 通过本次修改涉及的所有 PHP 文件
- 单测
  - `vendor/bin/phpunit --no-coverage app/code/Weline/Server/Test/Unit/Control/IpcControlGatewayTest.php app/code/Weline/Server/Test/Unit/Control/BroadcastControlDispatchServiceTest.php`
  - 结果：`4 tests / 17 assertions / 0 failures`
  - 备注：runner 仍报告 `PHPUnit Deprecations: 1`，为仓库现有测试配置问题，不属于本次改动引入
- 在线回归
  - 2026-03-21 22:57 执行 `php bin/w server:reload -n`
  - CLI 实际输出：`未检测到运行中的 WLS Worker`
  - 说明：当前会话下没有可供回归的运行中 Worker，因此未能直接验证“reload 发起端断开后异步继续执行”的 runtime 行为

## Remaining Verification Targets

- 在恢复/启动 WLS 后继续验证：
  - `php bin/w server:reload -n`
  - `php bin/w cache:clear -f`
  - `php bin/w server:ssl:reload -d <domain>`
  - 后台/API/query 的 reload/restart
- 关键日志信号：
  - `var/log/wls/wls.log` 中不应再出现 `帝王指令发起端 IPC 已断开 (reload)`

## Resume Notes

- 当前实现已经完成代码层收口与聚焦单测，后续主要是在线环境回归。
- 如果继续做真机验证，优先先确认 2026-03-21 当前实例是否重新启动成功，否则所有 async reload/cache/ssl reload 验证都会被“无运行实例”短路。
