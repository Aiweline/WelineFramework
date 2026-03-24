# Progress - wls shared sidecar reuse

- 2026-03-24 01:34 Created the task workspace.
- 2026-03-24 11:06 Reconstructed the in-flight WLS sidecar-reuse patch from the current worktree and verified the intended scope stays limited to multi-instance shared Session/Memory reuse.
- 2026-03-24 11:06 Confirmed the implementation now resolves shared-state runtime config from live port occupants, adopts the existing token file name, skips startup-side forced port release when reuse is possible, and lets the consumer master register adopted sidecars as `shared_external`.
- 2026-03-24 11:06 Added one more safety guard in `ServiceOrchestrator::killInstanceProcess()` so any late/indirect stop path still refuses to terminate an externally managed shared sidecar.
- 2026-03-24 11:06 Ran focused syntax + PHPUnit verification (`17` tests / `48` assertions). Also probed the live machine state and confirmed ports `19970` and `19971` were currently held by WLS `session_server.php` sidecars with instance-suffixed legacy token names (`session_server.weshop-acceptance.token`, `memory_server.weshop-acceptance.token`), which validates the need to adopt the running token instead of killing or renaming the service.
