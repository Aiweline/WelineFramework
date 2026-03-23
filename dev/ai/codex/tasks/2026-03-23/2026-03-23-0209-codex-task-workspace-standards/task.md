# Task: codex-task-workspace-standards

- Task ID: 2026-03-23-0209-codex-task-workspace-standards
- Started: 2026-03-23 02:09
- Status: done
- Owner: Codex
- Source: 用户要求新增 Codex 任务执行标准规范与技能

## Goal

- 为 Codex 建立新的任务执行标准：每个任务拥有自己的工作目录、自己的计划、自己的状态与进度，不再共享写入 `dev/ai/codex/ACTIVE.md`。
- 把框架级工程交付规范绑定进任务执行闭环，明确 SOLID、TDD、E2E 的默认要求。
- 给仓库新增一个可复用的任务执行技能与脚手架，让后续 Codex 会话能稳定沿用同一套做法。

## Scope

- In scope:
  - 更新 `AGENTS.md` 的启动与任务记录约束
  - 重写 `dev/ai/codex` 的任务工作区说明与模板
  - 新增任务工作区脚手架
  - 新增/更新相关技能与技能映射
  - 用新规范为当前任务本身创建工作目录并记录执行过程
- Out of scope:
  - 不批量迁移旧的单文件任务记录
  - 不改动具体业务模块代码

## Constraints

- 所有新状态必须只写当前任务工作目录，不再依赖共享 `ACTIVE.md`。
- 尽量保持与现有 `dev/ai/skills` / `dev/ai/rules` 体系兼容。
- 保留历史资料，避免破坏旧任务的可追溯性。

## Related Plans

- `dev/ai/codex/README.md`

## Related Files

- `AGENTS.md`
- `dev/ai/codex/README.md`
- `dev/ai/codex/TASK_TEMPLATE.md`
- `dev/ai/codex/ACTIVE.md`
- `dev/ai/codex/scripts/init-task.php`
- `dev/ai/codex/templates/task.md`
- `dev/ai/codex/templates/plan.md`
- `dev/ai/codex/templates/progress.md`
- `dev/ai/codex/templates/result.md`
- `dev/ai/skills/codex-task-workspace/SKILL.md`
- `dev/ai/skills/planning/SKILL.md`
- `dev/ai/skills/code-generation-standards/SKILL.md`
- `dev/ai/skills/testing/SKILL.md`
- `dev/ai/skills/skill-trigger-reminders/SKILL.md`
- `dev/ai/skills/skill-trigger-reminders/references/development-skill-map.md`
- `dev/ai/rules/skill-trigger-reminders.mdc`

## Resume

- Read [plan.md](./plan.md), [progress.md](./progress.md), and [result.md](./result.md).
