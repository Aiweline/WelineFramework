# Result - wls shared sidecar reuse

## Outcome

- Completed the shared-sidecar reuse slice for WLS multi-instance startup.
- `server:start` now inspects occupied shared-state ports and reuses matching Weline `Session Server` / `Memory Service` sidecars instead of force-killing them.
- Consumer masters adopt the reused sidecar into the local registry as `shared_external`, skip local stop/kill/cleanup against it, and can self-heal by starting a local replacement if the adopted sidecar later disappears.
- Shared-state token naming now tracks runtime identity by port, while reuse adopts the token file name exposed by the currently running sidecar process.

## Changed Files

- [Start.php](e:/WelineFramework/DEV-workspace/app/code/Weline/Server/Console/Server/Start.php)
- [ServiceOrchestrator.php](e:/WelineFramework/DEV-workspace/app/code/Weline/Server/Service/ServiceOrchestrator.php)
- [SharedSidecarInspector.php](e:/WelineFramework/DEV-workspace/app/code/Weline/Server/Service/SharedSidecarInspector.php)
- [StartSharedStateRuntimeConfigTest.php](e:/WelineFramework/DEV-workspace/app/code/Weline/Server/Test/Unit/Console/StartSharedStateRuntimeConfigTest.php)
- [ServiceOrchestratorStartupTest.php](e:/WelineFramework/DEV-workspace/app/code/Weline/Server/Test/Unit/Service/ServiceOrchestratorStartupTest.php)
- [ServiceOrchestratorStopFlowTest.php](e:/WelineFramework/DEV-workspace/app/code/Weline/Server/Test/Unit/Service/ServiceOrchestratorStopFlowTest.php)

## Verification

- `php -l app/code/Weline/Server/Console/Server/Start.php`
- `php -l app/code/Weline/Server/Service/ServiceOrchestrator.php`
- `php -l app/code/Weline/Server/Service/SharedSidecarInspector.php`
- `vendor/bin/phpunit.bat --configuration phpunit.xml --no-coverage app/code/Weline/Server/Test/Unit/Console/StartSharedStateRuntimeConfigTest.php app/code/Weline/Server/Test/Unit/Service/ServiceOrchestratorStartupTest.php app/code/Weline/Server/Test/Unit/Service/ServiceOrchestratorStopFlowTest.php`
  Result: `17` tests, `48` assertions, pass. Raw PHPUnit output still reports the existing repo-level `PHPUnit Deprecations: 1`.
- Live OS probe:
  `Get-NetTCPConnection -LocalPort 19970,19971`
  plus `Get-CimInstance Win32_Process`
  confirmed both shared-state ports were actively held by `app/code/Weline/Server/bin/session_server.php` sidecars, with command lines carrying the current runtime token names
  `session_server.weshop-acceptance.token` and `memory_server.weshop-acceptance.token`.

## Remaining Risks

- Shared sidecars are still effectively owned by whichever master launched them first. This slice fixes reuse and prevents consumer instances from killing them, but does not yet make the shared sidecar survive the owner master exiting without a later failover/self-heal cycle.
- No fresh full end-to-end `server:start` / second-instance smoke run was recorded in this slice because the local WLS runtime remains heavy and noisy outside the focused unit/probe verification above.

## Next Resume Step

- If the next user ask stays on WLS multi-instance runtime, run a live two-instance startup smoke after this commit and then evaluate whether ownership transfer for shared sidecars should become a separate follow-up slice.
