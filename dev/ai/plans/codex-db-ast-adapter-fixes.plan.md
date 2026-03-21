---
name: Codex Recovered Plan - DB AST Adapter Fixes
overview: Recover the unfinished AST, compiler, and adapter remediation plan from Codex session logs.
source_session: C:\Users\17142\.codex\sessions\2026\03\21\rollout-2026-03-21T09-59-25-019d0e1e-61e1-7722-966c-e0a4849fb78b.jsonl
source_timestamp: 2026-03-21T04:16:38.790Z
status: pending
isProject: false
todos:
  - id: codex-db-ast-adapter-fixes-1
    content: Review compiler, adapter, SQLite connector, and current test coverage to define the smallest complete repair surface
    status: in_progress
  - id: codex-db-ast-adapter-fixes-2
    content: Implement main-chain fixes for subquery compilation, dialect condition handling, adapter prepareSql convergence, MySQL upsert behavior, and SQLite unsupported-feature exceptions
    status: pending
  - id: codex-db-ast-adapter-fixes-3
    content: Add or adjust compiler-level regression tests and run relevant verification
    status: pending
  - id: codex-db-ast-adapter-fixes-4
    content: Review the resulting diff and create a git commit
    status: pending
---

# Codex Recovered Plan - DB AST Adapter Fixes

## Recovery Note

Recovered from the last `update_plan` found in the source session. This reflects the plan state in the session log and has not been revalidated against the current repository.

## Original Explanation

延续上一轮未完成的数据库适配器整改，目标是先修回归，再把 AST->compiler->adapter 主链补完整，最后验证并提交。
