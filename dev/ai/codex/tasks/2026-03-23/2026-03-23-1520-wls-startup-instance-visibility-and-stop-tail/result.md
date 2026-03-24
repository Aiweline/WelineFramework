# Result - WLS Startup Instance Visibility And Stop Tail

## Outcome

- In progress, with the current continuation focused on the stop CLI tail.
- `Stop.php` no longer assumes one `fread()` call equals one complete IPC progress message.
- The stop CLI now buffers fragmented IPC frames, flushes the final partial line on socket close, and can enter the final Master-exit wait from the observed stop-complete stage instead of depending only on a single exact final progress phrase.

## Changed Files

- `app/code/Weline/Server/Console/Server/Stop.php`
- `app/code/Weline/Server/Test/Unit/Console/StopCommandIpcStreamBufferTest.php`

## Verification

- `php -l app/code/Weline/Server/Console/Server/Stop.php`
- `php -l app/code/Weline/Server/Test/Unit/Console/StopCommandIpcStreamBufferTest.php`
- `vendor/bin/phpunit.bat --configuration phpunit.xml app/code/Weline/Server/Test/Unit/Console/StopCommandIpcStreamBufferTest.php`
- `vendor/bin/phpunit.bat --configuration phpunit.xml app/code/Weline/Server/Test/Unit/Console/StopCommandProgressHeuristicTest.php`
- PHPUnit assertions passed; raw exit code remains non-zero because the repo environment still emits `No code coverage driver available` and existing deprecation noise.

## Remaining Risks

- Fresh live WLS start/stop validation is still limited by the local runtime environment drift and unrelated startup blockers noted earlier in the day.
- This patch removes one strong root cause for false 45s stop waits, but the broader WLS control-plane work still needs follow-up on Master loop yielding / imperial-command serialization if further live traces show delay after IPC handling itself is healthy.

## Next Resume Step

- Re-run a live WLS stop/reload probe against a healthy instance and confirm the old hard-timeout tail is gone under real IPC traffic, then continue only if a separate post-IPC latency remains.
