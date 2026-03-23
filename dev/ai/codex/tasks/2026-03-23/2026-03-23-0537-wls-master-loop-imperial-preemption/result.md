# Result - wls master loop imperial preemption

## Outcome

- Master periodic maintenance work now yields to queued imperial commands instead of running to completion first.
- Control-plane command discovery now uses persisted instance metadata, so reload dispatch no longer pays the stale-instance realtime process-check cost.
- The committed slice is `2e6634db` (`perf(wls): let imperial commands preempt master loop`).

## Changed Files

- `app/code/Weline/Server/Service/ServiceOrchestrator.php`
- `app/code/Weline/Server/Service/ServerInstanceManager.php`
- `app/code/Weline/Server/Service/Control/BroadcastControlDispatchService.php`
- `app/code/Weline/Server/Test/Unit/Control/BroadcastControlDispatchServiceTest.php`
- `app/code/Weline/Server/Test/Unit/Service/ServerInstanceManagerRunningStatsTest.php`

## Verification

- `php -l app/code/Weline/Server/Service/ServiceOrchestrator.php`
- `php -l app/code/Weline/Server/Service/ServerInstanceManager.php`
- `php -l app/code/Weline/Server/Service/Control/BroadcastControlDispatchService.php`
- `php -l app/code/Weline/Server/Test/Unit/Control/BroadcastControlDispatchServiceTest.php`
- `php -l app/code/Weline/Server/Test/Unit/Service/ServerInstanceManagerRunningStatsTest.php`
- `php vendor/bin/phpunit app/code/Weline/Server/Test/Unit/Control/BroadcastControlDispatchServiceTest.php`
- `php vendor/bin/phpunit app/code/Weline/Server/Test/Unit/Service/ServerInstanceManagerRunningStatsTest.php`
- `php vendor/bin/phpunit app/code/Weline/Server/Test/Unit/Service/ServiceOrchestratorControlQueueTest.php`
- `php vendor/bin/phpunit app/code/Weline/Server/Test/Unit/Service/ServiceOrchestratorStartupTest.php`
- Runtime probe: repeated `php bin/w server:reload --no-wait`
- Post-commit runtime probe: `php bin/w server:reload --no-wait` completed in about `0.69s` locally
- Runtime probe: repeated static `curl` requests still warm from `MISS` to `HIT`
- Note: PHPUnit still exits non-zero because of the existing repo warning/deprecation environment (`No code coverage driver available` + one deprecation), but all targeted assertions passed.

## Remaining Risks

- Static cache is still per-worker in-memory cache, so with multiple workers the first request per worker can still show `MISS` before both workers are warm.
- `server:reload --wait` total completion time is still governed by the actual rolling-restart workflow; this slice only removed the avoidable command-dispatch waiting.

## Next Resume Step

- If we continue on WLS latency, the next slice is to instrument and trim the rolling-restart execution body itself, not just the command admission path.
