# Result - wls-static-cache-hit-and-reload-latency

## Outcome

- Improved WLS control-plane responsiveness for non-wait reload/cache-clear commands and removed the most expensive per-worker runtime probing from reload preflight statistics.
- Static asset responses continue to warm from `MISS` to `HIT`, and the static fast path now keeps cache-status reporting aligned with repeated requests more reliably.
- Code commit: `03bc8f5c` (`perf(wls): speed reload control path`).

## Changed Files

- `app/code/Weline/Server/Service/Contract/ServiceInfo.php`
- `app/code/Weline/Server/Service/Contract/ServerInstanceInfo.php`
- `app/code/Weline/Server/Service/ServerInstanceManager.php`
- `app/code/Weline/Server/Service/Control/IpcControlGatewayInterface.php`
- `app/code/Weline/Server/Service/Control/BroadcastControlDispatchService.php`
- `app/code/Weline/Server/Service/Control/IpcControlGateway.php`
- `app/code/Weline/Server/Test/Unit/Control/IpcControlGatewayTest.php`
- `app/code/Weline/Server/Test/Unit/Service/ServerInstanceManagerRunningStatsTest.php`
- `app/code/Weline/Server/bin/worker.php`
- `app/code/Weline/Server/bin/worker_ssl.php`

## Verification

- `php -l app/code/Weline/Server/Service/Contract/ServiceInfo.php`
- `php -l app/code/Weline/Server/Service/Contract/ServerInstanceInfo.php`
- `php -l app/code/Weline/Server/Service/ServerInstanceManager.php`
- `php -l app/code/Weline/Server/Service/Control/IpcControlGatewayInterface.php`
- `php -l app/code/Weline/Server/Service/Control/BroadcastControlDispatchService.php`
- `php -l app/code/Weline/Server/Service/Control/IpcControlGateway.php`
- `php -l app/code/Weline/Server/Test/Unit/Control/IpcControlGatewayTest.php`
- `php -l app/code/Weline/Server/Test/Unit/Service/ServerInstanceManagerRunningStatsTest.php`
- `php -l app/code/Weline/Server/bin/worker.php`
- `php -l app/code/Weline/Server/bin/worker_ssl.php`
- `php vendor/bin/phpunit app/code/Weline/Server/Test/Unit/Service/ServiceInfoRuntimeStatusTest.php`
- `php vendor/bin/phpunit app/code/Weline/Server/Test/Unit/Service/ServerInstanceManagerRunningStatsTest.php`
- `php vendor/bin/phpunit app/code/Weline/Server/Test/Unit/Control/IpcControlGatewayTest.php`
- Runtime probe: repeated `curl.exe -k -s -D - -o NUL https://127.0.0.1:9982/GuoLaiRen/PageBuilder/view/templates/style/ludo-empire/asset/css/home.css`
- Runtime probe: `php bin/w server:reload --no-wait`
- Note: PHPUnit still exits non-zero in this repo because of the existing warning/deprecation environment (`No code coverage driver available` + one deprecation), but all targeted assertions passed.

## Remaining Risks

- `server:reload --no-wait` no longer false-fails, but total CLI wall time is still noticeably non-trivial; the next likely hotspot is Master main-loop work such as health/reconcile/audit slices monopolizing the loop between IPC polls.
- Browser-style conditional cache validation (`If-None-Match` / `If-Modified-Since`) still deserves a deeper pass if the user continues to see cache-visibility inconsistencies in DevTools.

## Next Resume Step

- Profile the Master main loop around health checks / reconcile / worker liveness audits so imperial commands can preempt long maintenance work instead of waiting behind it.
