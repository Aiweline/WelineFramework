# Task: websites ai domain recommend tag accounts

- Task ID: 2026-03-26-0418-websites-ai-domain-recommend-tag-accounts
- Started: 2026-03-26 04:18
- Status: completed
- Owner: Codex
- Source: AI建站工作台 quick-start 增加 AI 推荐可用域名按钮，推荐前要求选择 Websites 供应商账户，账户选择统一用标签式选择

## Goal

- Add an `AI 推荐` action to the AI site builder quick-start domain area.
- Require a Websites registrar account before checking live availability.
- Reuse the existing tag-style registrar selector instead of a plain `<select>`.

## Scope

- In scope:
  - `Weline_Websites` AI workbench quick-start/backend recommendation flow
  - Registrar tag-selector bootstrapping inside the AI workbench hub/workspace
  - Focused unit/e2e coverage for the new recommendation flow
- Out of scope:
  - Broader registrar account UX outside the touched Websites AI workbench/domain templates
  - Changing unrelated dirty workspace files

## Constraints

- Workspace is heavily dirty; commit only task-related files.
- Keep taglib attribute usage compliant with repo rules.
- Preserve the existing non-blocking site-builder/domain-purchase flow.

## Related Plans

- None yet.

## Related Files

- `app/code/Weline/Websites/Controller/Backend/SiteBuilderAgent.php`
- `app/code/Weline/Websites/Service/WebsiteAgentService.php`
- `app/code/Weline/Websites/view/templates/Backend/SiteBuilderAgent/index.phtml`
- `app/code/Weline/Websites/view/templates/Backend/SiteBuilderAgent/workspace.phtml`
- `app/code/Weline/Websites/Taglib/RegistrarSelect.php`
- `app/code/Weline/Websites/Test/Unit/Service/WebsiteAgentServiceTest.php`
- `tests/e2e/specs/backend/ai-site-workbench.spec.js`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
