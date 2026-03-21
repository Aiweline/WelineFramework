---
name: Codex Recovered Plan - Theme Runtime Unification
overview: Recover the unfinished theme runtime, partials, scope, CLI, and validation unification work from Codex session logs.
source_session: C:\Users\17142\.codex\sessions\2026\03\21\rollout-2026-03-21T09-49-14-019d0e15-0da1-7b63-a013-1c6558e7369b.jsonl
source_timestamp: 2026-03-21T04:36:44.481Z
status: completed
isProject: false
todos:
  - id: codex-theme-runtime-unification-1
    content: Inspect the current theme module state and confirm reusable services across runtime, partials, scope, and CLI paths
    status: completed
  - id: codex-theme-runtime-unification-2
    content: Fix runtime area theme resolution plus preview and scope values so key call sites consistently use ThemeContextService
    status: completed
  - id: codex-theme-runtime-unification-3
    content: Unify partials and backend config flows so legacy Partials and ThemeConfig pages use ThemeData, Meta, and ThemeResourceCatalog
    status: completed
  - id: codex-theme-runtime-unification-4
    content: Add CLI and query-layer area support and align backend activation and preview interface logic
    status: completed
  - id: codex-theme-runtime-unification-5
    content: Add or repair tests and run static or automated verification
    status: completed
---

# Codex Recovered Plan - Theme Runtime Unification

## Recovery Note

Recovered from the last `update_plan` found in the source session. This reflects the plan state in the session log and has not been revalidated against the current repository.

## Completion (2026-03-21)

已在仓库内落实：`ThemeContextService` 收敛运行时主题解析与 scope；查询层与 CLI 对齐；新增 `ThemeContextServiceTest`。变更与验证见 `dev/ai/codex/tasks/2026-03-21/2026-03-21-theme-runtime-unification.md`。修改构造注入后需执行 `php bin/w setup:upgrade`。

## Original Explanation

按完整修复包推进，先统一主题上下文与运行时解析，再收敛 partials/scope 配置链，最后补 CLI 与测试验证。
