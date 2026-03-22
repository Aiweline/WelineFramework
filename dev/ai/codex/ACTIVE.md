# Active Task

- Updated: 2026-03-22 01:22
- Task File: `dev/ai/codex/tasks/2026-03-21/2026-03-21-2310-wls-worker-register-timeout-resurrection.md`
- Status: completed

## Current Goal

Fix the WLS instability where workers are falsely marked as `register timeout` and then automatically resurrected, while also confirming the site is reachable.

## Latest Progress

- Fixed the stale startup timestamp baseline in `app/code/Weline/Server/Service/ServiceOrchestrator.php`.
- Fixed IPC control-channel robustness in:
  - `app/code/Weline/Server/IPC/MasterControlServer.php`
  - `app/code/Weline/Server/IPC/ControlClient.php`
  - `app/code/Weline/Server/IPC/ChildControl/SubprocessControlKernel.php`
- The IPC patch now:
  - drains all pending accepts in one poll cycle
  - immediately reads newly accepted sockets
  - disables socket write buffering
  - uses bounded full-write loops for control frames
  - treats failed `register` or `ready` sends as startup failure instead of silent success
- Runtime verification is green:
  - `var/server/instances/default.json` shows dispatcher, session server, memory server, and all 12 workers in `ready`
  - `curl.exe -k -I https://127.0.0.1:9982/` returns `HTTP/1.1 200 OK`
  - repeated requests route successfully across healthy workers
  - browser validation with HTTPS errors ignored loads the home page successfully
  - no new post-restart `register 超时`, `Unregistered(pid:0)`, or `长期无 IPC` entries appear in `var/log/wls/wls.log`

## Verification

- `php -l app/code/Weline/Server/Service/ServiceOrchestrator.php`
  - result: passed
- `php -l app/code/Weline/Server/IPC/MasterControlServer.php`
  - result: passed
- `php -l app/code/Weline/Server/IPC/ControlClient.php`
  - result: passed
- `php -l app/code/Weline/Server/IPC/ChildControl/SubprocessControlKernel.php`
  - result: passed
- `curl.exe -k -I https://127.0.0.1:9982/`
  - result: `HTTP/1.1 200 OK`
- repeated dispatcher requests
  - result: healthy route hints observed across workers `10007` through `10012`
- `var/log/wls/wls.log` search for prior failure signatures
  - result: only pre-fix matches remain; no new matches after the current restart window

## Risks / Notes

- `Processer::batchCreate()` is still not truly parallel on Windows, so cold startup remains slower than ideal.
- A separate runtime warning still appears during requests for `ServerQueryProvider` constructor injection mismatch; it does not block startup stability or page access.
- A default browser automation context still reports `ERR_CERT_AUTHORITY_INVALID` for `https://127.0.0.1:9982/`; the page loads when certificate errors are ignored, so this is a local cert-trust issue rather than a startup issue.
- Current unrelated dirty file remains `app/code/Weline/Theme/Controller/Backend/ThemeEditor.php`; do not touch it.

## Next

- If needed later, clean up the unrelated `ServerQueryProvider` DI mismatch warning seen during requests.
