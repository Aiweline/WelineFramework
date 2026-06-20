# WLS Panel Plan

This directory is the working plan for turning WLS into an independent server panel.

## Goal

WLS should appear in the framework backend as a single authorized entry. After opening it, the user works inside an independent WLS Panel that can manage local and child WLS projects, gateway rules, PHP/database/path profiles, security rules, attack logs, marketplace plugins, and later tag/webhook based deploy flows.

## Files

| File | Purpose |
| --- | --- |
| [10-prototype.md](10-prototype.md) | UI prototype, page map, and responsive shell sketch. |
| [20-plugin-tag-logic.md](20-plugin-tag-logic.md) | Typed meta tag logic for WLS marketplace plugins. |
| [30-atomic-task-plan.md](30-atomic-task-plan.md) | Stage split and atomic implementation tasks. |
| [75-stage-1-panel-shell-e2e-evidence.md](75-stage-1-panel-shell-e2e-evidence.md) | Panel shell, marketplace, project registry, and gateway-sync validation evidence. |

## Current Stage

Stage 1 has a working independent panel shell and a focused browser smoke test:

- Backend keeps one WLS Panel menu entry.
- WLS Panel has its own visual shell, not the ordinary project backend layout.
- The shell supports light/dark theme switching.
- The layout must work on desktop and mobile.
- Existing WLS pages are reused through panel links until their functions are migrated into native panel pages.
- The WLS plugin marketplace entry now uses AppStore URLs filtered by `tag=module:wls&surface=backend`; AppStore keeps package install verification ownership.
- The WLS panel now has a server-side discovery path for installed WLS plugins:
  `WLS Panel -> WlsPanelPluginDiscoveryService -> w_query('appstore', 'installedModules') -> AppStore QueryProvider`.
  WLS still does not call AppStore internal services directly.
- The dashboard now starts from real WLS sources instead of prototype data:
  `WlsPanelDashboardDataService -> ReverseProxy + AttackLog + ServerInstanceManager`.
  Empty gateway state renders the current project only; ReverseProxy rules become interim project cards until the Stage 2 project registry is implemented.
- The standalone shell has a 2026-06-19 responsive/theme hardening slice:
  the backend fullscreen host is explicitly neutralized, the panel writes
  `data-wls-panel-theme` to the document/body for diagnostics, status colors
  use panel tokens, the marketplace grid uses responsive `auto-fit`, the
  sidebar collapses into top navigation at the same 1100px breakpoint as the
  card grids, plugin group dividers span the full mobile nav, header actions
  left-align in narrow layouts, long button labels can wrap, and dark mode now
  includes sidebar-specific variables.
- The same shell now has screenshot-level smoke evidence from a dedicated WLS
  instance plus headless Edge CDP: 1440 light, 1440 dark, 1024 dark, 768 dark,
  and 390 dark all passed with `overflow=0`, no login fallback, and
  `module:wls` marketplace cards present.

Stage 2 has a working project registry and Gateway runtime-apply slice:

- `WlsPanelProject` stores managed project metadata, including domain, admin URL, child panel URL, path, PHP profile, database profile, status, and gateway backend target.
- `WlsPanelProjectRegistryService` saves/deletes project records from the WLS Panel and synchronizes a linked ReverseProxy rule when gateway is enabled.
- The project section now renders a native panel form plus cards for current project, registered child WLS projects, and orphan ReverseProxy rules.
- Panel project save/delete and ReverseProxy manual apply now share `IpcControlGateway::proxyApply()`.
- Gateway-role E2E apply is proven with an AI test Master using `WLS_GATEWAY_ENABLED=1` and `WLS_GATEWAY_LISTEN=127.0.0.1:9672`.
- The Gateway process registers as role `gateway`, reaches `ready`, receives `TYPE_PROXY_RELOAD`, and routes a real TLS SNI request to a child HTTPS WLS backend without a full restart.
- The project registry now accepts explicit `instance`, `gateway_instance`, or `wls_instance` input and falls back to one running Gateway-enabled WLS instance when the target is unambiguous.
- The independent dashboard now includes a native Gateway Settings slice:
  active route counts, Gateway-enabled/running instance counts, WLS instance cards, a running-target selector, and manual `Apply Routes Now`.
- The target selector is also present in the project form, so project save/delete can apply to the chosen WLS Master instead of silently falling back to an ambiguous target.
- A focused service-level guard now covers ambiguous Gateway apply target
  selection: with two ready Gateway-enabled instances, `applyRoutes([])` fails
  without calling `proxyApply()`, while an explicit `gateway_instance` calls
  only the selected WLS target.
- Live dual-Gateway runtime proof also passed: A and B ran concurrently with
  distinct Gateway listeners, a unique SNI route was applied only to B, B
  returned `HTTP/1.1 200 OK` with `X-WLS-Mock: selected-b`, A failed the same
  SNI handshake, and both instances were stopped with no listener/process
  residue.
- Gateway Settings browser-form proof also passed on a dedicated panel host
  with two running Gateway targets: the standalone WLS Panel listed A/B,
  selected `ai-test-wls-gw-ui-b-9994`, submitted `Apply Routes Now`, rendered
  `网关路由已应用。`, preserved B as the selected target, B served the unique
  SNI route, A failed the same SNI handshake, and all temporary routes,
  sessions, scripts, ports, and processes were cleaned up.
- Gateway Settings browser smoke passed on `ai-test-wls-panel-gateway-9684` / port `9684`; POST apply correctly surfaces the "no connected Gateway process" warning when the selected WLS instance has no Gateway role attached.
- Gateway Settings now has an explicit runtime action selector: save only,
  worker reload, or target instance restart for listener/Gateway-role changes.
- Gateway Settings now includes a traffic mode selector. The saved
  `wls.gateway.traffic_mode` supports `auto`, `direct_listen`, and
  `passthrough`; `WLS_GATEWAY_TRAFFIC_MODE` can override it at process level.
  A restart action maps `direct_listen` to `--topology direct` and
  `passthrough` to `--topology dispatcher`, while ordinary `server:start`
  also maps the saved gateway traffic mode when runtime topology remains
  `auto`.
- Gateway Settings now displays the runtime direct-listen capability derived
  from `RuntimeCapabilityDetector`, so operators can see whether the current
  OS/kernel supports the SO_REUSEPORT path before selecting direct mode.
- A fresh Windows feasibility probe reconfirmed this checkout cannot run the
  direct-listen shared-port proof locally: PHP 8.4.16 reports
  `PHP_OS_FAMILY=Windows` and no `SO_REUSEPORT` constant, while
  `server:start --topology direct` exits before spawning the test instance.

Stage 3 has its first native Security page slice:

- `server/backend/wls-panel/security` renders inside the independent WLS Panel shell.
- The page shows 7-day attack metrics, blocked IP count, current rule JSON,
  rule summary cards, and recent attack events.
- The native attack log area now supports instance, IP, severity, attack type,
  blocked status, page size, and previous/next pagination without leaving the
  independent panel shell.
- The native attack log area now also has a project scope selector. The first
  slice builds scope options from the same dashboard project list used by the
  project cards and filters `AttackLog.domain` for current project, registered
  child WLS projects, or gateway-derived project cards.
- The native Security page now includes project security drill-down cards.
  Each managed scope shows 7-day events, blocked events, critical count, top
  attack type, latest event, and a direct link into filtered logs.
- The native Security page now includes a first project-level policy action:
  concrete project/domain cards expose `Edit Policy`, and the selected scope
  can save or remove `domain_overrides` for rate limit, path rate limits,
  path scan, SSL handshake failures, unknown route bans, IP whitelist, and
  protected paths through the same `AttackDetector::updateRules()` hot-reload
  path used by common rules.
- The project-level policy editor now includes a `Policy Inheritance Map` that
  compares common-rule values with the selected project's effective values
  across those policy fields and marks inherited, overridden, and
  custom-equals-global states before save.
- The Security page now records policy changes to
  `var/log/wls/security-policy-audit.jsonl` and renders a native
  `Security Policy Audit` list. Audit entries show action, source, scope,
  domain, and changed rule sections without storing the full rule payload.
- Rule JSON saves through `WlsPanel::postSecurityRulesSave()` and
  `AttackDetector::updateRules()` without leaving the panel.
- `AttackDetector::getRules()` now force-checks the persisted update flag before
  returning panel data, so a save followed by redirect can read fresh rules even
  when the next request lands on a different WLS worker.
- A common visual rule editor now sits above the merged JSON editor for
  `rate_limit`, `path_scan`, `ssl_handshake_failure`, `unknown_route_ban`,
  `ip_whitelist`, and `protected_paths`. Visual fields merge into the same JSON
  save path, so advanced rules remain available while common operations no
  longer require manual JSON editing.
- The visual rule editor now includes `path_rate_limits.rules` as a responsive
  row-level editor. Operators can enable/disable per-path limits, add rows by
  filling the blank row, remove rows by clearing the path, and save through the
  same merged JSON contract.
- A rule change preview now sits between the visual editor and merged JSON. It
  uses the same service merge contract for server-side preview assertions and a
  local browser preview for immediate operator feedback before save.
- `AttackDetector` now replaces explicitly configured numeric-list fields during
  default-rule merge, preventing removed whitelist/protected-path items from
  being silently restored by the default rule array.
- Legacy advanced Security Rules and Attack Log pages remain linked for deep
  management until the native visual editor and filtered log table are built.
- The dashboard now includes an Operations Capability Center. It renders fixed
  WLS operation slots for PHP profiles, database profiles, file manager, and
  tag/webhook deploy. Each slot is resolved from installed `module:wls` plugins
  by its `custom:*` typed tag, and missing slots link back to AppStore with the
  WLS marketplace filter prefilled.
- The dashboard now also includes a Project Config Center. It aggregates each
  managed project's Project Admin, Child Panel, Security, Gateway, PHP,
  Database, Files, and Deploy actions while keeping plugin-owned pages separate
  and passing only safe project context in URLs.
- The Project Config Center now exposes scoped editor intent directly on each
  project card: Attack Logs and Security Policy are separate native links,
  PHP/Database/Files/Deploy entries show whether they open a plugin-owned
  scoped editor or the filtered WLS marketplace install flow, and every
  operation link carries safe context metadata without leaking `project_path`.
- `Weline_FileManager` now includes bounded ZIP compression inside the
  standalone WLS File Manager plugin. Compression is ACL gated, requires the
  `COMPRESS_ENTRY` confirmation phrase, creates sibling ZIP files under `var`
  or `pub`, caps sources at 200 entries and 10 MB, rejects symlinks, and writes
  the same operation audit log as upload/rename/delete.
- `Weline_FileManager` now also includes queued large ZIP compression.
  `QUEUE_COMPRESS` creates a `Weline_Queue` task instead of doing large archive
  work inside the WLS request; the worker re-checks allowlisted roots, refuses
  symlinks/path escapes, caps sources at 2000 entries and 512 MB, avoids
  overwrites, and the standalone shell shows recent queue status.
- `Weline_FileManager` now also includes recoverable queued trash. `QUEUE_TRASH`
  creates a `Weline_Queue` task that moves a selected file or directory into
  same-root `.wls-trash` after worker-side root/symlink/limit checks. Completed
  jobs can be restored from the recent queue list using only `queue_id` and
  server-side queue payload, while the original path remains free.
- `Weline_FileManager` now includes the first source-code queue slice:
  `SOURCE_QUEUE_TRASH`. It is limited to one existing allowlisted source file
  under 128 KB after the source path policy is enabled, creates a
  `source_trash_entry` queue payload, and the worker re-checks root key,
  root/source path consistency, extension allowlist, protected paths, and
  `max_entries=1` before moving the file into same-root `.wls-trash`. Broader
  source queue flows remain blocked.
- `Weline_FileManager` now also includes bounded recursive directory delete.
  Files and empty directories still use `DELETE_ENTRY`; non-empty directories
  require the recursive checkbox plus `DELETE_TREE`, reject symlinks, scan the
  target tree before deleting, cap the operation at 100 entries and 10 MB, and
  emit a `delete_tree` audit record.
- `Weline_FileManager` now includes project-level path policy editing. The
  standalone plugin shell can save enabled controlled-write roots per
  project/domain context to `var/wls-panel/file-manager-path-policy.json` after
  an explicit `SAVE_PATH_POLICY` confirmation. The controller applies that
  policy through `rootCards()` before every guarded write path, so disabled
  roots become read-only immediately without exposing raw project paths. The
  same section can reset the current context after `RESET_PATH_POLICY`, deleting
  the saved project/domain entry and returning controlled roots to default
  inheritance.
- The PhpManager plugin now has its first safe operations slice:
  `Weline_PhpManager` declares `module:wls` and `custom:wls-php-manager`,
  contributes a WLS Panel menu entry, opens an independent PHP-profile shell,
  reads the current PHP runtime state, receives safe project context, stores a
  guarded project-level PHP Profile, previews php.ini directive drift, writes a
  WLS-managed ini block with backup-first apply, restores the latest PHP
  Manager backup, appends JSONL audit records, renders a PHP Profile
  inheritance map for runtime/Profile/effective values and required-extension
  satisfaction, previews install/remove extension lifecycle intent with
  execution disabled, and can optionally request an operator-selected WLS
  reload.

Remaining Stage 2 work is live direct-listen restart verification and
throughput validation on a Linux/macOS runtime that reports SO_REUSEPORT
support through `RuntimeCapabilityDetector`, plus broader multi-project UX
around the proven control path. Remaining Stage 3 work is the real execution
adapter and remaining source-write policy layer that has not yet been proven:
PHP extension install/remove platform adapters beyond the current dry-run plan,
real DbManager mysql/pgsql lifecycle, backup, restore, and migration execution
adapters plus explicit slave create/remove flows beyond the current dry-run
plans, and broader FileManager source-tree write policy beyond the current
opt-in existing-file `SAVE_SOURCE`, single-file `SOURCE_CREATE_FILE`,
same-directory `SOURCE_RENAME`, single-file recoverable `SOURCE_TRASH`, and
dedicated single-file `SOURCE_QUEUE_TRASH` source trash queue layers.
The broader FileManager source-tree policy is now split as a staged contract:
source roots remain read-only by default; the current implemented layers only
edit existing small files through `SAVE_SOURCE`, create one new small file
through `SOURCE_CREATE_FILE`, rename one existing file in the same directory
through `SOURCE_RENAME`, or move one existing small source file into same-root
`.wls-trash` through `SOURCE_TRASH`; the only source queue layer currently
allowed is `SOURCE_QUEUE_TRASH` for the same single-file recoverable trash
intent. Any broader source queue operation still requires a separate policy
flag, confirmation phrase, worker-side validation, and smoke evidence before it
becomes executable.
FileManager restore history has its first dedicated queue-history slice,
queue-created trash entries now have an explicit `PURGE_TRASH` permanent-purge
path guarded by queue payload lookup and `.wls-trash` service-layer validation,
and safe previewed text files can now be promoted into the guarded `SAVE_TEXT`
form only when the same controlled-root and extension allowlist checks pass.
The safe-text editor ergonomics slice is implemented with wrap/font controls,
dirty state, line/character/byte/cursor metrics, safe revert, and verified
desktop/mobile no-overflow layout.

Stage 5 has a first independent Deploy plugin shell:

- `Weline_Deploy` contributes WLS typed meta tags and now routes its WLS menu
  entry to `deploy/backend/wls-deploy`.
- The Deploy shell is visually independent from the ordinary backend, supports
  light/dark theme persistence, and accepts project context from the WLS Panel.
- The first shell slice shows Deploy configuration summary, current runtime
  stamp, capability roadmap, and recent release records with a safe warning
  fallback if release history storage is unavailable.
- The first project-scoped Deploy Profile slice is implemented: the standalone
  Deploy shell can save a selected WLS project's repository, branch/remote,
  deploy root, tag strategy, webhook branch/tag filters, Composer command,
  post-deploy command, rollback reference, and profile enablement, then reload
  the page with the project profile as the effective Deploy summary.
- Project-scoped release history is implemented: Deploy release records now
  persist `profile_key`, `project_id`, `domain`, and `project_type`; the WLS
  Deploy shell filters recent releases by the selected project/domain; ordinary
  Deploy history remains an all-scope operations list with a Scope column.
- Project-scoped release status is implemented: the WLS Deploy shell,
  `/deploy/version`, and webhook `?health=1` can read the selected project's
  `deploy_root/var/deploy/current.json` when safe project context is present,
  while no-context probes keep the global runtime behavior and avoid project
  Profile lookup.
- Project rollback is implemented as a guarded WLS Deploy action: the panel
  uses the saved project Profile `rollback_ref`, requires explicit
  confirmation plus a non-danger preflight, reloads effective project config
  server-side, and calls `DeployOrchestratorService::rollback()` inside the
  selected `deploy_root`.
- Route refresh and focused Playwright browser validation passed for the Deploy
  shell.
- Focused browser validation now also covers project Profile save/reload,
  direct WLS target navigation, dark theme toggle, and 390px mobile no-overflow
  behavior.
- The full WLS Panel E2E spec passed on a clean AI panel test instance using
  `--worker-memory-limit=512M`. A prior 256M worker run hit the WLS
  output-buffer memory-headroom guard in FileManager, so panel-mode test and
  production runs should use at least 512M worker memory.
- The plugin-heavy panel smoke was revalidated on
  `ai-test-wls-panel-plugin-heavy-9990` with `--worker-memory-limit=512M`:
  dashboard, marketplace, security, Deploy, FileManager, PHP Manager, and
  Database Manager all rendered as standalone shells; desktop/mobile screenshots
  and theme-toggle evidence were captured; worker memory stayed around
  251 MB / 237 MB; the instance stopped cleanly. The run also fixed plugin
  sidebar `#anchor` links so Deploy/PHP/DB/FileManager keep the current plugin
  URL and safe context instead of resolving to backend root.
- The FileManager plugin now has a project-aware controlled operations slice:
  current panel `var`/`pub` roots, or resolved child project
  `project_var`/`project_pub` roots, can create directories, save small text
  files, upload guarded safe-extension files, perform same-folder rename, delete
  files or empty directories, and run bounded recursive directory delete.
  `server.wlsPanelProject` resolves child path context through `w_query()` so
  FileManager does not depend on Server internals, and source roots remain
  read-only. Writes require the dedicated
  `Weline_FileManager::wls_file_manager_write` ACL plus in-panel confirmation,
  and all success/denied/failed attempts append JSONL audit records under
  `var/log/wls_file_manager_operations.log`. The operation log now also has a
  native audit summary and filters for action, result, root, and keyword.
  Project-level path policies are persisted per safe context and immediately
  narrow the controlled write roots for all FileManager operations; the current
  context can also be reset to default inheritance without posting raw
  `project_path` values. Queue-backed ZIP compression now uses
  `Weline_FileManager`'s queue worker for larger archives, and recoverable
  queued trash moves destructive intent into `.wls-trash` with a server-payload
  restore action instead of permanent deletion. The first preview-to-edit slice
  also preloads editable safe text previews into the guarded save form. A new
  opt-in source-edit path policy can promote already existing small source-code
  previews under enabled `project`, `local_project`, or `app_code` roots into a
  guarded `SAVE_SOURCE` form and can create one new small allowlisted source
  file through `SOURCE_CREATE_FILE` or rename one existing allowlisted source
  file inside the current directory through `SOURCE_RENAME` without opening
  source upload, delete, recursive delete, or queue operations on source roots.
- The DbManager plugin now has its first safe operations slice:
  `Weline_DbManager` declares `module:wls` and
  `custom:wls-database-manager`, contributes a WLS Panel menu entry, opens an
  independent database-profile shell, reads effective DB config from
  `Env::getDbConfig()`, masks account/password state, receives safe project
  context, exposes a POST-only sanitized connection test, and now stores a
  guarded project-level database Profile with encrypted password state,
  JSONL audit records, optional operator-selected WLS reload request, masked
  persistent `db`/`db.master` and existing `db.slaves.*` env drift preview,
  backup-first env.php apply, latest-backup rollback, and explicit
  `COPY_ENV_PASSWORD` import from the selected source env profile into the
  encrypted Project Profile. The shell now also previews create database,
  create user, grant user lifecycle intent, and backup/restore/migration
  dry-run intent with execution disabled until vendor adapters exist. The
  backup plan validates artifact names, confines future artifacts to
  `var/backups/wls/db-manager/database`, blocks unsafe restore input, and keeps
  migration dry-run free of DDL, DML, dump writes, restore writes, and WLS reload
  side effects. Slave writes are restricted to already configured env entries
  and do not create, delete, or reorder replicas.
