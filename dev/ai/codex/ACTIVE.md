# ACTIVE.md (Deprecated)

Do not write new mutable task state here.
Use dedicated task workspaces under `dev/ai/codex/tasks/YYYY-MM-DD/YYYY-MM-DD-HHMM-short-slug/` instead.
The legacy snapshot below is preserved only for backward context.

---

# Active Task

- Updated: 2026-03-23 09:53
- Task File: dev/ai/codex/tasks/2026-03-23/2026-03-23-0953-websites-ai-workbench-pagebuilder-bridge.md
- Status: in_progress

## Current Goal

Close the gap between the planned `Weline_Websites` AI site-building workbench and the currently live entrypoints:

- confirm what is already finished under `dev/ai/codex/AI工作台/`
- make the Websites AI workbench entry more human-friendly
- let PageBuilder's AI site-agent workspace act as an extension of the Websites workbench
- clean up the duplicated `Weline_Websites::site_builder_agent_pagebuilder` menu entry if the unified workbench now covers that path

## Latest Progress

- Completed workspace startup context per `AGENTS.md`.
- Routed repo skill usage through `weline-framework-skill-router`, then loaded `extension-points` and `theme-development`.
- Confirmed the planning docs under `dev/ai/codex/AI工作台/` are not fully implemented yet:
  - Epic 1 and Epic 2 are completed
  - Epic 3+ (controller/UI/provider integration/PageBuilder provider) are still pending
- Compared current live code paths:
  - `Weline_Websites` still uses the older one-shot `SiteBuilderAgent` form + SSE flow
  - `GuoLaiRen_PageBuilder` already has the richer session-based `AiSiteAgent` workspace
- Confirmed `Weline_Websites::site_builder_agent_pagebuilder` exists only as a duplicated menu node in `app/code/Weline/Websites/etc/backend/menu.xml`

## Verification

- Pending.

## Risks / Notes

- The worktree is dirty in many unrelated files; edits must remain tightly scoped.
- `app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace.phtml` and `app/code/GuoLaiRen/PageBuilder/Service/AiSiteAgentSessionService.php` already contain local modifications and must be patched carefully.
- Avoid forcing historical PageBuilder session migration in this pass.

## Next

- Upgrade the Websites AI workbench entry into a friendlier hub that exposes PageBuilder as an extension path.
- Register a PageBuilder provider under the Websites extension point.
- Update PageBuilder menu/workbench links to point back to the Websites hub.
- Remove the duplicated Websites PageBuilder-group menu entry.
- Run focused lint and setup verification.
