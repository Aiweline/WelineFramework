# Progress - commit uncommitted and ignore test artifacts

- 2026-03-23 13:43 Created the task workspace.
- 2026-03-23 13:44 Completed workspace startup checks: `SOUL.md` / `USER.md` / `MEMORY.md` are deleted in the worktree, so read `SOUL.md` and `USER.md` from `HEAD`; read `memory/2026-03-23.md`, `memory/2026-03-22.md`, and `dev/ai/codex/README.md`.
- 2026-03-23 13:45 Reviewed `git status --short --branch`; worktree contains a large mixed set of project changes plus generated test artifacts.
- 2026-03-23 13:47 Identified test-process files currently touching Git state: tracked `tests/e2e/collected-tests.json`, `tests/e2e/playwright-report/index.html`, `tests/e2e/test-results/.last-run.json`, tracked historical `test-results.xml`, untracked root `test-results/`, untracked E2E debug PNGs, and untracked Codex task `artifacts/` directories.
- 2026-03-23 13:49 Decided to update root `.gitignore` so test-process outputs and task `artifacts/` contents stay local while preserving `artifacts/.gitkeep`.
- 2026-03-23 13:52 Found that `dev/.gitignore` re-includes `dev/ai/**`, so added a more specific local-only rule there for `ai/codex/tasks/**/artifacts/*`.
- 2026-03-23 13:54 Confirmed the remaining untracked task-artifact noise was new `artifacts/.gitkeep` placeholders, then chose to ignore newly created placeholders as local task-process files too.
- 2026-03-23 13:56 Ran `git rm --cached` for the three tracked E2E artifacts so Git now records them as deletions while preserving local files.
- 2026-03-23 13:58 Ran `git add -A`; the current staged scope includes the pre-existing project worktree changes plus the ignore cleanup in `.gitignore`, `dev/.gitignore`, and the three test-artifact deletions.
