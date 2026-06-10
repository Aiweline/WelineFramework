---
name: system-config-scope
description: WelineFramework SystemConfig scope configuration guide. Use when developing, reading, or changing module configuration, scope-aware settings, SystemConfig backend UI, PHTML config templates, w_query('system_config', ...), theme layout configuration entries, or website/store/global config behavior.
---

# SystemConfig Scope

Use this skill before developing or reading Weline module configuration. It captures the global rule: modules provide config templates; `Weline_SystemConfig` owns the configuration center.

## Required Reading

Read these docs before editing code or templates:

- `app/code/Weline/SystemConfig/doc/scope-config-tree-plan.md`
- `app/code/Weline/SystemConfig/doc/scope-config-theme-layout-master-plan.md`
- `app/code/Weline/SystemConfig/doc/README.md`

For Theme virtual layout or product/category layout config, also read:

- `app/code/Weline/Theme/doc/virtual-layout-scope-plan.md`

## Ownership Rules

- `Weline_SystemConfig` owns global scope switching, website/store selectors, module search, config search, inheritance toggles, save handling, validation, cache invalidation, source explanation, and `w_query('system_config', ...)`.
- Business modules only provide PHTML config templates and optional adapter entries.
- Module templates must not implement config save endpoints, write `system_config` directly, or decide the write scope.
- Backend saves must use the admin user's explicitly selected scope, not the backend request's runtime scope.
- Runtime reads may use the request `ScopeContext` when the caller does not pass an explicit scope.
- Backend saves must create a version batch and return a version id when the current source supports the new plan.
- Save failures must roll back the whole batch; successful batches must be auditable and rollback-capable.
- ACL, sensitive-value masking, audit logging, and optimistic version conflict checks belong in SystemConfig.

## Template Contract

Module config templates are registered through Extends:

```text
app/code/{Vendor}/{Module}/extends/module/Weline_SystemConfig/Config/{area}/{code}.phtml
```

`Weline_SystemConfig` reads `ExtendsData::getExtendedBy('Weline_SystemConfig')` and accepts only registered PHTML templates under `extends/module/Weline_SystemConfig/Config/`. Do not add runtime directory scans as a fallback.

Templates may use PHTML logic, including `w_query()`, to build options or summaries. Persisted config is limited to SystemConfig tags:

- `<w:config:group>` declares a backend group.
- `<w:config:field>` declares a savable key and its type, scope levels, validation, default, and labels.
- `<w:config:adapter>` exposes a complex business object summary or management entry; it is not saved as a normal field.
- `<w:config:hint>` shows instructions or warnings and is never saved.

Only keys declared by `<w:config:field>` can be saved. Plain `<input>` elements are ignored unless generated or bound by SystemConfig field tags.

Keep template parsing and rendering separate:

- parse mode extracts metadata and config tags without executing side effects;
- render mode may execute display-only logic and read-only `w_query()` operations;
- templates must not directly write DB rows, files, queues, or business state.

## Scope Model

Config identity is:

```text
module + area + key + scope + locale
```

The normalized scope has up to three segments:

```text
website.store.extra
```

New backend writes should use normalized three-segment scopes:

- Global: `default.default.default`
- Website: `{website_code}.default.default`
- Store: `{website_code}.{store_code}.default`

Short scopes such as `default` or `default.demo` are compatibility reads only unless current source says otherwise.

## Development Workflow

1. Read the required docs and inspect current SystemConfig or module code before editing.
2. For ordinary module settings, add or update a PHTML template under the Extends path.
3. Use `<w:config:field>` for key/value settings and `<w:config:adapter>` for complex objects that remain in their own business tables.
4. Keep labels, descriptions, `@meta.*`, and options suitable for i18n and AI translation.
5. Do not add module-specific scope selectors, module search, config search, save handlers, or inheritance logic.
6. If the generic config center needs new behavior, implement it in `Weline_SystemConfig`, not in the consuming module.
7. For save behavior, preserve version batches, rollback semantics, ACL, audit logs, and base-version conflict checks.
8. If code changes touch functions/classes/methods, follow repo AGENTS/GitNexus impact-analysis rules before editing.

## Reading Config

Prefer SystemConfig APIs or `w_query('system_config', ...)` where available. When diagnosing reads, determine:

- requested `module`, `area`, `key`, `locale`, and explicit `scope` if any;
- current `ScopeContext` when no explicit scope is passed;
- fallback chain and source value;
- whether an env-locked or sensitive value is involved.

## Theme And WeShop Layouts

- Theme owns virtual layout assets, versions, source editing, visual editing, AI drafts, preview, and publish state.
- Theme ordinary settings enter SystemConfig through PHTML config templates.
- Product/category modules provide identity only, such as product, category, category product default, target id, and backend entry points.
- Product/category modules must not build a parallel config system for layout choices.
- Virtual layout source, visual edits, and AI output must save draft versions.
- Publishing a virtual layout should only move `published_version_id`, leaving history intact.
- Preview must be scoped to draft version, target, scope, and backend authorization.
- Rollback should point back to an earlier published version and record audit metadata.
- Timed layout plans must not write activity layouts back into product/category layout config.

## Validation

For docs/template-only work, at minimum run targeted `rg` checks over changed docs/templates.

For PHTML template changes:

```powershell
php -l app/code/{Vendor}/{Module}/extends/module/Weline_SystemConfig/Config/{area}/{code}.phtml
```

For SystemConfig backend behavior, validate the real backend route or nearest route-level/browser check, and confirm saves use the selected scope.
