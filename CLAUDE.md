# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

WelineFramework is a proprietary PHP 8.4 web framework with a modular architecture. Source modules live under `app/code/Weline/` and are autoloaded via PSR-4. The `vendor/` directory contains Composer dependencies; `generated/` is auto-generated — **never edit it directly**.

## Common Commands

```bash
# Schema sync (after adding/changing #[Col]/#[Index] attributes)
php bin/w setup:upgrade

# Route registration (after adding new Controllers)
php bin/w setup:upgrade --route

# HTTP route verification (replaces writing test scripts)
php bin/w http:request /                        # frontend
php bin/w http:request admin -b                 # backend (auto-login)
php bin/w http:request rest/v1/module/action -api
php bin/w http:request admin -b --filter=Warning
php bin/w http:request admin -b --filter=Fatal

# Run module unit tests
php bin/w phpunit:run --module=Vendor_ModuleName
php bin/w phpunit:run -b Vendor_ModuleName      # backend context

# WLS server lifecycle (distinguish reload vs restart)
php bin/w server:start
php bin/w server:reload      # after code changes (most common)
php bin/w server:restart -r  # only when Master/Dispatcher/startup params change
php bin/w server:stop
```

## Architecture

### Module Structure

Each module under `app/code/Weline/<ModuleName>/` follows this layout:

```
register.php          — module registration (required, use Register::register())
etc/env.php           — router/backend_router and other config
etc/event.xml         — event observer wiring
menu.xml              — backend menu entries
hook.php              — view hook definitions
extends.php           — extension point definitions
Model/                — ORM models with #[Table]/#[Col]/#[Index] attributes
Controller/           — HTTP controllers (Backend/ for admin area)
Console/              — CLI commands
view/templates/       — .phtml templates
view/hooks/           — hook implementation templates
i18n/                 — zh_Hans_CN.csv, en_US.csv
Test/Unit/            — PHPUnit unit tests
```

### Key Architectural Concepts

**Routing**: No `routes.xml`. Controllers are auto-discovered from directory structure. URL pattern: `/<backendKey>/<currency>/<language>/<module>/<area>/<controller>/<action>`. After adding controllers, run `setup:upgrade --route`. Use `$this->getUrl()` / `$this->getBackendUrl()`.

**ORM**: All `select()`/`find()`/`update()`/`delete()`/`insert()` chains **must** end with `->fetch()` or `->fetchArray()` to execute. `save()` is the exception. Pagination: `->pagination($page, $size)->select()->fetch()`. No `fetchOne()`, no `whereIn()` — use `->where('id', [1,2], 'IN')`.

**Schema changes**: Always use `#[Table]`/`#[Col]`/`#[Index]` PHP attributes on Model classes, then run `php bin/w setup:upgrade`. Never do field CRUD inside `Setup/Upgrade.php`.

**Events**: Dispatch with a variable payload, never an inline literal array. File: `etc/event.xml`. Naming: `ModuleName::event-name`.

**Hooks**: Naming: `{Module}::{area}::{type}::{component}::{position}`. `area` = `frontend`|`backend`; `type` = `partials`|`layouts`; component/position = lowercase + hyphens only (no underscores).

**Extension points (Extends)**: Define in `extends.php` at module root; implementations go under `extends/module/{TargetModule}/{ExtensionPointName}/`.

**WLS (Weline Long-running Server)**: Runs PHP in long-lived worker processes. Any `static` property that holds request-scoped state must be registered with `StateManager` for reset between requests. Code changes → `server:reload`. Master/Dispatcher/startup config changes → `server:restart -r`.

**Backend controllers**: Extend `Weline\Admin\Controller\BaseController`. Wrap content in `container-fluid`. Use `w-delete` component for deletions (not JS `confirm()`). For detail views, use Block Offcanvas + AJAX.

**Cross-module data**: Use `w_query()` / `unified-query-provider` pattern. Do not use events for data queries.

**Config reads**: `Env::get('key.subkey', $default)` — dot notation for nested keys.

## Global Constraints

**Never do:**
- Edit `generated/`
- Use `routes.xml`
- Call `alert()` / `confirm()` / `prompt()` in frontend JS — use BackendToast / BackendConfirm
- Hardcode user-visible text
- Do field CRUD inside `Setup/Upgrade.php`
- Use `error_log()` / `echo` / `print` for error output in production code
- Embed `<?= ?>` or `<?php ?>` inside `<w:*>` Taglib attributes (causes ParseError)
- Invent framework API methods — verify in source before using

**Always do:**
- User-visible text: `__('text')` or `<lang>text</lang>` in PHP/HTML; `@lang(text)` or `@lang{text}` in Taglib/custom tag attributes
- i18n strings in `i18n/zh_Hans_CN.csv` and `i18n/en_US.csv`
- Placeholders: `%{1}` or `%{name}` (never `%1` or `%2`)
- PHP 8.2+: null-safe with `?? ''` for args that may be null

## Skills Reference (dev/ai/skills/)

Load the matching skill for specialized topics — don't batch-read all skills. Use `dev/ai/skills/_index.md` for keyword → skill mapping. Key skills:

| Keywords | Skill path |
|---|---|
| Model, ORM, #[Col], pagination | `database-model-standards` |
| routing, URL, getUrl, 404 | `weline-routing` |
| event.xml, Hook, Extends, dispatch | `extension-points` |
| WLS, Worker, server, reload, static | `runtime-and-process` |
| Session, auth, login | `session-development` |
| phtml, CSS, JS, theme | `theme-development` |
| DataTable, Block, Taglib, Widget | `frontend-components` |
| i18n, __(), @lang | `i18n-internationalization` |
| toast, confirm, user notification | `friendly-notifications` |
| w_query, cross-module | `unified-query-provider` |
| menu.xml, ACL, #[Acl] | `acl-permission-system` |
| SSE, EventSource | `sse-streaming` |
| PageBuilder, layout | `pagebuilder-style-templates` |
| create command, Console, CommandAbstract | `create-framework-command` |
| cache, CacheFactory | `cache-usage` |

Full AI dev guide: `dev/ai/AI-开发与测试指南.md`. Global hard constraints: `dev/ai/global-constraints.md`.
