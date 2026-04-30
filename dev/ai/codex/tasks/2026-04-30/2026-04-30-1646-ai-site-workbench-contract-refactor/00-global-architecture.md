# Global Architecture

## Current System Snapshot

The AI site workbench currently follows this flow:

1. User edits site requirements in the backend workbench.
2. `AiSiteAgent` starts a plan queue operation.
3. `AiSitePlanQueue` executes Stage1 and writes an execution blueprint draft.
4. User confirms the plan.
5. `AiSiteTaskPlanQueue` executes Stage2 and writes a task plan draft.
6. User confirms the task plan.
7. Build queue consumes the confirmed task plan and produces render data/theme output.

The existing architecture already has a queue/SSE split. Keep it. The browser must not run AI work. SSE must only show status and logs.

## Target System

The refactor turns loose AI artifacts into typed contracts:

- Site Brief
- Design Manifest
- Page Contract
- Block Plan
- Block Visual Contract
- Block Task Contract
- Build Render Data
- QA Report
- Repair Patch

Each contract has metadata, permissions, source links, frozen fields, mutable fields, and QA gate state. Human confirmation freezes stage outputs so downstream stages can reference them but cannot silently rewrite them.

## Skill Management Target

The current file-backed skill registry remains valid for builtin skills. The workbench gains custom skills stored in DB, a skill manager UI, and a requirement-area multi-select. Selected skills are copied into generation scope and frozen as skill snapshots in contract context.

## Compatibility Rule

No existing session should break. If a session has old `execution_blueprint` or `virtual_theme_plan` data but no new contract structure, compatibility adapters must build temporary contract views for downstream code.
