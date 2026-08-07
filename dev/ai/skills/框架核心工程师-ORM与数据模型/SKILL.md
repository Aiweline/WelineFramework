---
name: 框架核心工程师-ORM与数据模型
description: >-
  Implement or review Weline ORM models, schema annotations, same-module query execution, pagination,
  and persistence contracts. Use for #[Col]/#[Table], model fetch/write behavior, schema, or data-model
  changes; use unified-query-provider for new cross-module query contracts and service skills for orchestration.
---

# ORM and data models

## Boundary

- Schema shape belongs to `#[Col]`, `#[Table]`, and related model annotations.
- `Setup/Upgrade.php` may migrate data but is not a parallel field/schema definition system.
- Use same-module ORM normally. Existing published read contracts remain valid; new discoverable cross-module reads belong to `unified-query-provider`.
- **Hard**：任何 Model 字段/索引/表结构声明变更，必须同步上调本模块 `etc/module.php` 的 `"version"`（semver 至少 patch+1），再跑 `setup:upgrade`。详见 `dev/ai/global-constraints.md` §4。

## Workflow

1. Identify the model/table, current callers, database semantics, and whether the task changes schema, query behavior, or persistence contract.
2. Update the owning annotations, indexes, model, or query flow.
3. If step 2 changed schema declarations: bump `etc/module.php` `"version"` in the same module (required; do not skip).
4. Ensure fetching and mutation chains execute through the required ORM terminal operation.
5. Run `php bin/w setup:upgrade` (optionally `-m Module_Name`) when schema declarations change; the version bump is what makes upgrade detect the module as needing sync.
6. Validate the changed query/persistence path against PostgreSQL semantics when database behavior matters.
7. Report downstream contract or migration impact, including old → new module version.

## ORM-specific checks

- Fetching chains end with `fetch()` or `fetchArray()` when execution is required.
- List pagination comes from the model/provider pagination API; never reconstruct it in templates from `REQUEST_URI` or rewrite internals.
- Do not leak dialect-specific SQL into business consumers when ORM covers the behavior.
- Confirm schema synchronization, query execution, and focused regression evidence appropriate to the risk.
- Schema edits without a `etc/module.php` version bump are incomplete and must not be delivered.

