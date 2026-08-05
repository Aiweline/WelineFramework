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

## Workflow

1. Identify the model/table, current callers, database semantics, and whether the task changes schema, query behavior, or persistence contract.
2. Update the owning annotations, indexes, model, or query flow.
3. Ensure fetching and mutation chains execute through the required ORM terminal operation.
4. Run `php bin/w setup:upgrade` when schema declarations change.
5. Validate the changed query/persistence path against PostgreSQL semantics when database behavior matters.
6. Report downstream contract or migration impact.

## ORM-specific checks

- Fetching chains end with `fetch()` or `fetchArray()` when execution is required.
- List pagination comes from the model/provider pagination API; never reconstruct it in templates from `REQUEST_URI` or rewrite internals.
- Do not leak dialect-specific SQL into business consumers when ORM covers the behavior.
- Confirm schema synchronization, query execution, and focused regression evidence appropriate to the risk.

