---
name: system-config-scope
description: Implement or diagnose Weline SystemConfig scope inheritance, backend configuration UI/templates, versioned saves/rollback, w_query('system_config', ...), or website/store/global behavior. Use when configuration ownership or scope semantics matter; routine reading of one known setting does not require this skill.
---

# SystemConfig scope

## Authority and ownership

- Start with current source and `app/code/Weline/SystemConfig/doc/README.md`.
- Load `app/code/Weline/SystemConfig/doc/scope-config-tree-plan.md`, `app/code/Weline/SystemConfig/doc/scope-config-theme-layout-master-plan.md`, or Theme layout plans only when the task changes those designs; plans are not default runtime truth.
- `Weline_SystemConfig` owns scope switching, discovery/search, inheritance, save validation, versioning, rollback, ACL, sensitive-value handling, audit, and cache invalidation.
- Business modules provide registered PHTML config templates and optional adapters; they do not create parallel save endpoints or select the write scope.

## Template contract

Templates live at:

```text
app/code/{Vendor}/{Module}/extends/module/Weline_SystemConfig/Config/{area}/{code}.phtml
```

- `<w:config:field>` declares savable key/value settings.
- `<w:config:adapter>` links complex objects that remain in their owning tables.
- `<w:config:group>` groups fields; `<w:config:hint>` is display-only.
- Only registered templates and declared fields are writable. Parse mode has no side effects; render mode may perform read-only queries.

## Scope and save semantics

Identity is `module + area + key + scope + locale`. Canonical scopes are:

- global: `default.default.default`
- website: `{website_code}.default.default`
- store: `{website_code}.{store_code}.default`

Backend writes use the administrator's explicitly selected scope. Runtime reads may use `ScopeContext` when no explicit scope is supplied. Short scopes are compatibility reads, not new write targets.

Current SystemConfig saves are versioned: preserve atomic batch rollback, `base_versions` conflict detection, actor/audit metadata, sensitive-value masking, cache invalidation, and the returned `version_id`. Rollback must precheck conflicts and creates its own version record.

## Workflow

1. Inspect the current template, provider/service, source docs, and selected scope.
2. Put ordinary settings in the module's registered template; keep complex business objects in their owning model and expose an adapter.
3. Change generic scope/save behavior only in `Weline_SystemConfig`.
4. Validate template parsing plus the real selected-scope save/read/fallback path.
5. For save changes, verify version batch, conflict, rollback, audit, ACL, and cache behavior with focused source/runtime/tests.
