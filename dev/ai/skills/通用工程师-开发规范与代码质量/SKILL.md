---
name: 通用工程师-开发规范与代码质量
description: Govern cross-cutting Weline development quality and end-to-end module closure. Use for module features or requirement changes, reconciling user requests with doc/需求.md, tracking target-version progress in doc/开发日志.md, enforcing review/test/E2E gates, updating operator handoff docs, repository-level standards review, or multi-domain work where no narrower owner is sufficient.
---

# Cross-cutting engineering quality

## Core contract

This skill interprets the global constraints at a cross-domain boundary. It owns the development-closure sequence and durable module traceability, while frontend, ORM, WLS, security, testing, and release details remain with their domain skills.

For any module implementation, requirement reconciliation, development-progress query, or final handoff, read [module-development-closure.md](references/module-development-closure.md) completely before acting. Use its two templates for the fixed module documents; adapt verified content without deleting required fields.

## Workflow

1. State the requested outcome, changed surfaces, exclusions, target module/version, and acceptance boundary.
2. Load the module `doc/AI-INDEX.md`, fixed **doc/需求.md** and **doc/开发日志.md**, owning domain skills, targeted source/configuration, existing tests, runtime entrypoints, and user-owned worktree changes.
3. Compare the request with current confirmed requirements. For any semantic difference or missing requirement, present the requirement/logic/acceptance/version impact and obtain the user's explicit supplement/change decision before code work.
4. Write the confirmed requirement and logic to **需求.md**, then open the target-version entry in **开发日志.md** with requirement IDs, scope, gates, and blockers. Establish an honest baseline first when either document is missing.
5. Identify generated artifacts, public contracts, architecture, data/security/runtime boundaries, and documentation owners. Choose the available model and direct/delegated implementation arrangement required by the global rule, and record the owner and scope.
6. Review the complete implementation for framework architecture, defects, and security; return findings to the implementation owner or a bounded selected agent, re-review to pass, then run layered tests, real-path WebUI/E2E where applicable, and the repeatable E2E regression.
7. Update the development-log entry at each gate or adjustment with concise real evidence. Never advance status from a diff, assumption, stale runtime, or unexecuted check.
8. Update the feature's owning functional documentation and operator handoff; create and index `doc/运营/{功能名}.md` only when no current operator owner exists. Close the target version only after requirements, evidence, E2E, documentation, and approved deviations reconcile.
9. Report actual evidence, unverified gaps, and remaining risk without inventing URLs, runtime state, history, or success.

## Review checklist

- unrelated worktree changes are preserved;
- generated/compiled output is not edited as source;
- public API, module, data, permission, i18n, and long-running runtime boundaries are respected;
- every implementation maps to confirmed requirement IDs and one target-version development entry;
- user-request differences were explicitly decided and written before code work;
- browser business requests, visible copy, and templates follow their owning skills;
- test and Browser/WLS depth matches the changed risk;
- review findings returned to the recorded implementation owner and re-review passed before tests;
- requirement, development-log, functional, operator, and module-index owners match the accepted result;
- task records link evidence without replacing fixed module documents or duplicating raw logs;
- external actions remain within explicit authorization.
