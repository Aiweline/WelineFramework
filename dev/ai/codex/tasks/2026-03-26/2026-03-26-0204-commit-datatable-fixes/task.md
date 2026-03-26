# Task: commit datatable fixes

- Task ID: `2026-03-26-0204-commit-datatable-fixes`
- Started: `2026-03-26 02:04`
- Status: `completed`
- Owner: `Codex`
- Source: `codex:user:提交变更，修复缺陷`

## Goal

- Review the uncommitted `Weline_DataTable` frontend-demo slice.
- Fix any remaining quality defects that should not ship together with the DataTable closure.
- Re-run focused verification and create a commit that only contains the DataTable task files.

## Scope

- In scope:
- `app/code/Weline/DataTable/*`
- `app/code/Weline/Framework/Http/PublicApiAuthRouteMatcher.php`
- `app/code/Weline/Framework/Test/Unit/Http/PublicApiAuthRouteMatcherTest.php`
- `tests/e2e/specs/frontend/weline-datatable.spec.js`
- DataTable task workspace docs for `2026-03-25` and this commit task workspace

- Out of scope:
- Unrelated dirty worktree changes outside the DataTable slice
- Existing workspace/framework defect in `php bin/w setup:upgrade --route`

## Constraints

- Do not mix the many unrelated workspace changes into this commit.
- Use the already verified pinned Playwright origin for browser verification.
- Keep the fix minimal and low risk.

## Related Plans

- Continue the completed `2026-03-25-0208-weline-datatable-e2e` closure and package it safely.

## Related Files

- `app/code/Weline/DataTable/Service/DemoTableService.php`
- `app/code/Weline/DataTable/view/statics/js/datatable-form-manager.js`
- `app/code/Weline/DataTable/view/statics/js/datatable-manager.js`
- `app/code/Weline/DataTable/view/frontend/templates/test/form.phtml`
- `tests/e2e/specs/frontend/weline-datatable.spec.js`

## Resume

- Read `plan.md`, `progress.md`, and `result.md`.
