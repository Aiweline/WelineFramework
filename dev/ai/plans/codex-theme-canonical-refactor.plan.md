---
name: Codex Recovered Plan - Theme Canonical Refactor
overview: Recover the unfinished Weline_Theme canonical resource and meta sync refactor from Codex session logs.
source_session: C:\Users\17142\.codex\sessions\2026\03\20\rollout-2026-03-20T18-33-20-019d0ace-8611-7593-b5d8-26d764548971.jsonl
source_timestamp: 2026-03-21T05:50:22.796Z
status: pending
isProject: true
todos:
  - id: codex-theme-canonical-refactor-1
    content: Build the new canonical theme resource resolution and meta sync services, and tighten direct-area inheritance rules
    status: in_progress
  - id: codex-theme-canonical-refactor-2
    content: Migrate old controllers, scanners, and runtime helpers to the new resolution chain and improve cache invalidation and performance behavior
    status: pending
  - id: codex-theme-canonical-refactor-3
    content: Update tests and documentation to match the new frontend and backend theme model, then run targeted verification
    status: pending
---

# Codex Recovered Plan - Theme Canonical Refactor

## Recovery Note

Recovered from the last `update_plan` found in the source session. This reflects the plan state in the session log and has not been revalidated against the current repository.

## Original Explanation

Implementing the Weline_Theme refactor in three layers so we can keep runtime, admin, and docs/tests aligned.
