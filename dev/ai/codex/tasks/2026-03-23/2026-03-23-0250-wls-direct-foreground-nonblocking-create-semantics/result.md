# Result - wls direct foreground nonblocking create semantics

## Outcome

- Completed the direct-create follow-up fix.
- Windows direct foreground launches now respect `block` semantics:
  - `block=false` no longer waits for PID confirmation
  - a foreground launch that already started will not fall through into a second hidden launch just because PID confirmation is delayed

## Changed Files

- `app/code/Weline/Framework/System/Process/Processer.php`
- `app/code/Weline/Framework/Test/ProcesserTest.php`

## Verification

- `php -l app/code/Weline/Framework/System/Process/Processer.php`
- `php -l app/code/Weline/Framework/Test/ProcesserTest.php`
- `php vendor/bin/phpunit --no-coverage app/code/Weline/Framework/Test/ProcesserTest.php app/code/Weline/Server/Test/Unit/Service/ServiceOrchestratorStartupTest.php`
  - Result: `36` tests, `75` assertions, pass
  - Note: PHPUnit still reports one existing deprecation from the current suite/config

## Remaining Risks

- The direct foreground launch path still relies on later IPC/register updates when `block=false`, so a real Windows frontend runtime pass is still the best final proof for the human-visible launch experience.

## Next Resume Step

- Run an isolated Windows frontend single-instance launch path that exercises `Processer::create(... foreground=true, block=false)` and confirm it returns immediately without spawning a duplicate hidden process.
