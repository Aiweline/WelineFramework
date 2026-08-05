---
name: 业务模块工程师-服务层与业务逻辑
description: Design Weline module services, extract business rules from controllers, and coordinate models or published APIs behind reusable contracts. Use for service classes, domain rules, orchestration, or thin-controller refactors; use core skills for framework infrastructure and ORM skills for persistence-only changes.
---

# Service and business logic

## Boundary

- Services own business rules and orchestration; Controllers/commands translate input/output and Models own persistence.
- Keep dependencies explicit and module-local unless a published cross-module contract is required.
- Do not move UI rendering into services or hide domain behavior in Controllers, templates, or raw SQL.

## Workflow

1. Identify the business outcome, entry point, owning module, collaborators, edge cases, and public contract.
2. Trace the current Controller/service/model path before extracting or adding a service.
3. Implement the smallest explicit service boundary and keep entry points thin.
4. Validate through the real route/API/command/Browser path plus focused tests where durable coverage is proportionate.
5. Report the service contract, caller impact, and evidence.

## Diagnostic checks

- Broken cart/list rows may be stale foreign keys or deleted backing records; confirm this before rewriting templates and preserve/rebind snapshots only through the owning service contract.
- When stored live data renders as placeholders, inspect the Controller/template/cache delivery path as part of the business flow.
- For repair/snapshot logic, prove the service/provider payload in addition to raw database state.

