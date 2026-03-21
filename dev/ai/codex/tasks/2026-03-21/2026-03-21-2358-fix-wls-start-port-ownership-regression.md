# Task: Fix WLS Start Port Ownership Regression

- Started: 2026-03-21 23:58
- Status: in_progress
- Owner: Codex

## Goal

修复 `php bin/w s:start` 在默认主端口 `9981` 上无法启动的问题，重点处理 Windows 下端口占用进程身份识别异常导致的误判与错误提示。

## Context

- 用户当前执行 `php bin/w s:start` 时，CLI 输出 `主端口 9981 被非框架进程占用`，并拒绝自动切换端口。
- 已确认 `Get-NetTCPConnection -LocalPort 9981 -State Listen` 显示监听 PID 为 `42912`，但此前 `tasklist` / `Get-Process` 对同一 PID 查询失败，存在 Windows 侧 PID 可见性异常或瞬时失配。
- 此前 WLS 控制面统一修复已经完成，`reload` / `cache clear` / `ssl reload` 均已验证，当前问题集中在启动前的端口归属识别与启动决策。
- 仓库存在无关历史脏改动风险，尤其需要谨慎处理 `app/code/Weline/Server/Console/Console/Server/Start.php`。

## Progress

- 已完成工作区启动约定读取与技能路由。
- 已复现并确认：`server:kill-port` 在 `KillPort.php:48` 调用 `Processer::inspectPortOccupant()` 时发生致命错误（方法缺失）。
- 已在 `app/code/Weline/Framework/System/Process/Processer.php` 增加 `inspectPortOccupant()`，返回结构与 `KillPort` 约定一致（`in_use/pid/pid_running/is_weline/state`）。
- 已验证 `php bin/w server:kill-port 9981 -f` 可执行且不再抛 undefined method；当前仅剩端口幽灵 PID 释放失败（系统层问题）。

## Verification Plan

- `php bin/w s:start`
- `php bin/w server:status`
- `php bin/w server:kill-port 9981 --info`
- 必要时补充针对 Windows 端口归属识别的自动化测试

## Verification

- `php bin/w server:kill-port 9981 -f`（通过：无致命错误）
- `ReadLints app/code/Weline/Framework/System/Process/Processer.php`（通过：无 lints）

## Notes

- 修复目标优先级：
  1. 避免把“无法确认归属”的端口直接误报为“非框架进程”。
  2. 若该占用其实属于当前 WLS 实例，应允许识别并给出可恢复路径。
  3. 启动失败与提示文案需要与真实状态一致，避免误导用户。

## 2026-03-22 Update

- Investigated live Windows port state for `127.0.0.1:9981`.
- Confirmed the `LISTEN` owner PID reported by `Get-NetTCPConnection` no longer exists in `Get-Process` or `tasklist`.
- Confirmed browser and `curl` are only client-side symptoms. They do not explain a dead-PID `LISTEN` socket by themselves.
- Found a separate trigger path: `ThemePreviewGenerator` was spawning headless Chrome against the same WLS port and could hang indefinitely. Added a bounded `proc_open` runner with timeout and process-tree cleanup in `app/code/Weline/Theme/Service/ThemePreviewGenerator.php`.
- Added ghost-port classification recovery in process management:
  - `Processer::inspectPortOccupantWithHistory()` upgrades orphan ports to `weline` when `name_index` still contains matching WLS command history for that port.
  - `server:start` and `server:kill-port` now use that ghost-aware inspection path.
  - `server:start::isServerRunning()` now requires a live Weline PID instead of treating any occupied port as "already running".
  - `server:stop::findWelineServerInstanceNameByPort()` now only reports an instance as occupying a port when a live Weline process is actually present.
- Syntax verification passed:
  - `php -l app/code/Weline/Framework/System/Process/Processer.php`
  - `php -l app/code/Weline/Server/Console/Server/Start.php`
  - `php -l app/code/Weline/Server/Console/Server/Stop.php`
  - `php -l app/code/Weline/Server/Console/Server/KillPort.php`
  - `php -l app/code/Weline/Theme/Service/ThemePreviewGenerator.php`

## 2026-03-21 Late Update

- Re-verified current runtime after local reproduction:
  - `php bin/w s:start` now deterministically reports `9981` as `异常占用状态（系统返回的 PID 已失效）`.
  - `php bin/w server:kill-port 9981 --info` now reports `异常占用`, not `非框架进程`.
  - `taskkill /F /PID 42912` returns `The process "42912" not found`, confirming this is an OS-level orphan listener rather than a normal live PID the framework can terminate.
- Hardened process-name based discovery in `app/code/Weline/Framework/System/Process/Processer.php`:
  - `isProcessRunningByName()` now delegates to strictly filtered managed PIDs instead of trusting raw command-line substring matches.
  - `getProcessIdsByName()` now re-validates each candidate with `isManagedProcessRunning(...)` so ad-hoc PHP/debug commands that merely mention `weline-wls-*` no longer produce false positives.
  - `isPortUsedByWeline()` now consistently reuses `inspectPortOccupant()`.
  - `inspectPortOccupant()` now falls back to current `pid_index` evidence only, avoiding both false foreign and false Weline classifications caused by stale `name_index` history.
  - `clearPortCache()` now also clears the orphan-port hint cache.
- Added instance-file-missing recovery guardrails in `app/code/Weline/Server/Console/Server/Stop.php`:
  - `findWelineServerInstanceNameByPort()` falls back to saved instance config only when there is a fast managed-process hint.
  - `server:stop <instance> -f` no longer hangs for a missing instance file; it returns quickly and only enters cleanup when a recoverable managed-process hint exists.
  - Safe cleanup path uses WLS name prefixes rather than blindly trusting stale residual PID lists.
- Verification completed:
  - `php -l app/code/Weline/Framework/System/Process/Processer.php`
  - `php -l app/code/Weline/Server/Console/Server/Stop.php`
  - `php vendor/bin/phpunit app/code/Weline/Framework/Test/ProcesserTest.php`
    - Test assertions passed; PHPUnit returned a warning exit because no code coverage driver is installed.
  - `php bin/w server:kill-port 9981 --info`
  - `php bin/w server:stop default -f`
  - `php bin/w server:status --all`
  - `php bin/w s:start`
- Current external blocker remains:
  - `127.0.0.1:9981` is still held by an orphan listener that Windows reports with PID `42912`, but the PID does not exist for `tasklist`, `Get-Process`, `Get-CimInstance`, or `taskkill`.
  - Framework-level cleanup cannot reclaim this socket because there is no killable live process behind that PID.

## 2026-03-22 Port Self-Heal Update

- Added a startup self-heal path in `app/code/Weline/Server/Console/Server/Start.php` for orphan main ports.
- New behavior:
  - If the main port is in `orphan` state and the user did not explicitly pass `-p`, `server:start` scans upward for the next free port and switches automatically.
  - The fallback port is written back into `var/server/config/<instance>.json` after successful startup, so later `php bin/w s:start` stays aligned with the recovered WLS instance.
  - `workerPort` is recalculated after the main port changes, preventing stale worker-port derivation after fallback.
- Live verification:
  - `php bin/w s:start` auto-switched `default` from `9981` to `9982`.
  - `curl.exe -vk --max-time 8 https://127.0.0.1:9982/` returned `HTTP/1.1 200 OK`.
  - `curl.exe -v --max-time 8 http://127.0.0.1:9982/` returned `HTTP/1.1 301 Moved Permanently` with `Location: https://127.0.0.1:9982/`.
  - `php bin/w server:status --all` now reports `default` running with Dispatcher `9982`, Workers `10001/10002`, Session `19970`, Memory `19971`, Control `19980`.
  - `var/server/config/default.json` now persists `"port": 9982`.
