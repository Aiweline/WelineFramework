# Progress - wls shared sidecar independent services

- 2026-03-24 03:09 Created the task workspace.
- 2026-03-24 03:14 Reviewed the previous `wls-shared-sidecar-reuse` slice and confirmed the remaining architectural gap: shared Session/Memory were still only “adopted from another instance” instead of being promoted to true instance-independent shared services.
- 2026-03-24 03:19 Mapped the main coupling points in `Start.php`, `ServiceOrchestrator.php`, `SessionServerProvider.php`, `MemoryServerProvider.php`, `ServerInstanceManager.php`, and the WLS entry scripts. The key issues are instance-scoped process names, instance-local runtime fallback/port switching, and weak “port occupant” reuse detection.
- 2026-03-24 03:21 Added the new explicit requirement from the user to the task plan: all related logs must include an instance name so shared-service behavior remains distinguishable in multi-instance output.
- 2026-03-24 03:45 Implemented `SharedStateServiceManager` plus `SharedStateServiceRegistry` so Session/Memory shared state services are ensured as independent shared services with per-role locking, registry persistence, and consumer tracking.
- 2026-03-24 03:46 Refactored `Weline\\Server\\Console\\Server\\Start` to delegate shared-state runtime resolution to the manager and print explicit reuse/created summaries; updated runtime/admin consumers to read the registry-backed shared endpoints.
- 2026-03-24 03:47 Added instance-name-aware logging across Master, Dispatcher, Worker, SSL Worker, HTTP Redirect, and shared sidecar entry scripts, and passed `--instance-name` / `--bootstrap-instance` / `--shared-service` through the shared-service boot path.
- 2026-03-24 03:49 Verification passed:
- `php -l app/code/Weline/Server/Service/SharedStateServiceManager.php`
- `php -l app/code/Weline/Server/Service/SharedStateServiceRegistry.php`
- `php -l app/code/Weline/Server/Console/Server/Start.php`
- `php vendor/bin/phpunit --no-coverage app/code/Weline/Server/Test/Unit/Service/SharedStateServiceManagerTest.php app/code/Weline/Server/Test/Unit/Console/StartSharedStateRuntimeConfigTest.php app/code/Weline/Server/Test/Unit/Service/ServiceOrchestratorStartupTest.php --colors=never`
