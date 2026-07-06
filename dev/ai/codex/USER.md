# Codex User Profile For This Workspace

This file captures stable workspace preferences. It is a repo-local context map,
not a higher-priority instruction layer. If this file conflicts with
`AGENTS.md`, `AI-ENTRY.md`, `dev/ai/global-constraints.md`, or Codex
system/developer instructions, follow the higher-priority rule and update this
file.

## Communication

- The user often writes Chinese.
- Preserve exact error strings, paths, commands, and timestamps.
- Explain using concrete call chains, files, runtime state, and visible output.
- Avoid generic advice when a local command or source inspection can answer.
- Keep progress updates short and factual.
- Final reports should include changed files, verification evidence, accessible
  routes or paths, and known risks.

## Execution Style

- Work autonomously on clear, reversible, non-destructive tasks.
- Run safe local reproduction and diagnostic commands when available.
- Do not ask the user to run ordinary commands that Codex can run in the
  workspace.
- Iterate after failures until the next concrete blocker is identified.
- Treat newer logs or stack traces from the user as the current source of truth.
- Preserve unrelated dirty worktree changes.

## Local Development Environment

- Current macOS workspace root: `/Users/weline/Project/Official/框架`.
- Historical Windows core root: `E:\WelineFramework\DEV-workspace`.
- For local backend/admin verification, development credentials are
  `admin/admin`.
- When development requires backend interaction, use `admin/admin` instead of
  blocking on credential requests.
- Database reads and writes are allowed when needed for diagnosis,
  verification, or controlled setup changes.
- Prefer framework ORM, model/service flows, and system update commands such as
  `php bin/w setup:upgrade` over ad hoc direct SQL.
- If direct SQL is truly necessary, keep it targeted and reversible, and report
  affected tables or rows.

## WLS Preferences

- Start from runtime metadata, process lifecycle, IPC, ports, and logs.
- Use exact WLS command output and stack traces as evidence.
- Do not run `php bin/w s:start -r -f -frontend` unless the user explicitly
  asks; this mode can hang in the local workspace.
- Cleanup commands are exclusive control-plane operations; let cleanup finish
  before layering other runtime actions.
- Child/shared-service processes should self-stop if the master dies.
- If `curl -k` succeeds but browser/plain curl fails, separate app reachability
  from certificate trust or browser automation issues.

## Testing Preferences

- Use `dev/ai/skills/testing/SKILL.md` when the user asks how to write tests.
- Do not create or update test artifacts unless the user explicitly asks for
  test work.
- For WLS tests, prefer isolated test instances and stop them after use.
- For browser-visible features, use Codex Browser smoke when local runtime is
  available; report browser/runtime blockers honestly.

## Repo Workflow Preferences

- For substantial implementation tasks, create or update task workspaces under
  `dev/ai/codex/tasks/YYYY-MM-DD/...`.
- Keep reports out of the repository root unless the user explicitly asks for
  root-level artifacts.
- Only write durable memory or context-map updates when the result should
  survive future sessions.

## Safety Boundaries

- Avoid notification-capable stock monitor flows unless explicitly requested.
- For encoding/mojibake fixes, inspect bytes and current working-tree logic
  before patching.
- Do not broadly revert files.
- Do not implement, restore, or deploy `app/code/GuoLaiRen` in this source
  repository; switch to the release target repository for that vendor.
