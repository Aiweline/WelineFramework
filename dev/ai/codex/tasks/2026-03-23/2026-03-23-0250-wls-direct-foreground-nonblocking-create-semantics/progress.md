# Progress - wls direct foreground nonblocking create semantics

- 2026-03-23 02:50 Created the task workspace.
- 2026-03-23 10:50 During the post-commit audit, found one more same-class issue: the Windows direct foreground `Processer::create()` path still waited for PID confirmation even with `block=false`, and could fall back into a second hidden launch if PID confirmation was delayed.
- 2026-03-23 10:52 Updated `Processer::create()` / `createWindowsForeground()` so the helper returns `null` only on real launch failure, returns `0` for successful non-blocking launches without PID confirmation, and only waits for managed PID confirmation when `block=true`.
- 2026-03-23 10:53 Added regression coverage for the direct managed-PID wait policy and re-ran the focused PHPUnit suite.
