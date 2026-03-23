# Progress - wls-daemon-time-limit-hardening

- 2026-03-23 14:24 Created the task workspace.
- 2026-03-23 22:45 Re-read WLS stop-tail context and confirmed the live inconsistency: `server:stop` reported `Master 进程不存在` while the recorded PID was still alive.
- 2026-03-23 22:53 Identified the concrete root cause on Windows foreground runs: the foreground Master process is the original `php bin/w server:start ... -frontend` command, so its real command line has no `--name=...`; `Processer::isManagedProcessRunning()` therefore rejected the live PID even though the pid-record hash matched.
- 2026-03-23 22:56 Patched `Processer::doesPidMatchRecordedIdentity()` to trust the recorded `command_line_hash` when it matches the live command line, preserving the stricter name / launch-id checks as the fallback path.
- 2026-03-23 22:57 Added a focused `ProcesserTest` regression for the foreground Master identity case.
- 2026-03-23 22:59 Re-ran `php -l` plus targeted PHPUnit for `ProcesserTest`; assertions passed and the only non-zero exit cause remained the existing repo warning about missing coverage driver.
- 2026-03-23 23:00 Ran a live detached foreground WLS instance (`codex-fg-hash`, `-frontend`, port `9990`) and verified `server:stop codex-fg-hash` now connects over IPC and completes a normal orchestrated stop instead of jumping to the false residual-cleanup path.
