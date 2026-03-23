# Task: ai site workbench e2e and provider lane fix

- Task ID: 2026-03-23-0633-ai-site-workbench-e2e-and-provider-lane-fix
- Started: 2026-03-23 06:33
- Status: completed
- Owner: Codex
- Source: fix provider-lane anchor and add AI workbench e2e coverage with fake domain purchase

## Goal

- Fix the Websites AI workbench provider-lane link so it stays on the current hub page.
- Add stable local E2E coverage for the unified AI site workbench, including fake quick-build flows that cannot call real registrar or build infrastructure locally.
- Make the standard `tests/e2e/start.js` wrapper path stable and human-friendly enough to pass the AI workbench flow without extra environment overrides.

## Scope

- In scope:
- `Weline_Websites` AI site workbench hub / workspace behavior
- fake-mode SSE quick-build flow for local demo and E2E
- Playwright coverage for provider-lane, workspace progression, AI quick build, and manual quick build
- Out of scope:
- real domain purchase, DNS, HTTPS issuance, or remote build side effects

## Constraints

- worktree is dirty; keep edits tightly scoped
- use local fake data for non-localizable build actions
- keep task state in this workspace instead of `dev/ai/codex/ACTIVE.md`

## Related Plans

- None yet.

## Related Files

- `app/code/Weline/Websites/Controller/Backend/SiteBuilderAgent.php`
- `app/code/Weline/Websites/view/templates/Backend/SiteBuilderAgent/index.phtml`
- `tests/e2e/start.js`
- `tests/e2e/framework/runtime.js`
- `tests/e2e/specs/backend/helpers/ai-workbench.js`
- `tests/e2e/specs/backend/ai-site-workbench.spec.js`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
