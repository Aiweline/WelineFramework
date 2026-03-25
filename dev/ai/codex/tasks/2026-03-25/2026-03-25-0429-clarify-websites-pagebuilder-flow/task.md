# Task: align websites pagebuilder handoff flow

- Task ID: 2026-03-25-0429-clarify-websites-pagebuilder-flow
- Started: 2026-03-25 04:29
- Status: completed
- Owner: Codex
- Source: codex chat 2026-03-25

## Goal

- Make the real Websites/PageBuilder AI site-building flow match the intended wording and architecture.
- Websites must own the base/default preparation flow.
- PageBuilder must take over through an extension handoff after preparation instead of being mixed into the Websites default stages.
- Backend e2e must prove the real handoff and background domain-purchase continuity.

## Scope

- In scope:
- PageBuilder provider native-entry resolution and Websites handoff routing
- Websites workspace behavior when the PageBuilder lane moves from `prepare` to `generate`
- Seeding/resuming the native PageBuilder session from the Websites workspace scope
- Focused unit and backend e2e coverage for the real handoff path plus background domain-purchase continuity
- Out of scope:
- Broad PageBuilder authoring UX refactors unrelated to the handoff boundary
- General i18n cleanup outside the touched flow

## Constraints

- Preserve the provider extension architecture: Websites remains the base flow, PageBuilder remains the extension flow.
- Do not mix PageBuilder generate/visual-edit steps back into the Websites default journey.
- Keep the domain purchase workbench path resumable while the PageBuilder handoff proceeds.

## Related Plans

- None yet.

## Related Files

- app/code/GuoLaiRen/PageBuilder/extends/module/Weline_Websites/AiSiteBuilderProvider/PageBuilderProvider.php
- app/code/Weline/Websites/Controller/Backend/SiteBuilderAgent.php
- app/code/Weline/Websites/view/templates/Backend/SiteBuilderAgent/workspace.phtml
- app/code/GuoLaiRen/PageBuilder/Test/Unit/Extends/Module/Weline_Websites/AiSiteBuilderProvider/PageBuilderProviderTest.php
- tests/e2e/specs/backend/ai-site-workbench.spec.js

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
