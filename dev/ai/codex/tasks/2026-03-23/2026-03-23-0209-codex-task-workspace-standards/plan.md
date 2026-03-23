# Plan - codex-task-workspace-standards

## Outcome

- 用“单任务目录”替换“共享 ACTIVE.md”，并把任务规范、技能映射、工程交付门禁、脚手架一起落到仓库里。

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Implement the task-workspace standard in `AGENTS.md` and `dev/ai/codex`
- [x] Add or update skills for planning, code generation, testing, and Codex task execution
- [x] Run validation commands and inspect the generated workspace flow
- [x] Update `result.md` and `memory/2026-03-23.md`

## Verification Targets

- [x] `php -l dev/ai/codex/scripts/init-task.php`
- [x] Execute `php dev/ai/codex/scripts/init-task.php ...` to create a real task workspace
- [x] Review touched docs and task records for consistency
