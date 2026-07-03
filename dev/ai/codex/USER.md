# Codex User Profile for This Workspace

This file captures durable user preferences for future Codex agents in
`E:\WelineFramework\DEV-workspace`.

## Communication

- The user often writes Chinese and may paste mojibake console output.
- Preserve exact error strings, paths, commands, and timestamps.
- Explain using concrete call chains, files, runtime state, and visible output.
- Avoid generic advice when a local command or source inspection can answer the
  question.
- Keep progress updates short and factual.
- Final reports should include changed files, verification evidence, and known
  risks.

## Execution Style

- Work autonomously on clear, reversible, non-destructive tasks.
- Run safe local reproduction and diagnostic commands yourself.
- Do not ask the user to run ordinary commands that you can run in the
  workspace.
- Iterate after each failure until the next concrete blocker is identified.
- Treat newer logs or stack traces from the user as the current source of truth.
- Preserve unrelated dirty worktree changes.

## Local Development Environment

- For local backend/admin verification in this workspace, the development
  account is `admin/admin`.
- When development requires backend interaction, you may use `admin/admin`
  instead of blocking on credential requests.
- In the development environment, database reads and writes are allowed when
  they are needed for diagnosis, verification, or controlled setup changes.
- Prefer framework ORM, model/service flows, and system update commands such as
  `php bin/w setup:upgrade` over ad hoc direct SQL.
- If direct SQL is truly necessary, keep it targeted and reversible, and report
  the affected tables or rows in the final summary.

## WLS Preferences

For Weline Server / WLS work:

- Start from runtime metadata, process lifecycle, IPC, ports, and logs.
- Use exact WLS command output and stack traces as evidence.
- `php bin/w s:start -r -f` is the default reproduction command when the user
  asks to diagnose WLS start behavior.
- Do not run `php bin/w s:start -r -f -frontend` unless the user explicitly
  asks for it. In this workspace, `-frontend` can cause the process to hang.
- Imperial cleanup commands are exclusive control-plane operations: let cleanup
  finish before layering other runtime actions.
- Child/shared-service processes should self-stop if the master dies.
- If historical `-frontend` behavior is under discussion, shared IPC services
  should be visible and helper-process logs should be mirrored to the console,
  but do not use `-frontend` for routine reproduction.
- If `curl -k` succeeds but browser/plain curl fails, separate app reachability
  from Windows Schannel/certificate trust.




`E:\公司\远程\src\weline`. Do not implement, restore, or deploy that

repository and follow its deployment skill.

## Safety Boundaries

- Avoid notification-capable stock monitor flows unless explicitly requested.
- For encoding/mojibake fixes, inspect bytes and current working-tree logic
  before patching.
- Do not broadly revert files. Rebuild from clean `HEAD` only when necessary and
  reapply intentional changes explicitly.
- For WLS tests, prefer isolated test instances and targeted PHPUnit. Stop any
  test WLS instance after use.

## Repo Workflow Preferences

- For substantial implementation tasks, create/update task workspaces under
  `dev/ai/codex/tasks/YYYY-MM-DD/...`.
- After live verification, update durable memory under `memory/YYYY-MM-DD.md`
  when the result should survive future sessions.
- Keep reports out of the repository root unless the user explicitly asks for
  root-level artifacts.
