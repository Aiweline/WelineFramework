# WLS Panel Atomic Task Plan

## Stage 1 - Independent Panel Shell

| ID | Task | Validation |
| --- | --- | --- |
| WLS-PANEL-001 | Add a single backend WLS Panel menu entry. | Backend route opens `server/backend/wls-panel`. |
| WLS-PANEL-002 | Add independent panel shell. | Shell has `data-wls-shell="standalone"`. |
| WLS-PANEL-003 | Add light/dark theme toggle. | Implemented and refined: toggle updates `data-wls-theme`, syncs document `color-scheme`, persists local storage, and falls back to system dark preference when no saved choice exists. |
| WLS-PANEL-004 | Add responsive dashboard and marketplace layout. | Implemented and refined: desktop shell pins sidebar/main into separate grid columns; mobile stacks navigation above content; Playwright desktop/mobile screenshots have no horizontal overflow. |
| WLS-PANEL-005 | Link existing WLS management pages from inside the panel. | Links open ServerManager, ReverseProxy, Security, AttackLog, SSL pages. |
| WLS-PANEL-006 | Replace dashboard prototype counters and fake child project with real WLS data. | Dashboard data service reads ReverseProxy, AttackLog, and ServerInstanceManager; empty gateway state shows only the current project. |
| WLS-PANEL-007 | Harden standalone shell responsive/theme contract after plugin-heavy UI growth. | Passed current shell proof: fullscreen backend host is neutralized, shell collapses sidebar to top navigation at the same 1100px breakpoint as the content grids, plugin nav dividers span all mobile columns, header actions left-align on narrow layouts, long translated button labels wrap safely, marketplace cards use `auto-fit`, status colors use panel tokens, and dark mode includes sidebar variables. Headless Edge CDP smoke captured 1440 light, 1440 dark, 1024 dark, 768 dark, and 390 dark screenshots with `passed=true`, no login fallback, `overflow=0`, `module:wls` marketplace tag present, and no assertion failures. |

## Stage 2 - Gateway And Project Management

| ID | Task | Validation |
| --- | --- | --- |
| WLS-GATEWAY-001 | Add panel mode env flag. | WLS can boot with panel mode on/off. |
| WLS-GATEWAY-002 | Add gateway mode env flag controlled from panel. | Config save triggers WLS reload. |
| WLS-GATEWAY-003 | Add project registry with domain, admin URL, panel URL, path, PHP profile, DB profile. | Create/update/delete works; linked ReverseProxy rule is created/updated/deleted when gateway is enabled. |
| WLS-GATEWAY-004 | Prefer direct listen mode while preserving proxy passthrough fallback. | Multiple workers can serve the same public gateway port when supported. |
| WLS-GATEWAY-005 | Add child WLS panel jump-through. | Parent panel can open child project admin/panel links. |
| WLS-GATEWAY-006 | Promote ReverseProxy rules into interim project cards until the full project registry exists. | ReverseProxy domain/upstream/status renders in the WLS Panel project list without static sample data. |
| WLS-GATEWAY-007 | Wire panel project save/delete into WLS `proxy_apply` control path. | Panel save/delete and ReverseProxy apply send normalized routes to the target WLS Master; if no Gateway role is connected, the panel surfaces the runtime warning instead of silently claiming reload success. |
| WLS-GATEWAY-008 | Prove full Gateway-role E2E apply. | Passed: `ai-test-wls-gateway-master-9671` started with `WLS_GATEWAY_ENABLED=1`; Gateway reached `ready`, `proxyApply()` targeted `gateway(ipc:452)`, and `curl --resolve ... https://<domain>:9672/` returned `HTTP/1.1 200` with `X-Weline-Route-Hint: ... sni=<domain>`. |

### Stage 2 Implementation Notes

- `WLS-GATEWAY-003` now has the first persisted slice:
  `Weline\Server\Model\WlsPanelProject` stores managed project metadata and
  `Weline\Server\Service\WlsPanelProjectRegistryService` owns panel form save/delete orchestration.
- The project form captures `name`, `domain`, `admin_url`, `panel_url`, `project_path`, `php_profile`, `database_profile`, `status`, `gateway_enabled`, `backend_host`, `backend_port`, `backend_ssl`, and `description`.
- When gateway is enabled, the registry service synchronizes a linked `ReverseProxy` record. When a project is deleted, the linked proxy rule is removed.
- The dashboard data service now merges current project, registered panel projects, and orphan ReverseProxy rules. Orphan rules remain visible as gateway-derived cards so existing proxy configuration is not hidden.
- The runtime apply control path is now wired:
  `WLS Panel project save/delete -> WlsPanelProjectRegistryService -> IpcControlGateway::proxyApply -> ServiceOrchestrator ACTION_PROXY_APPLY -> Gateway TYPE_PROXY_RELOAD`.
- `ReverseProxyManager::postApply()` also uses the same public `proxyApply()` API instead of a private control send path.
- The first proof reached a running WLS Master and returned a clear warning when no Gateway role process was connected.
- The full Gateway-role E2E proof now passes with an actual Gateway process and host-routed TLS SNI request.
- `WlsPanelProjectRegistryService` now resolves explicit `instance`, `gateway_instance`, or `wls_instance`; if none is provided, it can auto-select exactly one running Gateway-enabled WLS instance, then falls back to `default`.
- `WlsPanelGatewaySettingsService` adds the first native Gateway Settings slice inside the independent panel:
  it scans persisted WLS instances, identifies running/Gateway targets, counts active ReverseProxy routes, and exposes a manual `Apply Routes Now` action through `WlsPanel::postGatewayApply()`.
- The project form and Gateway Settings form now share a `gateway_instance` selector. The selector is restricted to running/control-capable targets; stopped historical instances stay visible as instance status cards but are not offered as apply targets.
- If more than one running Gateway-enabled instance is available, the selector is required before applying routes.
- The service-level guard for that selector is covered by
  `WlsPanelGatewaySettingsServiceTest`: ambiguous ready Gateway targets return
  a selection error without calling `proxyApply()`, and explicit
  `gateway_instance` input calls `proxyApply()` only for that selected target.
- The live dual-Gateway runtime proof now passes for the control-plane slice:
  `ai-test-wls-gw-target-a-9985` and `ai-test-wls-gw-target-b-9987` ran with
  Gateway listeners on `9986` and `9988`; `proxyApply()` targeted only B
  (`gateway(ipc:935)`); B routed `wls-gw-selected-target.local` to a TLS mock
  backend and returned `HTTP/1.1 200 OK` with `X-WLS-Mock: selected-b`; A
  failed the same SNI handshake; both instances were stopped and left no
  listener/process residue.
- The browser-form proof now also passes for the same target-selection path:
  dedicated host `ai-test-wls-gw-ui-host-9991` rendered the standalone WLS
  Panel, listed A and B in `.wls-gateway-apply-form`, selected
  `ai-test-wls-gw-ui-b-9994`, submitted the native `Apply Routes Now` action,
  rendered `网关路由已应用。`, preserved B as selected, and the post-submit route
  check proved B served `wls-gw-ui-selected.local` while A did not.
- The Gateway Settings slice now persists `wls.gateway.enabled` and `wls.gateway.listen` through `Env::setConfig()` from `WlsPanel::postGatewaySave()`.
- `WLS-GATEWAY-010` has its first implementation slice: Gateway Settings now
  persists `wls.gateway.traffic_mode`, supports `auto`, `direct_listen`, and
  `passthrough`, shows saved versus effective traffic mode, warns when
  `WLS_GATEWAY_TRAFFIC_MODE` overrides saved config, and maps concrete modes
  to runtime startup topology on restart or ordinary `server:start` when the
  saved topology is still `auto`. The panel also renders
  `RuntimeCapabilityDetector` output so the direct-listen option clearly shows
  whether the current runtime can use SO_REUSEPORT.
- The panel shows saved versus effective Gateway state, warns when `WLS_GATEWAY_ENABLED` or `WLS_GATEWAY_LISTEN` overrides saved config, and can apply current routes after saving without claiming that listener changes hot-spawned a Gateway process.
- Gateway save now exposes an explicit runtime action selector:
  `none` only saves/applies routes, `reload` requests `IpcControlGateway::reloadAsync(..., force)`, and `restart` submits `php bin/w server:start <instance> -r -f` through `Processer::create()` for listener/Gateway-role changes.
- Gateway restart submission now preserves the selected instance runtime shape:
  the panel builds `server:start <instance> -r -f` with the current port,
  worker count, HTTP/HTTPS mode, topology, and memory limits from
  `ServerInstanceInfo` plus raw instance JSON before calling
  `Processer::create()`. This prevents a panel-triggered restart from falling
  back to default HTTPS/default worker settings.
- Direct-listen restart is now capability-gated from the panel:
  `WlsPanelGatewaySettingsService::saveConfiguration()` still persists
  `wls.gateway.traffic_mode=direct_listen`, but when
  `RuntimeCapabilityDetector` reports no SO_REUSEPORT support it returns
  `runtime_action_blocked=true`, `runtime_action_success=false`, and
  `restart_required=true` instead of submitting a `server:start --topology direct`
  command that would fail on the current host.
- Dedicated WLS validation on port `9664` was attempted and cleaned up, but that instance did not become a stable listener in this checkout. Browser validation used an existing non-9501 WLS instance as fallback evidence; the dedicated-instance startup issue remains a runtime follow-up.
- Native Gateway Settings validation on port `9684` passed:
  `Weline_Server-panel-shell.spec.js` passed after template cache refresh; a focused Playwright DOM smoke confirmed `#gateway-settings`, `select[name="gateway_instance"]`, `.wls-gateway-apply-form`, no horizontal overflow, and no runtime fatal; a POST smoke showed the expected visible warning when no Gateway role process was connected.
- Native Gateway Settings live restart validation passed on dedicated WLS
  instances: host `ai-test-wls-panel-restart-host-9936` submitted a real panel
  save with `runtime_action=restart` for target
  `ai-test-wls-panel-restart-target-9938`; target changed Master PID from
  `45416` to `9732`, stayed on `http://127.0.0.1:9938`, kept `count=2`,
  `ssl_enabled=false`, `dispatcher_enabled=true`, and returned `HTTP 200`.

### Stage 2 Remaining Atomic Tasks

| ID | Task | Validation |
| --- | --- | --- |
| WLS-GATEWAY-009 | Complete native panel gateway settings page. | Passed for the native slice: status cards, target selection, manual route apply, env persistence, listen editing, env override warning, explicit runtime action selection, responsive form smoke, and live panel-triggered restart verification all passed. |
| WLS-GATEWAY-010 | Implement direct-listen traffic-mode contract while preserving passthrough mode. | Implemented slices: config/UI/env override/startup mapping exist for `auto`, `direct_listen`, and `passthrough`; Gateway Settings displays direct-listen runtime capability from `RuntimeCapabilityDetector`; unsupported runtimes can save `direct_listen` but panel-triggered restart is blocked before submitting a failing `--topology direct` command. Current Windows feasibility refresh: PHP 8.4.16 has no `SO_REUSEPORT` constant and `server:start --topology direct` exits with `WLS direct topology requires SO_REUSEPORT on this OS/kernel.` before creating the AI test instance. Remaining validation is live direct-listen SO_REUSEPORT routing and throughput on Linux/macOS or another runtime where `RuntimeCapabilityDetector` reports support. |
| WLS-GATEWAY-011 | Add multi-instance target selector for ambiguous Gateway apply. | Passed. Implemented in panel forms for running/control-capable targets and protected by a focused service test: ambiguous ready Gateway targets fail before `proxyApply()`, while explicit `gateway_instance` calls only the selected target. Live runtime proof passed with two Gateway-enabled WLS instances, and browser-form proof passed from the standalone WLS Panel: B was selected and submitted, B served the unique SNI route, A failed the same SNI handshake, and all temporary routes/instances/scripts/session artifacts were cleaned up. |

## Stage 3 - Security And Operations

| ID | Task | Validation |
| --- | --- | --- |
| WLS-SEC-001 | Move security rules into native panel page. | Rules save and reload without leaving panel shell. |
| WLS-SEC-002 | Move attack logs into native panel page. | Logs filter by project/instance. |
| WLS-SEC-006 | Add project-level security policy overrides. | Selected project/domain can save domain-specific AttackDetector overrides while common rules remain fallback. |
| WLS-OPS-001 | Add PHP profile plugin hook. | Implemented guarded write/apply/inheritance/extension-plan slice: `Weline_PhpManager` exposes WLS typed meta, is discoverable through the same `appstore.installedModules` query path, contributes a WLS menu entry, opens a standalone PHP profile shell, reads runtime PHP state, saves per-project PHP Profiles, previews php.ini drift, applies a managed ini block with backup and rollback, renders recent audit entries, shows runtime/Profile/effective inheritance plus required-extension satisfaction, previews install/remove extension lifecycle intent with execution disabled, and can optionally request WLS reload. Remaining work is real extension install/remove adapters. |
| WLS-OPS-002 | Add database profile plugin hook. | Implemented guarded write/apply/import/lifecycle/backup-plan slices: `Weline_DbManager` exposes WLS typed meta, opens a standalone panel shell, reads effective DB profiles, masks credentials, supports guarded connection tests, saves encrypted project-level database Profiles, can explicitly import the selected env password after `COPY_ENV_PASSWORD`, previews and applies persistent `db`/`db.master` plus existing `db.slaves.*` drift with backup and rollback, renders audit entries, and previews create database/create user/grant/backup/restore/migration intent. Existing slave writes are guarded to configured entries only. Remaining work is real mysql/pgsql lifecycle, backup, restore, migration execution adapters, and explicit slave create/remove flows. |
| WLS-OPS-003 | Add file manager plugin hook. | Implemented current slice set: `Weline_FileManager` exposes WLS typed meta, contributes a WLS menu entry, opens a standalone path/context shell, supports guarded browse/preview/download/create/save/upload/rename/delete/recursive delete/compress/queued compression, recoverable queued trash restore and permanent purge, path policy editing/reset, audit summary/filter controls, managed child project root switching, safe-text editor ergonomics, an opt-in existing-file `SAVE_SOURCE` source-editing policy, dedicated `SOURCE_CREATE_FILE` single-file source creation, dedicated same-directory `SOURCE_RENAME`, and dedicated single-file recoverable `SOURCE_TRASH` for enabled `project`/`local_project`/`app_code` roots without opening source-root upload/delete/purge/queue operations. Remaining work is source queue policy. |
| WLS-OPS-005 | Add native Project Config Center. | Dashboard aggregates admin, child panel, security, gateway, PHP, DB, files, and deploy actions per project without leaking `project_path`. |

### Stage 3 Implementation Notes

- `WLS-SEC-001` has its first native implementation slice:
  `WlsPanelSecurityDataService` reads security statistics, current
  `AttackDetector` rules, blocked IPs, and recent attack records for the
  independent WLS Panel.
- `WlsPanel::getSecurity()` renders `server/backend/wls-panel/security` inside
  the standalone shell. The normal framework backend remains only the authorized
  entry point.
- `WlsPanel::postSecurityRulesSave()` saves validated JSON rules through
  `AttackDetector::updateRules()` and redirects back to the native Security
  page with a panel notice or error.
- The template now renders the Security page with 7-day event metrics, blocked
  IP count, JSON rules editor, rule summary cards, recent attack cards, and
  advanced links to the legacy full Security Rules / Attack Log pages.
- The focused panel E2E now covers:
  desktop dashboard, Gateway runtime-action selector, dark-theme persistence,
  native Security page, mobile dark marketplace, typed plugin tags, and
  horizontal-overflow assertions.
- `WLS-SEC-003` has its first native implementation slice:
  the Security page renders a common visual rule editor for `rate_limit`,
  `path_scan`, `ssl_handshake_failure`, `unknown_route_ban`, `ip_whitelist`,
  and `protected_paths` above the advanced merged JSON editor.
- `WlsPanelSecurityDataService::saveRulesFromPanel()` validates the JSON base,
  merges visual fields onto matching common keys, preserves unexposed advanced
  rule sections, and saves through the same `AttackDetector::updateRules()`
  contract used by the raw JSON path.
- `AttackDetector` keeps default fallback for missing rule sections but now
  replaces explicitly supplied numeric-list fields such as
  `protected_paths.paths`, `ip_whitelist.ips`, `path_rate_limits.rules`,
  `malicious_patterns.patterns`, and `bad_user_agents.patterns`. This prevents
  default list entries from reappearing after an operator removes them.
- `WLS-SEC-003` now includes the `path_rate_limits.rules` row editor. The
  service builds editable rows from the current rules, appends a blank add row,
  normalizes path prefixes, clamps numeric values, skips blank path rows, and
  saves the resulting list as a replacement list through the existing
  `AttackDetector::updateRules()` path.
- `WLS-SEC-003` now also includes a rule change preview. The service exposes
  `previewRulesFromPanel()` for assertion-safe server preview using the same
  merge code as save, while the browser renders immediate pending changes
  locally from the current form values.
- `WLS-SEC-005` now includes project security drill-down cards. The service
  reuses `panelDashboardData['projects']` scope options, summarizes each scope
  through `AttackLog` by instance/domain, and exposes events, blocked count,
  critical count, risk, top attack type, latest event, and direct filtered-log
  links.
- `WLS-SEC-006` now has its first project-action slice:
  project security cards expose an `Edit Policy` action for concrete domains;
  the Security page renders a `Project Security Policy` form for the selected
  scope; saves write to `domain_overrides.domains[domain].rules` through the
  existing `AttackDetector::updateRules()` hot-reload contract.
- The project domain override editor now covers rate limit, path rate limits,
  path scan, SSL handshake failures, unknown route bans, IP whitelist, and
  protected paths. Common rules remain the fallback. At request time `AttackDetector`
  normalizes the HTTP Host header, builds temporary effective rules for the
  matched domain, restores common rules after detection, and records normalized
  domain values in attack logs.
- `WLS-SEC-006` now also includes policy audit history:
  successful common-rule, advanced-JSON, project-policy save, and
  project-policy removal operations append compact JSONL records under
  `var/log/wls/security-policy-audit.jsonl`. The native Security page renders
  the latest audit entries for the current scope, including action, source,
  domain, and changed rule sections while intentionally avoiding full rule
  payload storage.
- `WLS-SEC-006` now includes project policy inheritance visibility:
  `WlsPanelSecurityDataService` merges `domain_overrides` using the same
  replace-list behavior as `AttackDetector`, compares common and
  project-effective values for the editable rate-limit, path-rate,
  path-scan, SSL-handshake, unknown-route, whitelist, and protected-path
  fields, and the native panel renders a responsive `Policy
  Inheritance Map` with inherited, overridden, and custom-equals-global states.
- `WLS-SEC-006` now includes multi-worker read-after-write stability:
  `AttackDetector::getRules()` force-checks the persisted update flag before it
  returns panel data, so a save followed by redirect can render fresh
  `domain_overrides` even if the redirect request is served by another WLS
  worker.
- `WLS-OPS-004` now has its first dashboard implementation slice:
  `WlsPanelPluginDiscoveryService::getOperationCapabilities()` defines fixed
  WLS operation slots for PHP profiles, database profiles, file manager, and
  deploy releases. It resolves installed state by exact typed tags from
  AppStore-installed `module:wls` plugin metadata and renders missing slots as
  AppStore install links with the WLS tag filter prefilled.
- `WLS-OPS-004` now has its project deep-link slice:
  each managed project card renders `PHP Config`, `Database Config`, `Files`,
  and `Deploy` actions from the same operation slot keys. Installed plugins
  receive safe context (`operation`, `project_id`, `domain`, `project_type`);
  missing plugins still open the filtered AppStore install flow. Raw local
  `project_path` is not placed in URL query strings.
- `WLS-OPS-004` now has its installed-plugin entry slice:
  `appstore.installedModules` exposes raw `marketplace_meta`, `capabilities`,
  and panel entry fields such as `wls_panel_url`, `panel_url`, `backend_url`,
  `capability_url`, `panel_entry`, `backend_entry`, and `wls_panel`.
  `WlsPanelPluginDiscoveryService` resolves operation URLs from those fields and
  filters unsafe schemes before project-card links append context.
- `WLS-OPS-004` now has its plugin menu contribution slice:
  installed `module:wls` plugins can declare `wls_panel.menu[]` in module
  marketplace meta. `WlsPanelPluginDiscoveryService::getPanelContributions()`
  reads those entries without a WLS-specific PHP inheritance contract, filters
  unsafe URLs, falls back to a single panel URL when no menu is declared, and
  the standalone panel renders the entries in the sidebar plus a dashboard
  contribution section.
- `WLS-OPS-004` now also normalizes the first valid `wls_panel.menu[]` entry as
  the plugin default panel entry. Installed-plugin cards and operation capability
  cards can therefore open menu-only WLS plugins instead of showing
  "No panel entry declared".
- `WLS-OPS-003` now has its first real plugin implementation slice:
  `Weline_FileManager` declares `etc/marketplace/meta.json` with
  `module:wls`, `custom:wls-file-manager`, `feature:file-manager`,
  `capability:files-read`, and `system:true`. AppStore installed-module query
  can merge enabled local modules that expose marketplace meta, so bundled WLS
  capabilities and online installed plugins use the same discovery contract.
- `WLS-OPS-001` now has its first real plugin implementation slice:
  `Weline_PhpManager` declares `etc/marketplace/meta.json` with `module:wls`,
  `custom:wls-php-manager`, `feature:php-config`,
  `capability:php-runtime-read`, `capability:php-profile-write`,
  `capability:wls-reload-request`, and `system:true`.
  The module contributes `wls_panel.menu[]` to the standalone panel, satisfies
  the dashboard `php-profile` operation slot, and opens
  `weline_phpmanager/backend/wls-php-manager` as an independent PHP profile
  shell.
- `Weline\PhpManager\Controller\Backend\WlsPhpManager` reads current PHP
  runtime information through `WlsPhpProfileService::getRuntimeInfo()`,
  renders runtime cards, loaded extension chips, safe WLS project context, and
  a guarded Project PHP Profile form.
- `WLS-OPS-001` now also has a guarded project-profile write slice:
  `Weline\PhpManager\Model\WlsPhpProfile` stores per-project PHP Profiles
  keyed by `project_id`, `domain`, or `local`;
  `WlsPhpProfileService` handles form defaults, sanitized path/size/list
  fields, JSONL audit records, and optional `IpcControlGateway::reloadAsync()`
  requests for a selected WLS instance.
- `WLS-OPS-001` now also has a reversible php.ini apply slice:
  `WlsPhpIniService` builds an apply plan from the saved Project Profile,
  compares pending directive values against the target php.ini, limits writes
  to bundled/sandbox ini paths, writes only a WLS-managed directive block after
  creating a backup under `var/backups/wls/php-manager`, records JSONL audit
  events, and restores the latest PHP Manager backup through a confirmed
  rollback action.
- `WLS-PHP-EXT-001` now has its first dry-run implementation slice:
  `WlsPhpExtensionPlanService` normalizes install/remove intent from safe
  request parameters, compares the selected extension with current runtime
  state, Project Profile required extensions, and a core-extension protection
  list, then renders a disabled execution boundary in the standalone PHP
  Manager shell. This slice deliberately does not run package commands,
  enable/disable extensions, or write php.ini extension directives; those
  require a future platform adapter with allowlisted commands, confirmation,
  audit, and WLS reload binding.
- `WLS-OPS-002` now has its first real plugin implementation slice:
  `Weline_DbManager` declares `etc/marketplace/meta.json` with `module:wls`,
  `custom:wls-database-manager`, `feature:database-profile`,
  `capability:database-read`, `capability:database-test`,
  `capability:database-write`, `capability:wls-reload-request`, and
  `system:true`.
  The module contributes `wls_panel.menu[]` to the standalone panel, satisfies
  the dashboard `database-profile` operation slot, and opens
  `weline_dbmanager/backend/wls-db-manager` as an independent DB profile shell.
- `Weline\DbManager\Controller\Backend\WlsDbManager` reads the effective
  framework DB config through `Env::getDbConfig()`, normalizes direct,
  `master`, and `slaves` profiles, masks usernames and password state in the
  template, keeps safe project context in URLs/forms, and performs only a
  POST-only `SELECT 1` connection test with sanitized redirect notices/errors.
- `WLS-OPS-002` now also has a guarded project-profile write slice:
  `Weline\DbManager\Model\WlsDatabaseProfile` stores per-project database
  Profiles keyed by `project_id`, `domain`, or `local`;
  `WlsDatabaseProfileService` handles form defaults, password encryption,
  blank-as-keep password behavior, clear-password behavior, explicit
  `COPY_ENV_PASSWORD` env password import, JSONL audit records, saved Profile
  connection tests, and optional
  `IpcControlGateway::reloadAsync()` requests for a selected WLS instance.
- `WLS-OPS-002` now also has a reversible env apply slice:
  `WlsDatabaseEnvApplyService` compares the saved Project Profile with the
  persistent `app/etc/env.php` `db`/`db.master` target or an already configured
  `db.slaves.*` entry, renders a masked diff plus write mode, preserves the
  existing env password when the Profile has no encrypted password, requires
  `APPLY_DB_ENV` before write, creates backups under
  `var/backups/wls/db-manager`, and restores the latest Database Manager backup
  through a confirmed `ROLLBACK_DB_ENV` action. Slave writes are deliberately
  limited to existing entries and do not create, delete, or reorder read
  replicas.
- `Weline\FileManager\Controller\Backend\WlsFileManager` provides an
  independent fullscreen plugin shell at
  `weline_filemanager/backend/wls-file-manager`. It receives safe project
  context from WLS project cards, resolves managed project path data through
  `w_query('server', 'wlsPanelProject', ...)`, renders context-aware root
  cards, and keeps write operations behind the path allowlist, ACL,
  confirmation, and operation-log tasks.
- `Weline_FileManager` now has its first guarded read-only browser slice:
  the plugin accepts `root` plus a relative `path`, resolves both through
  `realpath()`, rejects escaping paths, and only lists entries under the
  selected project/app/var/pub root. Directory listings expose name, type, size,
  modified time, readable status, preview availability, and download
  availability, capped at 200 entries.
- `Weline_FileManager` now has its guarded read-only preview/download slice:
  common text/config/code formats can be previewed inline up to 64 KB, binary or
  unsupported content is rejected in-panel, and readable files inside the
  selected allowlisted root can be downloaded up to 20 MB through framework
  response headers that work under WLS.
- `Weline_FileManager` now has its first controlled write slice:
  `postCreateDirectory()` and `postSaveText()` are protected by the
  `Weline_FileManager::wls_file_manager_write` ACL, only operate inside the
  selected allowlisted root, keep `project` and `app_code` read-only, and open
  writes only for the current panel `var`/`pub` roots or resolved child project
  `project_var`/`project_pub` roots. Directory creation requires a confirmation
  checkbox. Text saves require a confirmation checkbox plus the `SAVE_TEXT`
  phrase, reject binary null bytes, cap content at 128 KB, and restrict writes
  to safe text extensions. Every success, denial, and failure appends a JSONL
  record to `var/log/wls_file_manager_operations.log`, and the latest records
  render in the plugin shell.
- `Weline_FileManager` now has its second controlled operations slice:
  `postUpload()`, `postRename()`, and `postDelete()` reuse the same write ACL,
  root allowlist, in-panel confirmation, and JSONL operation log. Uploads are
  capped at 5 MB and restricted to safe text/document/archive/web-asset
  extensions, rename stays in the same folder, files and empty directories use
  the normal `DELETE_ENTRY` path, and non-empty directories now have a bounded
  recursive delete policy requiring an explicit recursive checkbox plus the
  `DELETE_TREE` phrase. The recursive delete scanner rejects symlinks, requires
  the tree to remain inside the selected writable root, caps deletion at 100
  entries and 10 MB, then deletes from leaf nodes upward and writes a
  `delete_tree` audit record. `postCompress()` adds the bounded ZIP slice:
  archives are created beside the selected file/directory, require
  `COMPRESS_ENTRY`, reject symlinks, cap sources at 200 entries and 10 MB,
  never overwrite an existing archive, clean partial ZIPs on failure, and write
  the same operation log. `postCompressQueue()` adds the first queue-backed
  large-file slice: the panel requires `QUEUE_COMPRESS`, creates a
  `Weline_Queue` task through `w_query('queue', 'create', ...)`, and the
  `WlsFileManagerLargeOperationQueue` worker re-checks root boundaries,
  refuses symlinks/path escapes, caps sources at 2000 entries and 512 MB, and
  never overwrites an existing archive. `postTrashQueue()` and
  `postTrashRestore()` add the first recoverable destructive queue slice:
  `QUEUE_TRASH` moves the selected target into same-root `.wls-trash`, stores
  trash metadata in queue content, and a completed job can be restored from the
  recent queue list through server-side `queue_id` lookup while the original
  path is still free. The first richer restore-history UX slice now derives a
  dedicated 30-entry trash history from the same queue data and marks each
  trash job as restorable, waiting, blocked by an existing target, unavailable,
  failed, or permanently purged before exposing the existing restore form.
  `postTrashPurge()` adds the first explicit permanent-purge path for
  queue-created trash entries only: the form requires `PURGE_TRASH`, posts
  `queue_id`, reloads server-side queue content, calls the large-operation
  service to delete only entries inside the recorded `.wls-trash` root, and
  stamps `trash_purged_at` back into the queue content. The first
  preview-to-edit slice promotes only non-truncated writable safe-text previews
  into the guarded `SAVE_TEXT` form; the editor ergonomics slice adds
  wrap/font controls, dirty state, line/character/byte/cursor metrics, safe
  revert, and mobile-safe toolbar wrapping without changing the server-side
  write policy. Existing-file source-code editing now has a guarded opt-in
  `SAVE_SOURCE` policy slice; broad source-tree writes remain future slices.
- `Weline_FileManager` now has a project-aware path context slice:
  `ServerQueryProvider::wlsPanelProject` exposes a backend-only read operation
  for managed project metadata. File Manager uses that operation instead of a
  direct `Weline_Server` service dependency, reads `project_id`/`domain` from
  GET or POST, switches the active `project` root to the child project when a
  valid registry path exists, adds read-only `local_project` for the host panel
  root, exposes child `project_var` and `project_pub` as guarded writable roots,
  and lets audit filters derive valid root keys from the rendered root cards.
- `Weline_FileManager` now has its persisted path-policy editing slice:
  `WlsFileManagerPathPolicyService` stores policies in
  `var/wls-panel/file-manager-path-policy.json` keyed by `project:*`,
  `domain:*`, or `local`; `postPathPolicySave()` requires the write ACL,
  confirmation checkbox, and `SAVE_PATH_POLICY` phrase; and `rootCards()`
  applies the saved enabled-root list before any guarded write action resolves
  a target path. The form only posts safe context and root keys, so raw
  `project_path` stays server-side.
- The path-policy reset slice is also implemented:
  `WlsFileManagerPathPolicyService::resetFromPanel()` requires
  `confirm_path_policy_reset=1` plus `RESET_PATH_POLICY`, removes the current
  safe context entry from the same policy store, deletes the store when no
  policies remain, and `WlsFileManager::postPathPolicyReset()` records the
  `path_policy_reset` audit action before returning the standalone shell to
  `#path-policy`.
- `WLS-OPS-005` now has its first native aggregation slice:
  `WlsPanelProjectConfigCenterService` builds a dashboard Project Config
  Center from the same `panelDashboardData['projects']` list used by project
  cards and the same operation capability slots used by plugin cards. The
  center renders project admin, child panel, native Security scoped logs,
  native Gateway Settings, PHP, database, files, and deploy actions without
  copying raw `project_path` into URLs.
- The full panel E2E now asserts the Project Config Center on desktop and
  mobile, checks the four operation links per project, verifies security and
  gateway actions are present, and confirms generated links do not include
  `project_path`.

### Stage 3 Remaining Atomic Tasks

| ID | Task | Validation |
| --- | --- | --- |
| WLS-SEC-003 | Add a visual rule editor on top of the JSON rules contract. | Implemented: native panel controls cover common AttackDetector switches, thresholds, path-rate-limit rows, whitelist entries, protected paths, and before-save diff preview while keeping merged JSON available. |
| WLS-SEC-004 | Add native attack-log filters and pagination. | Implemented: Security page filters by project scope/domain, instance, IP, severity, attack type, blocked status, keeps page size, and paginates attack cards without leaving the panel. Project-aware scope is covered by WLS-SEC-005 and shares the same `AttackLog.domain` filter path. |
| WLS-SEC-005 | Add project-aware security scope. | Implemented: parent panel can filter native attack logs and 7-day security metrics by all managed targets, current project, registered child projects, or gateway-derived project cards through `AttackLog.domain`; the Security page now also renders per-scope drill-down cards with risk, top attack type, latest event, and direct filtered-log links. |
| WLS-SEC-006 | Add project-level security policy overrides. | Implemented expanded coverage: selected concrete project/domain can save or remove `domain_overrides` for rate limit, path rate limits, path scan, SSL handshake failures, unknown route bans, IP whitelist, and protected paths; successful changes append JSONL audit records; the editor shows a responsive inheritance map comparing common values with project-effective values; panel rule reads force-check the persisted update flag for multi-worker read-after-write stability. |
| WLS-OPS-001 | Add PHP profile plugin hook. | Implemented guarded write/apply/inheritance/extension-plan slice: `Weline_PhpManager` exposes WLS typed meta, is discoverable through the same `appstore.installedModules` query path, contributes a WLS menu entry, opens a standalone PHP profile shell, reads runtime PHP state, saves per-project PHP Profiles, previews php.ini drift, applies a managed ini block with backup and rollback, renders recent audit entries, supports dark/light and mobile layouts, shows a PHP Profile inheritance map for runtime/Profile/effective values plus required-extension satisfaction, previews install/remove extension lifecycle intent with execution disabled, and can optionally request WLS reload. Remaining work is real extension install/remove adapters. |
| WLS-OPS-002 | Add database profile plugin hook. | Implemented guarded write/apply/import/lifecycle/backup-plan slices: `Weline_DbManager` exposes WLS typed meta, is discoverable through the same `appstore.installedModules` query path, contributes a WLS menu entry, opens a standalone DB profile shell, reads `Env::getDbConfig()`, masks credentials, supports `master/default/slaves` readout, keeps project context, provides a POST-only sanitized connection test, saves per-project DB Profiles, encrypts password state, supports explicit `COPY_ENV_PASSWORD` env password import, renders recent audit entries, previews persistent `db`/`db.master` and existing `db.slaves.*` env drift, applies `app/etc/env.php` with backup-first writes, supports latest-backup rollback, previews create database/create user/grant user lifecycle intent, previews backup/restore/migration dry-run intent with artifact confinement and unsafe restore blocking, and optionally requests WLS reload. Existing slave writes are guarded to already configured entries only. Remaining work is real mysql/pgsql lifecycle, backup, restore, and migration execution adapters plus explicit slave create/remove flows. |
| WLS-OPS-003 | Add file manager plugin hook. | Implemented current slice set: `Weline_FileManager` exposes WLS typed meta, is discoverable through the same `appstore.installedModules` query path, contributes a WLS menu entry, opens a standalone path/context shell, provides guarded directory browsing, inline text preview, 20 MB capped downloads, directory creation, small text saves, guarded uploads, same-folder rename, file/empty-directory delete, bounded recursive directory delete, bounded ZIP compression, queued large ZIP compression, recoverable queued trash move/restore, ACL-gated write confirmation, JSONL operation logs inside allowlisted roots, native audit summary/filter controls for action/result/root/keyword, project-aware root resolution through `server.wlsPanelProject`, persisted project/domain path policy editing through `var/wls-panel/file-manager-path-policy.json`, and confirmed reset back to default inheritance for the current safe context. Managed project links can switch the active project root to the child project and expose child `project_var`/`project_pub` as controlled write roots while keeping source roots read-only for ordinary operations; saved policies immediately narrow controlled roots before every write action and can separately enable existing-file `SAVE_SOURCE` source editing, dedicated `SOURCE_CREATE_FILE` single-file creation, dedicated same-directory `SOURCE_RENAME` source rename, and dedicated single-file recoverable `SOURCE_TRASH` source trash for `project`, `local_project`, or `app_code` roots without opening source-root upload/hard-delete/purge/queue actions. Reset removes the saved context without exposing raw project paths, queued ZIP tasks run through `Weline_Queue` with worker-side root/limit revalidation, queued trash moves target same-root `.wls-trash` with restore based on server-side queue payload, the panel renders a dedicated 30-entry recoverable trash history with restore availability states, queue-created trash entries support `PURGE_TRASH` permanent purge with service-layer `.wls-trash` validation plus `trash_purged_at` history state, non-truncated writable safe-text previews preload the existing guarded `SAVE_TEXT` editor while enabled source-code previews preload the guarded `SAVE_SOURCE` editor, source creation requires the `SOURCE_CREATE_FILE` phrase and a non-existing allowlisted filename inside an existing enabled source directory, source rename requires the `SOURCE_RENAME` phrase, an existing allowlisted source file, a different non-existing allowlisted target name, and the same source directory, source trash requires the `SOURCE_TRASH` phrase, one existing allowlisted source file under 128 KB, and moves it into the same-root `.wls-trash` folder, and the safe-text/source editor keeps wrap/font controls, dirty state, line/character/byte/cursor metrics, safe revert, desktop/mobile no-overflow layout, and cache-busted browser evidence. Remaining work is source queue policy. |
| WLS-OPS-004 | Define plugin menu contribution shape for WLS operations pages. | Implemented fourth slice plus default-entry normalization: dashboard capability cards resolve PHP, database, file-manager, and deploy slots from installed `module:wls` plugin typed tags; project cards deep-link to those same slots with safe project context; AppStore query output carries raw marketplace meta, capabilities, and panel entry fields; installed plugins can declare `wls_panel.menu[]` entries that render in the standalone panel sidebar and dashboard contribution section; the first valid menu entry is also exposed as the default plugin entry for installed-plugin cards and operation buttons. |
| WLS-OPS-005 | Add native Project Config Center. | Implemented second slice: dashboard now renders a responsive project-scoped configuration center with safe context labels, separate Attack Logs and Security Policy links, Gateway links, and PHP/Database/Files/Deploy scoped editor entries. Installed plugins open their owned writable pages with `operation`, `project_id`, `domain`, and `project_type`; missing plugins still open the filtered `module:wls` marketplace flow. Browser validation confirmed desktop/mobile no-overflow and no `project_path` leakage. |

## Stage 4 - Marketplace Typed Tags

| ID | Task | Validation |
| --- | --- | --- |
| WLS-TAG-001 | Store typed meta tags in module metadata. | `MarketplaceMetaReaderTest::testStrictPackageMetaAcceptsTypedColonTags` passes for `module:wls`, `custom:wls-file-manager`, and `system:false`. |
| WLS-TAG-002 | Update Office Site marketplace indexing. | Implemented/located in `Framework-Official\App\weline\app\code\Weline\PlatformAppStore`: `POST /api/v1/platform/module/list` accepts `tag`, `tags`, and `tag_match`; `ModuleCatalogService` normalizes typed tags and requires exact matches, so `module:wls-extra` does not match `module:wls`. |
| WLS-TAG-003 | Update WLS marketplace client. | WLS Panel marketplace forwards `tag=module:wls&surface=backend` into AppStore online and installed-module flows. |
| WLS-TAG-004 | Refresh panel after module install/update. | Implemented native refresh, return-context, auto-refresh, clean-fragment redirect, menu-only entry normalization, and visible refresh-result summary slices: AppStore exposes `w_query('appstore', 'installedModules')` with meta/capability/entry fields; WLS Panel POSTs `plugin-refresh`, refreshes framework registries, route-refreshes discovered WLS plugin modules only, reruns discovery, and returns to Marketplace with a refreshed notice without direct AppStore class coupling. The redirect now carries `panel_plugin_refresh=1`, registry mode/count, route refresh/count, WLS plugin count, and panel contribution count, so the marketplace page can show exactly what changed and what was reloaded. WLS-origin AppStore links carry `wls_panel_return=1`; AppStore install/update success redirects back to the independent WLS Panel marketplace with `panel_auto_refresh=plugins`; the panel auto-submits the existing POST refresh form once and then clears the auto-refresh flag. The WLS Panel redirect helpers append fragments after URL building and redirect through the response object, so the final URL is `...?panel_notice=plugins_refreshed#installed-plugins` instead of leaving a trailing empty query delimiter. Menu-only plugins receive a normalized default entry so refreshed installed cards can immediately open the contributed WLS panel page. |
| WLS-TAG-005 | Add setup fast path for plugin install/update reload. | Implemented: `setup:upgrade --skip-classmap` and `--skip-composer-dump` can bypass the local `composer.phar dump-autoload` child-process hang, while `setup:background-optimize --skip-classmap` now really skips classmap generation. WLS Panel plugin refresh now also uses a panel-local registry path: it refreshes the module list, discovers installed `module:wls` plugins, runs `RegistryUpdateService::updateModuleRegistriesIncremental()` for those plugin modules, route-refreshes the same module list, and only falls back to `updateAllRegistries()` when the incremental registry refresh fails. Validation: Weline_Deploy schema stages exited 0 and printed the fast-mode skip notice; source-level refresh assertions confirm the incremental call is the primary path and global refresh exists only as fallback. |

### Stage 4 Implementation Notes

- Client-side typed tag parsing belongs to `Weline\Framework\MarketplaceMeta\MarketplaceTag`.
- AppStore strict install continues to require structured tag entries with source-locale labels.
- AppStore can consume platform responses from `tags`, flat `tags_resolved`, locale-grouped `tags_resolved`, or `marketplace_meta.tags`.
- Online marketplace filtering is backed by the official-site source at
  `E:\WelineFramework\Framework-Official\App\weline\app\code\Weline\PlatformAppStore`.
  `ModuleCatalogService::normalizeTypedTagFilter()` accepts typed strings,
  arrays, JSON arrays, and structured tag objects; `moduleHasTypedTags()` uses
  exact matching for `all`/`any` semantics.
- WLS plugin modules declare tags in `etc/marketplace/meta.json`; they do not need a special PHP inheritance contract just for discovery.
- `module:wls` is the only mandatory WLS compatibility tag.
- `custom:*`, `category:*`, `feature:*`, and `system:*` are ordinary typed tags consumed by marketplace and panel filters.
- Stage 1 WLS Panel uses AppStore URLs for install flow ownership instead of directly calling AppStore services from `Weline_Server`.
- Installed plugin discovery is server-side and cross-module safe: `WlsPanelPluginDiscoveryService` calls `w_query('appstore', 'installedModules', ['tag' => 'module:wls', 'surface' => 'backend'])`.
- AppStore owns installed module metadata normalization and returns `tag_codes`, `surface_codes`, localized labels, and custom identity tags to WLS.
- Panel refresh now uses `WlsPanelPluginRefreshService` after install/update:
  it refreshes the module list, discovers installed `module:wls` plugins,
  refreshes Framework registries incrementally for those plugin modules,
  route-refreshes the same plugin modules only, then reruns
  `WlsPanelPluginDiscoveryService::refreshCapabilities()`. A failed
  incremental registry refresh falls back to full registry refresh; an empty
  plugin set is a no-op and does not trigger all-module registry or route
  rebuilds from the panel.
- Panel refresh also returns plugin menu contribution state. Because the route
  refresh receives an explicit module list, an empty plugin set does not trigger
  an all-module route rebuild from the panel.
- The marketplace now renders the latest POST refresh result when
  `panel_plugin_refresh=1` is present: registry mode/count, route refresh
  status/count, installed WLS plugin count, and WLS panel menu contribution
  count. This makes module install/update reload observable in the independent
  panel instead of relying on a notice-only redirect.
- WLS Panel AppStore entry links now carry `wls_panel_return=1` so the shared
  AppStore UI can keep a visible `Back To WLS Panel` action and preserve the
  panel context through filter, authorize, install, check-update, and update
  forms.
- WLS Panel uses the canonical AppStore index route `appstore/backend` for
  online plugin browsing. AppStore action endpoints remain under
  `appstore/backend/index/...`.
- On successful install or update with `wls_panel_return=1`, AppStore redirects
  to `server/backend/wls-panel/marketplace?panel_notice=plugins_refreshed&panel_auto_refresh=plugins#installed-plugins`.
  WLS stays independent from the project backend while AppStore continues to
  own package verification and installation.
- The standalone panel does not mutate registry state on GET. It exposes
  `data-wls-auto-refresh="plugins"` and the browser submits the existing
  `plugin-refresh` POST form once; the POST redirect removes
  `panel_auto_refresh` and prevents loops.
- WLS Panel-local redirects use a clean-fragment helper and direct response
  redirects, avoiding the controller-level empty query delimiter that can appear
  when a fully built URL with `#installed-plugins` is passed back through
  `PcController::redirect()`.
- Focused AppStore page-level E2E now covers the online plugin page, installed
  module page, and authorize-install page with `wls_panel_return=1`.
- Setup fast path support now exists for WLS Panel-safe plugin refreshes:
  when Composer dependencies and autoload config did not change, panel install
  or update flows can run `setup:upgrade` with `--skip-classmap` or the
  explicit `--skip-composer-dump` flag to avoid the currently hanging local
  `extend/server/composer.phar dump-autoload` child process.
- Remaining setup/update work for the panel is to profile after-observer cost.
  A validated fast setup run still took 141 seconds because `upgrade_after`
  observers performed cron/i18n/module collection work after composer was
  skipped, but the WLS Panel refresh button no longer depends on the full setup
  path for ordinary plugin capability reloads.

## Stage 5 - Deploy Capability

| ID | Task | Validation |
| --- | --- | --- |
| WLS-DEPLOY-001 | Mount Deploy as WLS capability. | Implemented first slice: `Weline_Deploy` declares `etc/marketplace/meta.json` with `module:wls`, `custom:wls-deploy`, `feature:tag-deploy`, webhook/tag capabilities, and a WLS panel menu contribution. The WLS dashboard now resolves Deploy as an installed operation card and renders the contributed Deploy menu entry. |
| WLS-DEPLOY-002 | Add webhook endpoint. | Implemented service bridge: Frontend and Rest webhook controllers now authenticate Gitee/GitHub/Bearer/query tokens through `DeployWebhookReleaseService`, resolve push/tag refs, skip mismatched refs, and call `DeployOrchestratorService::release()` instead of shell-only `webhook.sh deploy`. |
| WLS-DEPLOY-003 | Default to tag-only deploy. | Implemented first runtime slice: config defaults and ref resolver default to `tag`; branch push is rejected with `trigger_mode_tag_only` unless legacy config or explicit mode enables branch. |
| WLS-DEPLOY-004 | Add child project deploy config. | Project Profile persistence, command allowlists, real webhook context resolution, project-scoped webhook secret binding, rollback-reference policy, and explicit rollback action wiring are implemented: `deploy/backend/wls-deploy` accepts WLS project context (`project_id`, `domain`, `operation`, `project_type`), saves/loads `Weline\Deploy\Model\DeployProjectProfile`, normalizes allowed Composer/post-deploy commands and rollback references at save time, stores an optional project `webhook_secret` without echoing it back to the panel, applies enabled project profile values to the effective Deploy summary after immediate reload, and lets the public webhook endpoint read `profile_key/project_id/domain/project_type` from query or payload to overlay the enabled project Profile before token verification and release execution. `DeployOrchestratorService` now resolves `DEPLOY_ROOT` as the release execution root for Git, backup source, post-deploy commands, runtime stamp writes, server reload, and rollback checkout. The rollback POST action executes only when the selected project Profile is enabled, has a stored rollback ref, and the preflight is not `danger`. |
| WLS-DEPLOY-005 | Add release logs and status. | Project-scoped release logging is implemented: `DeployRelease` stores `profile_key`, `project_id`, `domain`, and `project_type`; `DeployOrchestratorService` writes context-aware records from explicit context or runtime config; `DeployReleaseHistoryService::getRecentForContext()` filters the WLS Deploy panel by selected project/domain while legacy `getRecent()` remains an all-scope list for CLI and ordinary backend history. The first dry-run slice is implemented as a panel-only release preflight with an explicit Run Preflight action: it checks Profile source, repository URL shape, deploy-root text safety, tag/branch trigger policy, Webhook path/secret state, and command allowlist without running Git, writing files, or reloading WLS. The second dry-run slice is Webhook Replay: the panel accepts a `payload.ref`, resolves it through `DeployWebhookRefResolver` against the selected project's effective Profile/global settings, and displays `ready` or `skipped` without invoking the release orchestrator. The third dry-run slice is Manual Release Plan: the panel accepts an explicit manual ref, normalizes raw tag names safely, applies the selected project Profile, blocks `danger` preflight contexts, and renders the exact future execution steps while never calling `DeployOrchestratorService::release()`. The first real-webhook safety harness is implemented at service level: a branch-only Profile can force a tag payload to return `202 skipped` with project scope, proving Profile overlay without executing Git. The controlled success-path POST harness is also proven with a temporary bare Git remote, temporary working clone as `deploy_root`, project secret, real `~wh~` POST, real orchestrator execution, project-scoped release history, and full cleanup. A controlled rollback harness is proven with a temporary bare Git remote, temporary working clone, saved project Profile, real `DeployOrchestratorService::rollback()` tag checkout, project-scoped release history, `current.json` rollback metadata, and full cleanup. The WLS Deploy status slice now also reads `deploy_root/var/deploy/current.json` for the selected project: `deploy/backend/wls-deploy`, `/deploy/version`, and webhook `?health=1` accept safe project context, only invoke Profile overlay when context is present, and otherwise preserve the global runtime probe path. Focused validation passed with service probes, route checks, authenticated desktop/mobile browser smoke, and theme-toggle smoke on the non-default `dev-docs-api-9524` WLS instance. |

### Stage 5 Implementation Notes

- `Weline_Deploy` is now discoverable as a WLS plugin through the same typed
  meta path used by FileManager.
- Webhook execution now enters the same release orchestrator used by
  `deploy:release`, so webhook tag publish can write release history,
  version stamps, post-deploy command output, and server reload intent through
  one service boundary.
- Runtime config from webhook setup is normalized into orchestrator project
  config for branch, remote, update mode, backup, and post-deploy command.
- WLS policy now defaults Deploy to tag-only at both stored-config fallback and
  file-config resolver levels. Legacy `webhook_allow_tag_deploy` remains
  supported for existing installations.
- `deploy/backend/wls-deploy` is now the WLS Panel plugin landing page for
  Deploy. The page uses the independent fullscreen shell, keeps Deploy visually
  separate from the ordinary project backend, and offers direct jumps to Deploy
  configuration, release history, and the project backend.
- Deploy marketplace meta now routes both webhook/tag capability entries and
  the WLS panel menu contribution to `deploy/backend/wls-deploy`, so the
  Operations Capability Center and plugin nav open the same standalone shell.
- The Deploy shell exposes a first project-context contract for future child
  WLS integration: the parent WLS Panel can pass the selected project/domain
  into the Deploy page without coupling Deploy to the main panel controller.
- The Deploy shell now has a concrete project Profile persistence path:
  `DeployProjectProfileService` stores per-project repository, branch/remote,
  deploy root, trigger policy, tag/branch filters, Composer/post-deploy
  commands, rollback reference, and enabled state. Enabled profiles override
  global Deploy summary values for that selected WLS project immediately after
  save/reload.
- `DeployProjectCommandPolicyService` now protects the Profile save path for
  command fields. Composer is limited to `composer install` plus a small flag
  allowlist, post-deploy commands must be `php bin/w <allowed-command>` and may
  chain allowed maintenance commands with `&&`, while shell control characters,
  redirects, pipes, quotes, command substitution, and arbitrary script paths are
  rejected before persistence.
- `DeployOrchestratorService` now reuses the same command policy after global
  and runtime release config are merged, before backup, Git update, or command
  execution. This covers older persisted values and non-panel entry points such
  as webhook and CLI release flows.
- `DeployReleaseHistoryService` now supports project-scoped release history.
  Child-project releases are keyed by `profile_key` (`project:<id>` or
  `domain:<domain>`), and `is_current` is cleared only within the same release
  scope so one child project cannot overwrite another project's current marker.
- The standalone WLS Deploy page now uses the current `project_id`/`domain`
  context to show only that project's latest release records, while the ordinary
  Deploy release history page keeps the cross-scope list and exposes a `Scope`
  column.
- `DeployProjectProfileService::buildPanelPreflight()` now exposes the first
  dry-run contract for the WLS Deploy shell. It is intentionally read-only:
  Profile source, repository URL shape, deploy-root text safety, tag-only
  policy, Webhook readiness, and command allowlist are checked before an
  operator sees future release actions, but the preflight never runs Git,
  creates directories, writes version stamps, or reloads WLS.
- `WlsDeploy::postPreflightRun()` gives operators an explicit button-backed
  dry-run checkpoint. It recomputes the same read-only preflight for the
  selected project context, redirects back to `#preflight`, and only returns a
  notice or blocker message.
- `WlsDeploy::postWebhookReplay()` adds a safe Webhook Replay checkpoint for
  WLS Deploy. It accepts `refs/tags/...` or `refs/heads/...`, applies the same
  enabled project Profile override as the Deploy summary, and only renders the
  resolver decision. It deliberately does not call
  `DeployWebhookReleaseService::releaseFromWebhook()` or
  `DeployOrchestratorService::release()`.
- `WlsDeploy::postManualPlanRun()` adds the third dry-run checkpoint for future
  manual release execution. It accepts an explicit ref, safely treats raw names
  as tag refs in tag mode, blocks `danger` preflight contexts, resolves the ref
  through `DeployWebhookRefResolver`, redirects back to `#manual-release-plan`,
  and renders Profile, deploy root, remote/update mode, backup, Composer,
  post-deploy, runtime-stamp/reload, and dry-run boundary steps. It deliberately
  does not call `DeployOrchestratorService::release()`, does not write release
  history, and does not reload WLS.
- Manual release execution now reuses the same registered
  `manual-plan-run` POST route instead of adding a separate route. The
  `Run Release` button posts `manual_action=run_release`, requires
  `confirm_manual_release=1`, and the controller re-runs Profile loading,
  preflight, and `DeployWebhookRefResolver` before calling
  `DeployOrchestratorService::release()` with `trigger=manual`. In `danger`
  preflight or skipped trigger-policy contexts, execution is blocked before
  Git, release-history, runtime-stamp, or WLS reload side effects.
- The real webhook path now shares the same project context contract as the WLS
  Deploy shell. `DeployWebhookReleaseService::releaseFromWebhook()` accepts
  safe context from the request query or JSON payload (`profile_key`,
  `project_id`, `domain`, `project_type`), asks
  `DeployProjectProfileService` for an enabled Profile overlay, resolves the
  ref against that effective config, and passes both `context` and `config` to
  `DeployOrchestratorService`.
- Project-scoped webhook secret binding is implemented. `DeployProjectProfile`
  stores an optional `webhook_secret`; the panel never echoes the secret value,
  only whether it is configured. Webhook controllers now resolve the effective
  project config before token verification, so a project secret can accept the
  request while the global secret is rejected for that project. Projects without
  an individual secret continue to use the global `webhook_secret`.
- Project rollback reference policy is implemented as a non-destructive
  preflight gate. `DeployProjectCommandPolicyService::normalizeRollbackRef()`
  accepts only safe tag-like refs, `refs/tags/...`, `refs/heads/...`, or 7-40
  character commit SHAs, rejects shell control characters and ambiguous ref
  syntax, and `buildPanelPreflight()` renders a `rollback` check before any
  rollback button is allowed to execute.
- Project rollback action wiring is implemented. `WlsDeploy::postRollbackRun()`
  requires POST plus an explicit confirmation checkbox, reloads the selected
  project Profile, blocks missing/disabled/danger preflight contexts, and calls
  `DeployOrchestratorService::rollback()` with the effective project config.
  The panel renders the saved rollback ref, disables the action until the
  Profile is ready, and redirects back to the release section with rollback
  result metadata after success.
- `DeployOrchestratorService::rollback()` shares the release history, runtime
  stamp, env sync, reload intent, and release-after event path with ordinary
  release execution while limiting Git work to the normalized rollback ref.
  Tags checkout as detached tag versions, `refs/heads/...` rolls back to the
  remote branch, and 7-40 character SHAs checkout detached commits.
- `DeployGitMetadataService` now executes Git with `proc_open()` argument
  arrays and `bypass_shell=true` instead of shell-composed `cd ... && git ...`
  strings. This preserves real Git exit codes while avoiding the Windows
  `cmd`/PATH/AutoRun failures that produced false non-zero exits during
  release and rollback harnesses.
- `DeployOrchestratorService`, `DeployGitMetadataService`, and
  `DeployReleaseRuntimeService` now honor `DEPLOY_ROOT` for project-scoped
  releases. A configured root must be absolute and already exist; otherwise the
  release fails instead of running in the host BP by accident. Empty roots still
  preserve the legacy host-project behavior.
- `DeployWebhookReleaseService::healthPayload()` and `deploy/version` now share
  the same safe project-status contract. When the request carries
  `profile_key`, `project_id`, `domain`, or `project_type`, Deploy overlays the
  enabled project Profile and reads that project's
  `deploy_root/var/deploy/current.json`; without project context, the global
  health/version probes do not call project Profile lookup and continue reading
  the global configured runtime root or host `var/deploy/current.json`.
- `WlsDeploy::getIndex()` reads the current runtime stamp from the selected
  effective `deploy_root` before rendering the standalone WLS Deploy status
  cards, so parent WLS project cards can show child-project release state
  without confusing it with the host panel release stamp.
- A controlled success-path webhook POST harness is validated without adding a
  production fake-success switch. The harness creates a temporary bare Git
  remote and temporary working clone under `var/tmp`, saves a temporary enabled
  project Profile with its own webhook secret, starts a dedicated non-9501 WLS
  instance, POSTs a real tag payload to the public `~wh~` route, lets
  `DeployOrchestratorService::release()` run Git checkout and write
  `var/deploy/current.json` inside the temporary `deploy_root`, verifies the
  project-scoped `DeployRelease` success record, then restores `app/etc/env.php`
  and `deploy_settings`, removes Profile/release rows, stops WLS, closes the
  port, and deletes the temp tree.
- A controlled rollback action harness is validated without touching the host
  checkout. The harness creates a temporary bare Git remote with a rollback tag
  and a forward commit, clones it into a temporary `deploy_root`, saves an
  enabled project Profile with `rollback_ref=refs/tags/v-rollback-1`, runs
  `DeployOrchestratorService::rollback()` through the project-effective config,
  verifies HEAD equals the tagged rollback commit, verifies
  `var/deploy/current.json` records `deploy_version=v-rollback-1` and the
  rollback ref, verifies one project-scoped release record, then restores
  `app/etc/env.php`, deletes Profile/release rows, and removes the temp tree.
- Focused Deploy browser validation passed, and the full WLS Panel shell E2E
  passed on a clean AI panel test instance using `--worker-memory-limit=512M`.
  A prior 256M worker run hit the WLS output-buffer memory-headroom guard in
  FileManager, so panel-mode test/prod guidance should document the 512M
  baseline.
- The guarded manual release execution button is now implemented. It reuses the
  existing `manual-plan-run` POST route with `manual_action=run_release`,
  requires `confirm_manual_release=1`, re-runs Profile/preflight/ref-policy
  checks server-side, and blocks `danger` or skipped contexts before any Git,
  release-history, runtime-stamp, or WLS reload side effects.
- Route-upgrade validation for the Deploy gate also fixed the ACL persistence
  blocker around `Weline_Framework::binquery`: the class ACL now has a concrete
  menu parent and ACL batch-save payloads normalize boolean/integer fields
  before PostgreSQL persistence. The focused fast route upgrade exits 0 and
  leaves `manual-plan-run::POST` registered without creating a
  `manual-release-run` route.
- Remaining Stage 5 work is later profiling FileManager memory pressure under
  plugin-heavy panel sessions. Panel-mode test/prod runs should use at least
  `--worker-memory-limit=512M`, matching the validated full-panel E2E baseline.

## Parallel Agent Backlog

These are the remaining work packets that should be assigned to separate
agents. Each packet is intentionally small enough to validate independently
before merging back into the full WLS Panel goal.

| Packet | Suggested agent lane | Scope boundary | Primary files | Validation gate |
| --- | --- | --- | --- | --- |
| WLS-GW-PERF-001 | WLS runtime / process | Prove live `direct_listen` SO_REUSEPORT routing, then compare with passthrough. Run this only on Linux/macOS or another runtime where `RuntimeCapabilityDetector` reports `supports_reuse_port=true`; current Windows validation is negative-only and must not be treated as throughput evidence. Do not change panel UI unless runtime evidence requires a status wording update. | `app/code/Weline/Server/Service/MasterProcess.php`, `app/code/Weline/Server/Service/WlsPanelGatewaySettingsService.php`, Gateway process services | Start two non-9501 Gateway-capable WLS instances on the supported runner, verify shared public listener behavior, collect HTTP reachability, route hint, process count, request distribution/throughput comparison versus passthrough, and cleanup evidence. |
| WLS-GW-TARGET-002 | WLS runtime / E2E | Completed ambiguous Gateway apply verification with two live Gateway-enabled targets and a browser-form POST through Gateway Settings. | `WlsPanelGatewaySettingsService`, `WlsPanelProjectRegistryService`, WLS Panel template | Passed: `WlsPanelGatewaySettingsServiceTest` (`OK (2 tests, 10 assertions)`); live A/B Gateway instances on ports `9986`/`9988`; B-only `proxyApply()` returned `gateways=1`; B served `HTTP/1.1 200 OK` with `X-WLS-Mock: selected-b`; A failed the same SNI handshake; browser-form proof on dedicated host `9991` selected B `9994`, rendered `网关路由已应用。`, B served `X-WLS-Mock: ui-selected-b`, A failed the same SNI handshake, and cleanup left no matching PHP process or listening port. |
| WLS-PHP-EXT-001 | PHP ops plugin | Implemented first dry-run slice: the PHP Manager extension section accepts install/remove intent, validates safe extension names, blocks invalid/core/remove-missing cases, warns on profile-required removal, renders future adapter steps, and keeps the execution button disabled. No shell command, php.ini extension write, install, remove, or reload side effect exists in this slice. | `app/code/Weline/PhpManager`, WLS Panel plugin docs | Passed: PHP lint, i18n CSV parse, service dry-run probes for core-remove block and install dry-run, focused Chrome browser smoke with action disabled, desktop/mobile no-overflow, screenshots under the task artifact directory, and WLS instance cleanup for `ai-test-wls-php-ext-9997`. |
| WLS-DB-LC-001 | DB ops plugin | Implemented first dry-run slice: Database Manager now has a lifecycle plan service and panel section for create database, create user, and grant user intent. It validates driver support, safe identifiers, credential readiness, and host/grant scope while keeping execution disabled until vendor adapters exist. | `app/code/Weline/DbManager`, DB profile services/models | Passed: PHP lint, i18n CSV parse, service probes for supported/blocked states, focused browser smoke with action disabled, desktop/mobile no-overflow, screenshots under the task artifact directory, and WLS cleanup evidence for `ai-test-wls-db-lifecycle-9998`. |
| WLS-DB-BACKUP-002 | DB ops plugin | Implemented first safe planning slice: Database Manager now has a backup/restore/migration dry-run plan service and panel section. It validates driver support, safe database identifiers, safe artifact names, profile source, backup scope, migration target, and future artifact confinement under `var/backups/wls/db-manager/database`; dangerous restore input is blocked and the execution button stays disabled until real adapters add preflight, confirmation phrase, pre-restore backup, audit records, verification probes, and rollback guidance. | `app/code/Weline/DbManager`, `app/code/Weline/Server/doc/wls-panel-plan` | Passed: PHP lint, i18n CSV parse, marketplace meta JSON parse, service probes for backup/restore/migration states, focused browser smoke for desktop dry-run, mobile restore-blocked state, dark theme inheritance, no horizontal overflow, screenshots under the task artifact directory, and WLS cleanup evidence for `ai-test-wls-db-backup-9999`. |
| WLS-FILE-EDIT-001 | File manager plugin | Implemented safe-text edit slices and the source-code edit policy slices: previewed safe text files under existing controlled roots can preload the guarded `SAVE_TEXT` form after root, extension, truncation, and writability checks; enabled source roots can preload existing small source files into a guarded `SAVE_SOURCE` form after source root policy, extension, protected-path, symlink, size, and writability checks; enabled source directories can create one new non-existing allowlisted source file through `SOURCE_CREATE_FILE`, rename one existing allowlisted source file in the same directory through `SOURCE_RENAME`, and move one existing allowlisted source file under 128 KB into same-root `.wls-trash` through `SOURCE_TRASH`. The editor keeps wrap/font controls, dirty state, line/character/byte/cursor metrics, safe revert, and mobile-safe toolbar wrapping without weakening ACL, confirmation phrase, root policy, extension whitelist, or audit logging. Remaining work is source queue policy. | `app/code/Weline/FileManager` | PHP lint and static policy checks pass for the source-edit/source-create/source-rename/source-trash slices; browser desktop/mobile edit smoke from the prior safe-text slice remains the latest visual baseline until the next WLS runtime smoke refresh. |
| WLS-FILE-SOURCE-002 | File manager plugin | Implemented executable source-write layers: broader source-tree writes are separated into dedicated layers instead of making source roots ordinary writable roots. The implemented layers are existing-file `SAVE_SOURCE`, single-file `SOURCE_CREATE_FILE`, same-directory source rename with `SOURCE_RENAME`, and single-file recoverable source trash with `SOURCE_TRASH`; future slices must add dedicated policy flags and phrases for any source queue flow. | `app/code/Weline/FileManager`, WLS Panel Plan docs | Route refresh and functional HTTP smoke passed for `source-trash` on `ai-test-wls-file-source-trash-9976` using non-default port `9976` and `--no-dispatcher`; browser visual screenshot capture was blocked by in-app browser URL policy, so the latest visual baseline remains the prior desktop/mobile FileManager smoke. Remaining source queue layer stays disabled. |
| WLS-PROJECT-CONFIG-002 | Business module / panel | Implemented scoped editor entry slice: Project Config Center now links existing PHP/DB/security/file/deploy writable pages without duplicating their rules, splits Attack Logs from Security Policy, and labels each operation as scoped editor versus marketplace install. | `WlsPanelProjectConfigCenterService`, WLS Panel template, WLS Panel Plan docs | Passed focused browser smoke on `ai-test-wls-project-config-9996`: project card exposed safe context, four operation links, policy/log/gateway actions, dark theme toggle, desktop and 390px no-overflow, and no `project_path` in generated URLs. |
| WLS-PANEL-MEM-001 | QA / runtime | Completed current Windows/Dispatcher plugin-heavy panel validation with FileManager, Deploy, PHP, DB, security, and marketplace loaded together. Evidence also fixed plugin sidebar anchor links that resolved to backend root under the backend base URL. | `app/code/Weline/Deploy/view/templates/Backend/WlsDeploy/index.phtml`, `app/code/Weline/PhpManager/view/templates/Backend/WlsPhpManager/index.phtml`, `app/code/Weline/DbManager/view/templates/Backend/WlsDbManager/index.phtml`, `app/code/Weline/FileManager/view/templates/Backend/WlsFileManager/index.phtml`, task artifacts | Passed on `ai-test-wls-panel-plugin-heavy-9990` with `--worker-memory-limit=512M`: full panel route sweep, desktop/mobile screenshots, theme toggles, anchor regression check, worker memory about 251 MB / 237 MB, `server:stop`, final status stopped, no LISTEN on 9990. |

Concurrency rules:

- Gateway runtime tasks can run in parallel with PHP/DB/File plugin tasks if
  they use distinct non-9501 ports and unique WLS instance names.
- PHP/DB/File plugin tasks must not edit shared WLS Panel shell CSS without a
  UI owner review; they should keep plugin-local styles scoped to their shell.
- DB lifecycle and Deploy release harnesses must not share temporary
  repositories, databases, or project profile keys.
- Every packet must append evidence to this plan directory and record WLS
  cleanup (`server:stop`, process check, env restore when applicable).
