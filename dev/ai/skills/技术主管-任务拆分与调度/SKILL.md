---
name: 技术主管-任务拆分与调度
description: Coordinate live multi-agent Weline execution by splitting independent work, assigning bounded ownership, sequencing dependencies, preventing edit overlap, and integrating returned evidence. Use when parallel specialists materially improve an active task; not for writing an implementation plan or for a single already-bounded task.
---

# Multi-agent dispatch

## Inputs

- current objective, scope, authorization, and acceptance surface;
- an existing plan or enough targeted evidence to define bounded subtasks;
- available agent slots and known overlapping files/symbols.

## Workflow

1. Keep requirement interpretation, integration, and final acceptance with the current task owner.
2. Delegate only independent work with a concrete result, allowed paths, prohibited paths, expected evidence, and stop/escalation conditions.
3. Model dependencies and file/symbol overlap before dispatch. Run conflicting edits serially or give one agent ownership.
4. Use only the slots that improve latency or confidence; never target a fixed utilization or agent count.
5. Track `in_progress`, `completed`, and real blockers. Give corrections when evidence or scope is incomplete.
6. Review returned diffs/evidence, resolve integration conflicts, and run owner-level acceptance before reporting completion.
7. Surface blockers or residual risk honestly when they require user authority or an external-state change.

## Output

- bounded assignment map and dependency order;
- ownership/conflict decisions;
- returned evidence and integration status;
- explicit blockers, corrections, and final owner acceptance.

Subagents do not broaden authorization, commit/push/deploy independently, or replace the current task owner's judgment.
