# Result - wls shared sidecar independent services

## Outcome

- Completed the independent shared Session/Memory service runtime slice for WLS.
- `server:start` now delegates shared-state runtime resolution to `SharedStateServiceManager`, which ensures per-role shared services with registry-backed reuse, consumer tracking, and explicit lock coordination.
- Shared service logs and process tags now include instance names so multi-instance output remains attributable.
- Follow-up live verification uncovered and fixed a Windows command-line parsing edge case in `SharedSidecarInspector`, so probed `instance_name` / `token_file_name` no longer retain trailing quotes after sidecar launch.

## Changed Files

- `app/code/Weline/Server/Console/Server/Start.php`
- `app/code/Weline/Server/Service/Control/SharedStateAdminService.php`
- `app/code/Weline/Server/Service/MasterProcess.php`
- `app/code/Weline/Server/Service/Provider/MemoryServerProvider.php`
- `app/code/Weline/Server/Service/Provider/SessionServerProvider.php`
- `app/code/Weline/Server/Service/ServiceOrchestrator.php`
- `app/code/Weline/Server/Service/SharedSidecarInspector.php`
- `app/code/Weline/Server/Test/Unit/Service/SharedSidecarInspectorTest.php`
- `app/code/Weline/Server/Service/SharedStateServiceManager.php`
- `app/code/Weline/Server/Service/SharedStateServiceRegistry.php`
- `app/code/Weline/Server/Test/Unit/Console/StartSharedStateRuntimeConfigTest.php`
- `app/code/Weline/Server/Test/Unit/Service/ServiceOrchestratorStartupTest.php`
- `app/code/Weline/Server/Test/Unit/Service/SharedStateServiceManagerTest.php`
- `app/code/Weline/Server/bin/dispatcher.php`
- `app/code/Weline/Server/bin/http_redirect_worker.php`
- `app/code/Weline/Server/bin/session_server.php`
- `app/code/Weline/Server/bin/worker.php`
- `app/code/Weline/Server/bin/worker_ssl.php`
- `app/code/Weline/Server/i18n/en_US.csv`
- `app/code/Weline/Server/i18n/zh_Hans_CN.csv`
- `dev/ai/codex/tasks/2026-03-24/2026-03-24-0309-wls-shared-sidecar-independent-services/task.md`
- `dev/ai/codex/tasks/2026-03-24/2026-03-24-0309-wls-shared-sidecar-independent-services/plan.md`
- `dev/ai/codex/tasks/2026-03-24/2026-03-24-0309-wls-shared-sidecar-independent-services/progress.md`
- `dev/ai/codex/tasks/2026-03-24/2026-03-24-0309-wls-shared-sidecar-independent-services/result.md`

## Verification

- `php -l app/code/Weline/Server/Service/SharedStateServiceManager.php`
- `php -l app/code/Weline/Server/Service/SharedStateServiceRegistry.php`
- `php -l app/code/Weline/Server/Console/Server/Start.php`
- `php vendor/bin/phpunit --no-coverage app/code/Weline/Server/Test/Unit/Service/SharedStateServiceManagerTest.php app/code/Weline/Server/Test/Unit/Console/StartSharedStateRuntimeConfigTest.php app/code/Weline/Server/Test/Unit/Service/ServiceOrchestratorStartupTest.php --colors=never`
- `php -l app/code/Weline/Server/Service/SharedSidecarInspector.php`
- `php -l app/code/Weline/Server/Test/Unit/Service/SharedSidecarInspectorTest.php`
- `vendor/bin/phpunit.bat --configuration phpunit.xml --no-coverage app/code/Weline/Server/Test/Unit/Service/SharedSidecarInspectorTest.php app/code/Weline/Server/Test/Unit/Service/SharedStateServiceManagerTest.php app/code/Weline/Server/Test/Unit/Console/StartSharedStateRuntimeConfigTest.php app/code/Weline/Server/Test/Unit/Service/ServiceOrchestratorStartupTest.php`
- Task-local live smoke on temporary shared ports `29070/29071` via `artifacts/shared-state-smoke.php`
- `php -l app/code/Weline/Server/Test/Unit/Service/SharedStateServiceManagerTest.php`
- `php -l dev/ai/codex/tasks/2026-03-24/2026-03-24-0309-wls-shared-sidecar-independent-services/artifacts/shared-state-multi-worker.php`
- `php -l dev/ai/codex/tasks/2026-03-24/2026-03-24-0309-wls-shared-sidecar-independent-services/artifacts/shared-state-multi-smoke.php`
- `vendor/bin/phpunit.bat --configuration phpunit.xml --no-coverage app/code/Weline/Server/Test/Unit/Service/SharedStateServiceManagerTest.php app/code/Weline/Server/Test/Unit/Service/SharedSidecarInspectorTest.php`
- Task-local concurrent live smoke on temporary shared ports `29080/29081` with `6` parallel workers via `artifacts/shared-state-multi-smoke.php`
- Foreground shared-sidecar log check on temporary port `29072` via `app/code/Weline/Server/bin/session_server.php ... --frontend`

## Live Verification Highlights

- First ensure created the shared `session_server` / `memory_server`; second ensure reused the same PIDs and process names.
- The temporary in-memory registry recorded both consumer instances (`smoke-a`, `smoke-b`) without touching the real shared-service registry file.
- The foreground `session_server` log output included the live tag `[SessionServer:29072@log-check-instance]`, the explicit `Instance: log-check-instance` line, and the `Bootstrap requester instance: log-check-bootstrap` line.
- A concurrent six-instance smoke (`multi-smoke-1..6`) finished with `all_workers_succeeded=true`, `single_session_process=true`, `single_memory_process=true`, `session_created_count=1`, and `memory_created_count=1`, proving the design is not limited to a special dual-instance case.
- The isolated temp registry recorded all six consumer instances under both roles, showing the shared-service ownership model now scales as `N` consumers -> `1` shared Session service + `1` shared Memory service.
- Temporary live listeners on `29070`, `29071`, and `29072` were cleaned up after verification.
- The concurrent temp listeners on `29080` / `29081` were also cleaned up; only `TIME_WAIT` sockets remained after the smoke.
- The supporting smoke scripts / JSON outputs / stdout captures remain in the task-local `artifacts/` directory, which is intentionally ignored by `dev/.gitignore` and therefore not part of the commit.

## Remaining Risks

- A full end-to-end `server:start` multi-instance runtime regression with several real WLS instances has still not been re-run in this slice; the live validation here focused on the shared sidecar ensure/probe/logging path itself under parallel pressure.
- The repo still contains unrelated uncommitted WeShop, Websites, i18n, and temp-file drift outside this commit boundary.

## Next Resume Step

- If more confidence is needed, run a real `server:start` / `server:status` / `server:stop` regression against several non-default WLS instances concurrently while keeping the shared Session/Memory services on the same configured shared endpoints.
