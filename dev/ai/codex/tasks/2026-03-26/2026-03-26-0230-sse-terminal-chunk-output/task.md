# Task: sse terminal chunk output

- Task ID: 2026-03-26-0230-sse-terminal-chunk-output
- Started: 2026-03-26 02:30
- Status: completed
- Owner: Codex
- Source: codex chat

## Goal

- Make the AI site builder SSE console render streamed AI output as chunked terminal content instead of creating a new timestamped log line for every few characters.

## Scope

- In scope:
- Route AI streaming events in `SiteBuilderAgent` to terminal `chunk` events.
- Ensure the site builder hub SSE terminal and its fallback client both subscribe to and aggregate `chunk` payloads.
- Record verification and outcome in this task workspace.
- Out of scope:
- Reworking unrelated domain-purchase SSE flows or generic terminal styling outside the affected site builder path.

## Constraints

- Keep the change narrow because the worktree contains many unrelated in-progress modifications.
- Preserve existing non-stream status, tool, and error logs.

## Related Plans

- None yet.

## Related Files

- `app/code/Weline/Websites/Controller/Backend/SiteBuilderAgent.php`
- `app/code/Weline/Websites/view/templates/Backend/SiteBuilderAgent/index.phtml`
- `dev/ai/codex/tasks/2026-03-26/2026-03-26-0230-sse-terminal-chunk-output/plan.md`
- `dev/ai/codex/tasks/2026-03-26/2026-03-26-0230-sse-terminal-chunk-output/progress.md`
- `dev/ai/codex/tasks/2026-03-26/2026-03-26-0230-sse-terminal-chunk-output/result.md`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
