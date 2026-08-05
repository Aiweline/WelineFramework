---
name: 业务模块工程师-配置缓存与后台权限
description: Implement module-level env/SystemConfig, cache dimensions, backend menu wiring, and bounded permission integration. Use when a business module needs settings, cached data, or admin visibility; use security skills for ACL policy/session architecture and system-config-scope for inheritance/version semantics.
---

# Module configuration, cache, and backend wiring

## Boundary

- Keep settings/cache/menu wiring in the owning module and use framework factories/contracts.
- Update menu and Controller permission wiring together; use the ACL skill when policy or permission-tree behavior is the task.
- Update owning operator documentation only when setup, configuration, or usage changes.

## Cache contract

`w_cache($pool)` automatically dimensions ordinary keys by area, website code, language, and currency. Business code supplies only its logical key.

Use `getCustom` / `setCustom` / `rememberCustom` / `deleteCustom` / `hasCustom` only for global dictionaries, metadata, or bootstrap structures. Custom dimensions default to `false` (escaped); enable only the dimensions the value actually depends on.

Do not hand-compose website/language/currency into logical keys, use all-true Custom as a substitute for the ordinary API, or instantiate cache drivers directly. Details: `app/code/Weline/Framework/doc/3-开发/缓存使用指南.md`.

## Workflow

1. Inspect current env/SystemConfig, cache dependencies/invalidation, menu, and Controller permissions.
2. Implement the smallest owning-module change with explicit cache dimensions.
3. Refresh env/setup/routes only when their source changed.
4. Validate configuration read/write, cache hit/invalidation/dimension isolation, and real backend visibility/access as applicable.
5. Report any credential blocker rather than storing or guessing credentials.
