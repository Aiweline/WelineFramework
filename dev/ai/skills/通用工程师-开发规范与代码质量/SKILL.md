---
name: 通用工程师-开发规范与代码质量
description: Resolve cross-cutting Weline questions about task boundaries, generated artifacts, code quality, documentation ownership, or validation evidence. Use for repository-level standards review or multi-domain work where no narrower owner is sufficient; skip it when a domain skill fully covers the task.
---

# Cross-cutting engineering quality

## Scope

This skill interprets the global constraints at a cross-domain boundary. It does not duplicate frontend, ORM, WLS, security, testing, or release workflows.

## Workflow

1. State the requested outcome, changed surfaces, exclusions, and acceptance boundary.
2. Load the owning domain skills/module docs and inspect targeted source, configuration, existing tests, runtime entrypoints, and user-owned worktree changes.
3. Identify generated artifacts, public contracts, data/security/runtime boundaries, and documentation owners that the change must preserve.
4. Keep the implementation minimal and reversible. Use structure-aware batch tooling only when the transformation is uniform and every result is diff-reviewed.
5. Validate at the changed surface. Add or update focused tests when proportionate; do not create broad unrelated fixtures or E2E suites.
6. Report actual evidence, unverified gaps, and remaining risk without inventing URLs, runtime state, or success.

## Review checklist

- unrelated worktree changes are preserved;
- generated/compiled output is not edited as source;
- public API, module, data, permission, i18n, and long-running runtime boundaries are respected;
- browser business requests, visible copy, and templates follow their owning skills;
- test and Browser/WLS depth matches the changed risk;
- task records and documentation are updated only when the global rules make them relevant;
- external actions remain within explicit authorization.
