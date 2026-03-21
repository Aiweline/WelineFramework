# Active Task

- Updated: 2026-03-21 23:18
- Task File: `dev/ai/codex/tasks/2026-03-21/2026-03-21-1613-wls-control-plane-unification.md`
- Status: completed

## Current Goal

实施并完成 WLS 控制面统一修复，收口 `reload/cache/ssl reload` 入口，修正 CLI/observer/backend/query 与运行中实例的交互语义。

## Latest Progress

- 已完成控制面统一改造并提交历史提交 `e1db3f25`。
- 继续做真实运行时回归时，确认 `verify_http` 实例处于运行中，`server:reload verify_http -n` 成功触发滚动重载，Worker PID 从 `53520/32532` 切换为 `52908/53168`。
- 检查 `var/log/wls/wls.log`，本轮异步 reload 未再出现旧的 `reload` 发起端 IPC 断开中止信号；日志只保留预期的 worker drain / restart 轨迹。
- 发现 `cache:clear -f` 输出中的 WLS 超时提示是 `CliCommandExecutedObserver` 与 `CacheFlushedObserver` 对 `cache:` 命令重复派发导致的假失败，而不是 WLS 未执行缓存清理。
- 已修正 `CliCommandExecutedObserver`：不再对 `cache:` 命令二次通知，缓存清理由 `CacheFlushedObserver` 在真实 flush 后独立通知。
- 已新增 `CliCommandExecutedObserverTest`，覆盖“代码命令会触发 reload、缓存命令不会重复派发”。

## Verification

- `php -l app/code/Weline/Server/Observer/CliCommandExecutedObserver.php`
- `php -l app/code/Weline/Server/Test/Unit/Observer/CliCommandExecutedObserverTest.php`
- `vendor/bin/phpunit --no-coverage app/code/Weline/Server/Test/Unit/Observer/CliCommandExecutedObserverTest.php app/code/Weline/Server/Test/Unit/Control/IpcControlGatewayTest.php app/code/Weline/Server/Test/Unit/Control/BroadcastControlDispatchServiceTest.php`
  - 结果：`6 tests / 22 assertions / 0 failures`
  - 备注：仍有现有 `PHPUnit Deprecations: 1`
- `php bin/w server:reload verify_http -n`
  - 结果：CLI 立即返回 accepted，随后 `verify_http.json` 中 Worker PID 更新为 `52908/53168`
- `php bin/w cache:clear -f`
  - 结果：不再输出误导性的 WLS 超时提示
- PowerShell 直连控制端口发送 `cache_clear`
  - 返回：`{"type":"command_result","success":true,"message":"Cache clear broadcast sent"}`
- PowerShell 直连控制端口发送 `ssl_cert_reload` with `localhost`
  - 返回：`{"type":"command_result","success":true,"message":"SSL cert reload broadcast sent"}`

## Next

- 如需继续收尾，可将本轮运行时补修单独提交，避免和工作区其他脏改动混在一起。
