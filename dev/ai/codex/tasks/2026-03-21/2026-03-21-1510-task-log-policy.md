# Task: codex task log policy

- Started: 2026-03-21 15:10
- Status: completed
- Owner: Codex
- Source: 用户要求“新建一个规范，每次任务都要在 dev/ai/codex 中记录每个任务的情况和进展，避免网络出问题导致任务内容丢失”

## Goal

建立一个持久化规范，确保 Codex 在每次任务开始、进行中、结束后都将任务内容和进展写入 `dev/ai/codex/`，便于中断恢复。

## Context

- 工作区已有 `dev/ai/plans`、`dev/ai/rules`、`dev/ai/skills`，但没有专门的 Codex 任务日志目录。
- 当前会话已确认需要把“任务落盘”提升为默认工作流程，而不是临时做法。

## Progress

- 15:10 完成启动检查，读取 `SOUL.md`、`USER.md`、`memory/2026-03-21.md`，确认 `MEMORY.md` 缺失。
- 15:12 确认 `dev/ai/codex/` 目录不存在，且 `dev/ai/` 下已有 AI 规则与计划仓位。
- 15:14 设计落盘结构：`README.md`、`TASK_TEMPLATE.md`、`ACTIVE.md`、`tasks/YYYY-MM-DD/*.md`。
- 15:16 开始写入 AGENTS 规则和 Codex 任务日志规范。
- 15:20 完成目录和规范文档落盘。
- 15:22 更新 `memory/2026-03-21.md`，将这条新约定写入日记。

## Files

- AGENTS.md
- dev/ai/codex/README.md
- dev/ai/codex/TASK_TEMPLATE.md
- dev/ai/codex/ACTIVE.md
- dev/ai/codex/tasks/2026-03-21/2026-03-21-1510-task-log-policy.md

## Risks / Blockers

- 暂无代码层阻塞。
- 需要保证今后会话能优先读取 `ACTIVE.md`，所以已同步修改工作区规则入口。

## Next

- 完成文档内容。
- 更新当日 memory，记录这条新约定。

## Result

- 已创建 `dev/ai/codex/` 专用目录，包含规范说明、模板、活动任务入口和本次任务记录。
- 已修改 `AGENTS.md`，要求会话启动时读取 `dev/ai/codex/ACTIVE.md`，并在每次任务开始/进行中/结束时持续更新任务记录。
- 已将该约定写入 `memory/2026-03-21.md`，便于后续会话延续。

## Verification

- 已检查 `AGENTS.md` 中新增规则存在。
- 已检查 `dev/ai/codex/README.md`、`ACTIVE.md`、任务记录文件已创建。
- 未运行测试：本次为文档与流程改动，无代码执行逻辑变更。
