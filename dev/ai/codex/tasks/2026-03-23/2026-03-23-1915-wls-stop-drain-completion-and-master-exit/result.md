# Result - wls-stop-drain-completion-and-master-exit

## Outcome

- Fixed a real stop-flow logic bug where global stop drain carried per-instance ports, causing `Dispatcher` to stay in `DRAINING` and phase 2 to false-timeout.
- Hardened stop-flow disconnect handling so an instance that disconnects during stop no longer remains stuck in `STATE_DRAINING`.
- Added a CLI-side fallback so `server:stop` switches to waiting for Master exit once all child exits have already been observed, even if the last explicit Master-exit progress line is missing.

## Changed Files

- `app/code/Weline/Server/Service/ServiceOrchestrator.php`
- `app/code/Weline/Server/Console/Server/Stop.php`
- `app/code/Weline/Server/Test/Unit/Service/ServiceOrchestratorStopFlowTest.php`
- `app/code/Weline/Server/Test/Unit/Console/StopCommandProgressHeuristicTest.php`

## Verification

- `php -l app/code/Weline/Server/Service/ServiceOrchestrator.php`
- `php -l app/code/Weline/Server/Console/Server/Stop.php`
- `php -l app/code/Weline/Server/Test/Unit/Service/ServiceOrchestratorStopFlowTest.php`
- `php -l app/code/Weline/Server/Test/Unit/Console/StopCommandProgressHeuristicTest.php`
- `php vendor/bin/phpunit app/code/Weline/Server/Test/Unit/Service/ServiceOrchestratorStopFlowTest.php`
- `php vendor/bin/phpunit app/code/Weline/Server/Test/Unit/Console/StopCommandProgressHeuristicTest.php`
- `php vendor/bin/phpunit app/code/Weline/Server/Test/Unit/Service/ServiceOrchestratorControlQueueTest.php`
- `php vendor/bin/phpunit app/code/Weline/Server/Test/Unit/Service/ServiceOrchestratorStartupTest.php`
- Note: PHPUnit still exits non-zero only because of the existing environment warning (`No code coverage driver available`) and one deprecation, but all targeted assertions passed.

## Remaining Risks

- I did not rerun a full live `php bin/w s:start -r -f -frontend -p 9982` stop/start trace in this turn.
- `Stop.php` still contains unrelated local worktree changes outside this small fallback patch, so any future edits there should stay surgical.
