# Task Log - WLS Windows frontend foreground launch path

- Date: 2026-03-22
- Started: 2026-03-22 23:59:00
- Status: completed
- Request: Continue the Windows frontend startup investigation after the `Start.php` parse fix and make frontend worker launches use a real foreground-console path without breaking WLS startup.

## Context

- The original parse failure in `app/code/Weline/Server/Console/Server/Start.php` had already been fixed.
- WLS runtime startup was healthy before this follow-up, but Windows frontend worker launches still used a non-interactive PowerShell `Start-Process` path that produced headless `conhost.exe` children instead of visible worker windows.
- `dev/ai/codex/ACTIVE.md` was being changed by another task, so this work stayed in a standalone task log.

## Plan

1. Re-read the live `Processer.php` foreground and batch-create branches.
2. Replace the Windows foreground launch path with a console-oriented launcher instead of non-interactive PowerShell `Start-Process`.
3. Make batch foreground launches avoid the old optimized PowerShell batch path.
4. Add focused unit coverage for the new launcher behavior.
5. Re-run lint, targeted PHPUnit, and the real startup command.

## Progress

- Re-read `Processer::create()`, `Processer::batchCreateWindows()`, `Processer::buildWindowsBatchCreateScript()`, and `ProcesserTest`.
- Reproduced that the first patch version could start child processes but failed to confirm them reliably, leaving WLS in a partial startup state.
- Identified the follow-up root cause: the new `cmd` launcher preserved quoted `--name="..."` / `--launch-id="..."` arguments, while existing PID lookup helpers were much more reliable against unquoted identity flags.
- Patched `Processer.php` again so Windows foreground launches now:
  - use a temp self-deleting `.cmd` launcher driven by `cmd.exe /d /c`
  - normalize stable identity flags like `--name=`, `--launch-id=`, and `--epoch=` to unquoted values when safe
  - wait for PID confirmation by `process name + launch-id` instead of depending only on a full raw command-line match
  - force `batchCreateWindows()` to fall back to per-process `create()` when any command requests `foreground=true`
- Added/updated unit coverage in `app/code/Weline/Framework/Test/ProcesserTest.php` for:
  - foreground batch fallback
  - `cmd/start` launcher command generation
  - self-deleting foreground `.cmd` script generation
  - argument normalization for identity flags

## Verification

- `php -l app/code/Weline/Framework/System/Process/Processer.php`
- `php -l app/code/Weline/Framework/Test/ProcesserTest.php`
- `php vendor/phpunit/phpunit/phpunit app/code/Weline/Framework/Test/ProcesserTest.php`
- `php bin/w server:stop`
- `php bin/w s:start -r -f -frontend -p 9982`
- `php bin/w server:status --all`
- `curl.exe -k -I https://127.0.0.1:9982/`
- `Get-CimInstance Win32_Process ...` checks for WLS child command lines

## Outcome

- Restored successful frontend startup after replacing the Windows foreground launch path.
- Verified that `php bin/w s:start -r -f -frontend -p 9982` now results in a healthy `default` instance again with `12/12 workers`, `dispatcher`, `session`, and `memory` all `ready`.
- Verified HTTPS health again with `HTTP/1.1 200 OK` from `https://127.0.0.1:9982/`.
- Verified that frontend child processes now launch through `cmd.exe /d /c <temp>.cmd` wrappers and carry normalized unquoted `--launch-id=` / `--name=` identity flags in their final `php.exe` command lines.

## Residual Risk

- From this tool environment, the new foreground path still reports `MainWindowHandle = 0`, so I could not prove true visible windows end-to-end here.
- What is verified locally is the launch chain change (`cmd.exe` wrapper instead of headless PowerShell) plus restored startup correctness.
- If the user still does not see worker windows in their own interactive terminal after this patch, the next step should focus on Windows desktop-session / console-host behavior rather than WLS startup correctness.
