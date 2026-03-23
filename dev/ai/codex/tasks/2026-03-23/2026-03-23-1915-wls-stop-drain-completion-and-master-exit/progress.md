# Progress - wls-stop-drain-completion-and-master-exit

- 2026-03-23 19:15 Created the task workspace.
- 2026-03-23 19:20 Mapped the user stop trace to the current code and identified a concrete root cause: `broadcastDrainToAll()` sends per-instance ports during global stop, so `Dispatcher` treats stop-all drain as a selective worker drain instead of an immediate global drain completion.
- 2026-03-23 19:22 Identified a second stop-flow weakness: stop-time disconnect handling clears `ipcClientId` but leaves `STATE_DRAINING` untouched, so a draining instance can continue to count against phase-2 drain completion.
- 2026-03-23 19:32 Patched `ServiceOrchestrator` so global stop drain always sends an empty port list and stop-flow disconnects push instances out of `STATE_DRAINING`.
- 2026-03-23 19:36 Added `Stop` CLI fallback logic so once all child PIDs have already reported `已退出/已断开连接`, the command transitions to waiting for Master exit even if the final explicit progress sentence is missing.
- 2026-03-23 19:40 Added focused unit coverage for both regressions and re-ran the targeted syntax/PHPUnit checks successfully.
