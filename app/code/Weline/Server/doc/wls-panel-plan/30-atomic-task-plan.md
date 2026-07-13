# WLS Panel Atomic Task Plan

## Current Supersession Note

The 2026-06-29 humanized redesign supersedes the earlier shared `wls-panel-plugins.js` and hash-anchor plugin navigation approach. Current implementation rules are: WLS owns plugin parent/child menus in the panel sidebar; menu clicks use real WLS Panel routes; embedded plugin pages render wrapperless with `embedded=1`; plugin-local sidebars/titlebars stay hidden inside the WLS panel; and security child pages use focused WLS routes instead of `#security-*` anchors.

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
| WLS-PANEL-008 | Add global WLS plugin interaction layer. | Superseded by the 2026-06-29 native shell direction. The historical shared `wls-panel-plugins.js` layer was removed because the current WLS Panel shell no longer loads it. Plugin integration is now server-route/template driven: WLS renders plugin parent/child menus, child clicks open focused WLS plugin-shell routes, and embedded plugin pages hide their own visible chrome. |

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
- Current topology contract supersedes the historical Stage 2 field below:
  `wls.runtime.topology` is authoritative. `wls.gateway.traffic_mode`,
  `WLS_GATEWAY_TRAFFIC_MODE`, `direct_listen`, and `passthrough` are read-only
  migration aliases and may be mapped only when no explicit authoritative
  topology exists; new panel/instance writes must not persist them.
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
- `WLS-GW-PERF-001` now has a supported-runner proof in task
  `2026-06-22-0729-wls-direct-listen-supported-runner-proof`: a disposable
  Linux PHP 8.4.22 Docker runner reports `SO_REUSEPORT=true`,
  `supports_reuse_port=true`, starts two direct workers on shared public port
  `10045`, compares dispatcher mode on public port `10046` with workers
  `27608/27609`, proves worker distribution through
  `/_wls/health?detail=1`, and cleans up WLS processes, container, and ports.
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
| WLS-GATEWAY-010 | Implement direct-listen traffic-mode contract while preserving passthrough mode. | Passed current contract: config/UI/env override/startup mapping exist for `auto`, `direct_listen`, and `passthrough`; Gateway Settings displays direct-listen runtime capability from `RuntimeCapabilityDetector`; unsupported runtimes can save `direct_listen` but panel-triggered restart is blocked before submitting a failing `--topology direct` command. Windows feasibility remains negative-only (`SO_REUSEPORT=no`), while task `2026-06-22-0729-wls-direct-listen-supported-runner-proof` provides positive Linux runner evidence: two direct workers share port `10045`, dispatcher comparison uses port `10046` with workers `27608/27609`, health probes succeed with balanced worker distribution, and high-concurrency direct proof reached `360.89 QPS` versus dispatcher `290.96 QPS` on the same health workload. |
| WLS-GATEWAY-011 | Add multi-instance target selector for ambiguous Gateway apply. | Passed. Implemented in panel forms for running/control-capable targets and protected by a focused service test: ambiguous ready Gateway targets fail before `proxyApply()`, while explicit `gateway_instance` calls only the selected target. Live runtime proof passed with two Gateway-enabled WLS instances, and browser-form proof passed from the standalone WLS Panel: B was selected and submitted, B served the unique SNI route, A failed the same SNI handshake, and all temporary routes/instances/scripts/session artifacts were cleaned up. |

## Stage 3 - Security And Operations

| ID | Task | Validation |
| --- | --- | --- |
| WLS-SEC-001 | Move security rules into native panel page. | Rules save and reload without leaving panel shell. |
| WLS-SEC-002 | Move attack logs into native panel page. | Logs filter by project/instance. |
| WLS-SEC-006 | Add project-level security policy overrides. | Selected project/domain can save domain-specific AttackDetector overrides while common rules remain fallback. |
| WLS-SEC-AUDIT-FILTER-001 | Add native security policy audit filters. | Passed current slice: native Security Policy Audit can filter by action, source, domain, changed section, keyword, and limit; service probes cover scoped domain, wildcard domain, wrong-source rejection, and global common-policy entries visible under scoped domains; i18n CSV validation passed; WLS route reachability on dedicated instance `ai-test-wls-sec-audit-10038` returned root `HTTP 200` and protected Security URL `HTTP 302` with audit query preserved; follow-up browser visual smoke on `ai-test-wls-sec-audit-visual-10039` passed for desktop/mobile and light/dark after fixing the desktop audit-filter select width. |
| WLS-OPS-001 | Add PHP profile plugin hook. | Implemented guarded write/apply/inheritance/extension adapter slice: `Weline_PhpManager` exposes WLS typed meta, is discoverable through the same `appstore.installedModules` query path, contributes a WLS menu entry, opens a standalone PHP profile shell, reads runtime PHP state, saves per-project PHP Profiles, previews php.ini drift, applies a managed ini block with backup and rollback, renders recent audit entries, shows runtime/Profile/effective inheritance plus required-extension satisfaction, and routes bundled Windows PHP extension install/remove intent through a guarded php.ini adapter with `RUN_PHP_EXTENSION_ACTION`, sanitized audit, post-verification, rollback guidance, and optional WLS reload. Remaining work is platform adapters beyond bundled Windows PHP. |
| WLS-OPS-002 | Add database profile plugin hook. | Implemented guarded write/apply/import/lifecycle/backup-plan slices: `Weline_DbManager` exposes WLS typed meta, opens a standalone panel shell, reads effective DB profiles, masks credentials, supports guarded connection tests, saves encrypted project-level database Profiles, can explicitly import the selected env password after `COPY_ENV_PASSWORD`, previews and applies persistent `db`/`db.master` plus existing `db.slaves.*` drift with backup and rollback, renders audit entries, previews create database/create user/grant/backup/restore/migration/SQL-apply intent, renders mysql/pgsql lifecycle SQL plans with verification queries and rollback guidance, adds a guarded POST execution boundary for lifecycle create database/create user/grant after `RUN_DB_LIFECYCLE`, adds guarded mysql/pgsql `backup_database` execution after `RUN_DB_BACKUP`, adds guarded MySQL/MariaDB, PostgreSQL custom-format, and PostgreSQL plain SQL public-schema-reset `restore_database` execution after `CHECK_DB_RESTORE` plus `RUN_DB_RESTORE`, with plain SQL additionally requiring `RESET_PG_SCHEMA`, adds read-only migration preflight after `CHECK_DB_MIGRATION` with backup metadata, checksum, driver/database match, readability, target risk classification, and sanitized audit, adds guarded additive SQL Apply after `RUN_DB_SQL_APPLY` with safe artifact confinement, statement allowlist, pre-apply backup, verification query, and sanitized audit, and adds explicit database slave create/remove after `CREATE_DB_SLAVE` / `REMOVE_DB_SLAVE` with backup-first env writes, safe-key validation, sanitized audit, and optional WLS reload. Normal Env Apply still only updates an already selected slave entry. Browser visual smoke for restore preflight passed on dedicated WLS instance `ai-test-wls-db-restore-vis-10026`, migration preflight passed on `ai-test-wls-db-migration-vis-10024`, PostgreSQL reset restore execution form proof passed on `ai-test-wls-db-reset-vis-10034`, and SQL Apply form proof passed on `ai-test-wls-db-sql-apply-10041`; these cover desktop/mobile and light/dark themes with no login fallback, no fatal text, no horizontal overflow, and guarded execution copy/state. MySQL backup success-path proof now passes in task `2026-06-21-1635-wls-dbmanager-mysql-backup-docker-success-harness` against disposable MariaDB 11.4 plus an ephemeral PHP client, verifying seeded data, metadata, checksum, sanitized audit, and cleanup. MySQL/MariaDB lifecycle success-path proof now passes in task `2026-06-21-1658-wls-db-lifecycle-mysql-docker-success-harness`, verifying create database/create user/grant user, target-user create/insert/read behavior, sanitized audit, and cleanup. MySQL/MariaDB restore success-path proof now passes in task `2026-06-21-1714-wls-db-restore-execution-boundary`, verifying source backup, pre-restore backup, restored data, sanitized audit chain, artifact cleanup, database/user cleanup, and container cleanup. PostgreSQL custom-format restore success-path proof now passes in task `2026-06-21-1746-wls-db-postgres-restore-execution`, verifying `.dump` backup, the then-current PostgreSQL plain SQL preflight-only state, `pg_restore` execution, pre-restore backup with mutated data, restored `alpha,beta` rows, sanitized audit chain, artifact cleanup, database/user cleanup, and container cleanup. PostgreSQL plain SQL public-schema-reset restore proof now passes in task `2026-06-21-2017-wls-db-postgres-plain-restore-schema-reset`, verifying `RESET_PG_SCHEMA`, forced custom `.dump` pre-restore backup with mutated `gamma`, restored `alpha,beta` rows, sanitized audit events, and cleanup; its focused reset restore execution form browser evidence is tracked in `2026-06-21-2055-wls-db-postgres-reset-restore-visual-smoke`. Migration execution is now covered by `WLS-DB-MIGRATION-EXEC-001`; remaining work is project-health remediation/deeper probes and future PostgreSQL reset modes beyond public schema. |
| WLS-OPS-003 | Add file manager plugin hook. | Implemented current slice set: `Weline_FileManager` exposes WLS typed meta, contributes a WLS menu entry, opens a standalone path/context shell, supports guarded browse/preview/download/create/save/upload/rename/delete/recursive delete/compress/queued compression, recoverable queued trash restore and permanent purge, path policy editing/reset, audit summary/filter controls, managed child project root switching, safe-text editor ergonomics, an opt-in existing-file `SAVE_SOURCE` source-editing policy, dedicated `SOURCE_CREATE_FILE` single-file source creation, dedicated same-directory `SOURCE_RENAME`, dedicated single-file recoverable `SOURCE_TRASH`, dedicated single-file source trash queue `SOURCE_QUEUE_TRASH`, dedicated read-only single-file source archive queue `SOURCE_QUEUE_ARCHIVE`, dedicated read-only child-directory source archive queue `SOURCE_QUEUE_ARCHIVE_TREE`, and dedicated read-only selected-entry source archive queue `SOURCE_QUEUE_ARCHIVE_SELECTION` for enabled `project`/`local_project`/`app_code` roots without opening source-root upload/delete/purge/overwrite/source-root ZIP/broader queue operations. Remaining work is broader multi-directory, multi-root, or source-write queue policy beyond the implemented trash/archive queue slices. |
| WLS-OPS-005 | Add native Project Config Center. | Dashboard aggregates admin, child panel, security, gateway, PHP, DB, files, and deploy actions per project without leaking `project_path`. |

### Stage 3 Implementation Notes

- `WLS-OPS-002` now includes the focused SQL Apply execution proof from task
  `2026-06-22-0415-wls-db-sql-apply-guarded-adapter`: a disposable MariaDB
  11.4 harness created a real pre-apply backup, applied 3 additive DDL
  statements through PDO, verified row readback, wrote sanitized
  `backup_executed` / `sql_apply_executed` audit events, and removed its
  Docker container/network.

- `WLS-OPS-002` now also includes the guarded migration execution proof from
  task `2026-06-22-0504-wls-db-migration-execution-guarded-adapter`: the
  MySQL/MariaDB import gate requires `CHECK_DB_MIGRATION` and
  `RUN_DB_MIGRATION`, replays preflight, creates a fresh pre-migration backup,
  imports only a confined Database Manager `.sql` / `.sql.gz` artifact through
  the MySQL client, verifies `SELECT 1`, writes sanitized
  `migration_executed` audit evidence, passes disposable MariaDB 11.4 execution
  proof, and renders correctly in desktop/mobile light/dark Browser smoke.

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
- `WLS-SEC-AUDIT-FILTER-001` adds first-class Security Policy Audit filters:
  the native panel now accepts `policy_audit_action`,
  `policy_audit_source`, `policy_audit_domain`, `policy_audit_section`,
  `policy_audit_keyword`, and `policy_audit_limit`. The service normalizes
  those fields, caps the limit, treats `*` as all domains, keeps common
  global-policy audit entries visible when a project domain is selected, and
  filters the JSONL history before rendering.
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
- `WLS-PHP-EXT-001` started as a dry-run slice and is now followed by
  `WLS-PHP-EXT-ADAPTER-001`: `WlsPhpExtensionPlanService` normalizes
  install/remove intent from safe request parameters, compares the selected
  extension with current runtime state, Project Profile required extensions,
  and a core-extension protection list, then enables execution only when a
  guarded adapter reports a supported state. The current adapter is limited to
  bundled Windows PHP extensions that already exist under
  `extend/server/php/ext`; package-manager, PECL, download, and arbitrary
  extension flows remain blocked until separate guarded adapters exist.
- `WLS-PHP-I18N-001` now has its first text-polish slice:
  `Weline_PhpManager` keeps the same typed WLS plugin behavior while
  `zh_Hans_CN.csv` covers the standalone PHP Manager shell labels, project
  context, runtime/Profile/php.ini/extension sections, audit labels, theme
  labels, and typed WLS marketplace note. The matching `en_US.csv` source entry
  was added for the previously missing marketplace note key.
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
- `WLS-DB-ADAPTER-001` now adds the first vendor-aware database lifecycle
  adapter boundary: `WlsDatabaseLifecycleSqlPlanAdapter` revalidates safe
  mysql/pgsql lifecycle input, renders preview-only SQL statements,
  verification queries, rollback/cleanup guidance, the
  `db.lifecycle.plan_ready` audit event, and the `RUN_DB_LIFECYCLE`
  confirmation phrase into the Database Manager panel. Execution remains
  disabled until a future adapter adds ACL-confirmed POST execution, live DB
  connection handling, audit append, post-verification, and rollback gates.
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
| WLS-OPS-001 | Add PHP profile plugin hook. | Implemented guarded write/apply/inheritance/extension adapter slice: `Weline_PhpManager` exposes WLS typed meta, is discoverable through the same `appstore.installedModules` query path, contributes a WLS menu entry, opens a standalone PHP profile shell, reads runtime PHP state, saves per-project PHP Profiles, previews php.ini drift, applies a managed ini block with backup and rollback, renders recent audit entries, supports dark/light and mobile layouts, shows a PHP Profile inheritance map for runtime/Profile/effective values plus required-extension satisfaction, and now routes bundled Windows PHP extension install/remove intent through a guarded php.ini adapter. The adapter only touches PHP Manager's managed extension block, requires `RUN_PHP_EXTENSION_ACTION`, writes sanitized audit, exposes post-verification and rollback guidance, and can optionally request WLS reload. Remaining work is platform adapters beyond bundled Windows PHP. |
| WLS-OPS-002 | Add database profile plugin hook. | Implemented guarded write/apply/import/lifecycle/backup-plan slices: `Weline_DbManager` exposes WLS typed meta, is discoverable through the same `appstore.installedModules` query path, contributes a WLS menu entry, opens a standalone DB profile shell, reads `Env::getDbConfig()`, masks credentials, supports `master/default/slaves` readout, keeps project context, provides a POST-only sanitized connection test, saves per-project DB Profiles, encrypts password state, supports explicit `COPY_ENV_PASSWORD` env password import, renders recent audit entries, previews persistent `db`/`db.master` and existing `db.slaves.*` env drift, applies `app/etc/env.php` with backup-first writes, supports latest-backup rollback, previews create database/create user/grant user lifecycle intent, renders preview-only mysql/pgsql lifecycle SQL plans with verification and rollback guidance, previews backup/restore/migration/SQL-apply intent with artifact confinement and unsafe restore blocking, adds guarded mysql/pgsql `backup_database` execution with `RUN_DB_BACKUP`, `mysqldump`/`pg_dump`, optional `.sql.gz` compression, artifact metadata, checksum, and sanitized audit, adds guarded MySQL/MariaDB, PostgreSQL custom-format, and PostgreSQL plain SQL public-schema-reset restore execution with `CHECK_DB_RESTORE`, `RUN_DB_RESTORE`, pre-restore backup, `mysql`/`mariadb` streaming, `pg_restore`, or reset+`psql`, verification query, sanitized audit, adds guarded additive SQL Apply with `RUN_DB_SQL_APPLY`, safe `.sql`/`.sql.gz` artifact confinement, pre-apply backup, additive DDL allowlist, verification query, and sanitized audit, and guarded explicit `db.slaves` create/remove forms with `CREATE_DB_SLAVE` / `REMOVE_DB_SLAVE`, backup-first env writes, safe-key validation, and optional WLS reload only from the env/profile path. Restore preflight, migration preflight, and SQL Apply now have focused logged-in browser visual proof across desktop/mobile and light/dark themes. MySQL backup success-path proof now passes against disposable MariaDB 11.4 in task `2026-06-21-1635-wls-dbmanager-mysql-backup-docker-success-harness`; the harness verifies `schema_and_data` dump content, metadata, checksum, `backup_executed` audit, no credential leakage, artifact cleanup, and container cleanup. MySQL/MariaDB lifecycle success-path proof now passes in task `2026-06-21-1658-wls-db-lifecycle-mysql-docker-success-harness`; the harness verifies `create_database`, `create_user`, and `grant_user`, target-user create/insert/read behavior, `lifecycle_executed` audit without password leakage, and database/user/container cleanup. MySQL/MariaDB restore success-path proof now passes in task `2026-06-21-1714-wls-db-restore-execution-boundary`; the harness verifies source backup, pre-restore backup with mutated data, restored `alpha,beta` rows, `restore_executed` audit without password leakage, artifact cleanup, database/user cleanup, and container cleanup. PostgreSQL custom-format restore success-path proof now passes in task `2026-06-21-1746-wls-db-postgres-restore-execution`; the harness verifies `.dump` backup, the then-current PostgreSQL plain SQL preflight-only state, `pg_restore` execution, pre-restore backup with mutated data, restored `alpha,beta` rows, sanitized audit chain, artifact cleanup, database/user cleanup, and container cleanup. PostgreSQL plain SQL public-schema-reset restore proof now passes in task `2026-06-21-2017-wls-db-postgres-plain-restore-schema-reset`; the harness verifies `RESET_PG_SCHEMA`, forced custom `.dump` pre-restore backup with mutated data, restored `alpha,beta` rows, sanitized audit chain, artifact/container cleanup, and the remaining local pg_dump 18 to PG15 plain-SQL compatibility risk. Normal Env Apply remains guarded to existing slave entries; list-level slave add/remove is handled by the explicit Slave Create / Remove section. Migration execution is now covered by `WLS-DB-MIGRATION-EXEC-001`; remaining work is project-health remediation/deeper probes and future PostgreSQL reset modes beyond public schema. |
| WLS-OPS-003 | Add file manager plugin hook. | Implemented current slice set: `Weline_FileManager` exposes WLS typed meta, is discoverable through the same `appstore.installedModules` query path, contributes a WLS menu entry, opens a standalone path/context shell, provides guarded directory browsing, inline text preview, 20 MB capped downloads, directory creation, small text saves, guarded uploads, same-folder rename, file/empty-directory delete, bounded recursive directory delete, bounded ZIP compression, queued large ZIP compression, recoverable queued trash move/restore, ACL-gated write confirmation, JSONL operation logs inside allowlisted roots, native audit summary/filter controls for action/result/root/keyword, project-aware root resolution through `server.wlsPanelProject`, persisted project/domain path policy editing through `var/wls-panel/file-manager-path-policy.json`, and confirmed reset back to default inheritance for the current safe context. Managed project links can switch the active project root to the child project and expose child `project_var`/`project_pub` as controlled write roots while keeping source roots read-only for ordinary operations; saved policies immediately narrow controlled roots before every write action and can separately enable existing-file `SAVE_SOURCE` source editing, dedicated `SOURCE_CREATE_FILE` single-file creation, dedicated same-directory `SOURCE_RENAME` source rename, dedicated single-file recoverable `SOURCE_TRASH` source trash, dedicated single-file source trash queue `SOURCE_QUEUE_TRASH`, dedicated read-only single-file source archive queue `SOURCE_QUEUE_ARCHIVE`, dedicated read-only child-directory source archive queue `SOURCE_QUEUE_ARCHIVE_TREE`, and dedicated read-only selected-entry source archive queue `SOURCE_QUEUE_ARCHIVE_SELECTION` for `project`, `local_project`, or `app_code` roots without opening source-root upload/hard-delete/purge/overwrite/source-root ZIP/broader queue actions. Reset removes the saved context without exposing raw project paths, queued ZIP tasks run through `Weline_Queue` with worker-side root/limit revalidation, queued trash moves target same-root `.wls-trash` with restore based on server-side queue payload, the panel renders a dedicated 30-entry recoverable trash history with restore availability states, queue-created trash entries support `PURGE_TRASH` permanent purge with service-layer `.wls-trash` validation plus `trash_purged_at` history state, non-truncated writable safe-text previews preload the existing guarded `SAVE_TEXT` editor while enabled source-code previews preload the guarded `SAVE_SOURCE` editor, source creation requires the `SOURCE_CREATE_FILE` phrase and a non-existing allowlisted filename inside an existing enabled source directory, source rename requires the `SOURCE_RENAME` phrase, an existing allowlisted source file, a different non-existing allowlisted target name, and the same source directory, source trash requires the `SOURCE_TRASH` phrase, one existing allowlisted source file under 128 KB, and moves it into the same-root `.wls-trash` folder, source trash queue requires `SOURCE_QUEUE_TRASH` and revalidates a `source_trash_entry` payload in the worker, source archive queue requires `SOURCE_QUEUE_ARCHIVE` and revalidates a `source_archive_file` payload before writing a ZIP snapshot under `var/wls-panel/file-manager/source-archives/`, source directory archive queue requires `SOURCE_QUEUE_ARCHIVE_TREE`, accepts one existing child directory under the current enabled source-policy path, caps the worker at 200 entries and 10 MB, and revalidates `source_archive_tree` before writing the ZIP snapshot under `var/wls-panel/file-manager/source-archives/`, source selection archive queue requires `SOURCE_QUEUE_ARCHIVE_SELECTION`, accepts up to 20 explicitly named direct child files or directories under the current enabled source-policy path, caps the worker at 200 traversed entries and 10 MB, and revalidates `source_archive_selection` before writing the ZIP snapshot under `var/wls-panel/file-manager/source-archives/`, and the safe-text/source editor keeps wrap/font controls, dirty state, line/character/byte/cursor metrics, safe revert, desktop/mobile no-overflow layout, and cache-busted browser evidence. Remaining work is broader multi-directory, multi-root, or source-write queue policy beyond the implemented trash/archive queue slices. |
| WLS-OPS-004 | Define plugin menu contribution shape for WLS operations pages. | Implemented fourth slice plus default-entry normalization: dashboard capability cards resolve PHP, database, file-manager, and deploy slots from installed `module:wls` plugin typed tags; project cards deep-link to those same slots with safe project context; AppStore query output carries raw marketplace meta, capabilities, and panel entry fields; installed plugins can declare `wls_panel.menu[]` entries that render in the standalone panel sidebar and dashboard contribution section; the first valid menu entry is also exposed as the default plugin entry for installed-plugin cards and operation buttons. |
| WLS-OPS-005 | Add native Project Config Center. | Implemented second slice: dashboard now renders a responsive project-scoped configuration center with safe context labels, separate Attack Logs and Security Policy links, Gateway links, and PHP/Database/Files/Deploy scoped editor entries. Installed plugins open their owned writable pages with `operation`, `project_id`, `domain`, and `project_type`; missing plugins still open the filtered `module:wls` marketplace flow. Browser validation confirmed desktop/mobile no-overflow and no `project_path` leakage. The legacy framework-backend marketplace URL `server/backend/panel-marketplace` now has a read-only compatibility redirect into `server/backend/wls-panel/marketplace`, preserving only safe marketplace filters such as `tag` and `panel_auto_refresh` and stripping `project_path` / `return_url`. |

- `WLS-OPS-002` SQL Apply now has both logged-in panel visual proof and
  disposable MariaDB 11.4 execution proof. The execution harness verifies
  pre-apply backup metadata, 3 additive DDL statements through PDO, row
  readback, sanitized `backup_executed` / `sql_apply_executed` audit events,
  and Docker cleanup in task
  `2026-06-22-0415-wls-db-sql-apply-guarded-adapter`.

## Stage 4 - Marketplace Typed Tags

| ID | Task | Validation |
| --- | --- | --- |
| WLS-TAG-001 | Store typed meta tags in module metadata. | `MarketplaceMetaReaderTest::testStrictPackageMetaAcceptsTypedColonTags` passes for `module:wls`, `custom:wls-file-manager`, and `system:false`. |
| WLS-TAG-002 | Update Office Site marketplace indexing. | Implemented/located in `Framework-Official\App\weline\app\code\Weline\PlatformAppStore`: `POST /api/v1/platform/module/list` accepts `tag`, `tags`, and `tag_match`; `ModuleCatalogService` normalizes typed tags and requires exact matches, so `module:wls-extra` does not match `module:wls`. |
| WLS-TAG-003 | Update WLS marketplace client. | WLS Panel marketplace forwards `tag=module:wls&surface=backend` into AppStore online and installed-module flows. |
| WLS-TAG-004 | Refresh panel after module install/update. | Implemented native refresh, return-context, auto-refresh, clean-fragment redirect, menu-only entry normalization, and visible refresh-result summary slices: AppStore exposes `w_query('appstore', 'installedModules')` with meta/capability/entry fields; WLS Panel POSTs `plugin-refresh`, refreshes framework registries, route-refreshes discovered WLS plugin modules only, reruns discovery, and returns to Marketplace with a refreshed notice without direct AppStore class coupling. The redirect now carries `panel_plugin_refresh=1`, registry mode/count, route refresh/count, WLS plugin count, and panel contribution count, so the marketplace page can show exactly what changed and what was reloaded. WLS-origin AppStore links carry `wls_panel_return=1`; AppStore install/update success redirects back to the independent WLS Panel marketplace with `panel_auto_refresh=plugins`; the panel auto-submits the existing POST refresh form once and then clears the auto-refresh flag. The WLS Panel redirect helpers append fragments after URL building and redirect through the response object, so the final URL is `...?panel_notice=plugins_refreshed#installed-plugins` instead of leaving a trailing empty query delimiter. Menu-only plugins receive a normalized default entry so refreshed installed cards can immediately open the contributed WLS panel page. |
| WLS-TAG-005 | Add setup fast path for plugin install/update reload. | Implemented: `setup:upgrade --skip-classmap` and `--skip-composer-dump` can bypass the local `composer.phar dump-autoload` child-process hang, while `setup:background-optimize --skip-classmap` now really skips classmap generation. WLS Panel plugin refresh now also uses a panel-local registry path: it refreshes the module list, discovers installed `module:wls` plugins, runs `RegistryUpdateService::updateModuleRegistriesIncremental()` for those plugin modules, route-refreshes the same module list, and only falls back to `updateAllRegistries()` when the incremental registry refresh fails. Validation: Weline_Deploy schema stages exited 0 and printed the fast-mode skip notice; source-level refresh assertions confirm the incremental call is the primary path and global refresh exists only as fallback. |
| WLS-TAG-006 | Harden AppStore online typed-tag card parsing. | Implemented current client slice: the AppStore marketplace index view now flattens `tags`, flat `tags_resolved`, locale-grouped `tags_resolved`, and `marketplace_meta.tags`, normalizes every code through `MarketplaceTag::normalizeCode()`, deduplicates tag badges, supports structured `{type,value}` tag objects, and uses exact token matching for browser-side tag filters. `surface:backend` and `surface.backend` both filter card surfaces as `backend`. Validation: `php -l app\code\Weline\AppStore\view\templates\Backend\Index\index.phtml` passed. Online Office Site E2E remains a separate gate because the expected checkout was not present at `E:\WelineFramework\Framework-Office-Site` in this workspace. |
| WLS-TAG-007 | Re-verify official marketplace typed-tag server contract. | Verified in the actual official checkout at `E:\WelineFramework\Framework-Official\App\weline`: `PlatformAppStore\Service\ModuleCatalogService` already normalizes plain strings, JSON arrays, arrays, and structured `{type,value}` tag objects, while `moduleHasTypedTags()` uses exact normalized token matching for `all` and `any`. `Controller\Api\V1\Platform\Module::postList()` forwards `tag`, `tags`, and `tag_match` into `listPublished()`. Validation passed: PHP lint for `ModuleCatalogService.php` and API controller; `php vendor\phpunit\phpunit\phpunit app\code\Weline\PlatformAppStore\test\Unit\Service\ModuleCatalogServiceTagFilterTest.php` ran 3 tests / 6 assertions and covered `module:wls-extra` not matching `module:wls`. PHPUnit emitted only the existing coverage-mode warning. No official repo code was edited because that checkout marks `app/code/Weline/**` read-only for this work. |

### Stage 4 Implementation Notes

- Client-side typed tag parsing belongs to `Weline\Framework\MarketplaceMeta\MarketplaceTag`.
- AppStore strict install continues to require structured tag entries with source-locale labels.
- AppStore can consume platform responses from `tags`, flat `tags_resolved`, locale-grouped `tags_resolved`, or `marketplace_meta.tags`.
- AppStore marketplace cards now use the same typed-tag normalization rules for
  online result badges and browser-side filtering. Tag filters are exact token
  matches; substring matches such as `module:wls-extra` against `module:wls`
  are not accepted by the client view.
- Online marketplace filtering is backed by the official-site source at
  `E:\WelineFramework\Framework-Official\App\weline\app\code\Weline\PlatformAppStore`.
  `ModuleCatalogService::normalizeTypedTagFilter()` accepts typed strings,
  arrays, JSON arrays, and structured tag objects; `moduleHasTypedTags()` uses
  exact matching for `all`/`any` semantics.
- The official checkout has been re-verified without code edits:
  `ModuleCatalogServiceTagFilterTest` passes 3 tests / 6 assertions, including
  the critical non-match of `module:wls-extra` against `module:wls`. A live
  token-authenticated marketplace API E2E is still a later environment gate,
  not part of this source-contract check.
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
| WLS-DEPLOY-006 | Close the browser-visible project Profile to release-history path. | Completed the WLS Deploy browser-visible bridge: the standalone Deploy panel now renders a `Project Release Path` section that connects project context, Profile state/key, Webhook Replay, Manual Plan, and the scoped release-history anchor in one visible chain. The section exposes stable DOM markers (`data-wls-deploy-release-path`, per-step markers, and scoped history attributes), preserves `project_id`, `domain`, and `project_type` across all WLS Deploy forms and action links, and the release-history section advertises the same `profile_key`/scope/count as the bridge. Long command input values now render with ellipsis instead of visually spilling from text fields. Validation passed with PHP lint, Deploy CSV parse (`en_US=648`, `zh_Hans_CN=648`), Node syntax check, authenticated Edge CDP smoke on the dedicated non-9501 instance `ai-test-wls-deploy-path-10042`, desktop 1440 light and phone 390 dark screenshots, successful `#releases` click-through, no login fallback, no fatal text, no horizontal overflow, no actionable control overflow, and final WLS cleanup/port-closed evidence. |
| WLS-DEPLOY-I18N-001 | Polish WLS Deploy panel visible text. | Completed a focused text-quality slice: manual release, rollback action, execution-plan, preflight-gate, status-summary, and Git error strings in `zh_Hans_CN.csv` now render Chinese values instead of English fallbacks; rollback progress source strings in `DeployOrchestratorService::rollback()` were repaired from mojibake keys to valid Chinese keys with intact `%{1}` placeholders, and `en_US.csv` now maps those repaired keys to English. Validation: PHP lint passed for `DeployOrchestratorService.php`; both Deploy CSV files parsed with `fgetcsv()`; targeted untranslated-key probe returned no rows; precise rollback mojibake probe returned no rows; `git diff --check` passed for the touched Deploy files. No route refresh or browser smoke was required because this slice did not add routes, templates, forms, CSS, or runtime release behavior. |

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
- The WLS Deploy panel text layer now has a focused Chinese polish pass for
  manual release planning/execution, rollback action, execution gates, status
  summary, and Git errors. The rollback progress log source keys were repaired
  from mojibake to valid Chinese keys, with `en_US.csv` and
  `zh_Hans_CN.csv` kept aligned for the repaired strings.
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
- FileManager memory pressure has now been profiled under plugin-heavy panel
  sessions with 512M workers. Panel-mode test/prod runs should still use at
  least `--worker-memory-limit=512M`, matching the validated full-panel E2E
  baseline.
- Remaining runtime work is no longer basic UI viability; it is worker-exit
  observability/root-cause analysis for repeated self-healed replacements during
  longer plugin-heavy route/theme sessions.

## Parallel Agent Backlog

These are the remaining work packets that should be assigned to separate
agents. Each packet is intentionally small enough to validate independently
before merging back into the full WLS Panel goal.

| Packet | Suggested agent lane | Scope boundary | Primary files | Validation gate |
| --- | --- | --- | --- | --- |
| WLS-GW-PERF-001 | WLS runtime / process | Completed supported-runner direct-listen proof without production code changes. Windows remains a correct negative capability host, but the disposable Linux PHP 8.4.22 Docker runner reports `supports_reuse_port=true` and exercises the direct topology. | Task `2026-06-22-0729-wls-direct-listen-supported-runner-proof`; `artifacts/Dockerfile.direct-listen-proof`; `artifacts/runtime-capability-probe.php`; `artifacts/health-distribution-probe.php` | Passed: direct mode `ai-test-wls-direct-docker-10045` ran two HTTP workers on shared public port `10045`; dispatcher mode `ai-test-wls-dispatcher-docker-10046` ran dispatcher `10046` plus workers `27608/27609`; `/_wls/health?detail=1` probes had zero failures and balanced worker hits; high-concurrency run showed direct `3000/3000`, `360.89 QPS`, workers `1484/1516`, versus dispatcher `3000/3000`, `290.96 QPS`, workers `1500/1500`; `server:stop`, Docker removal, and port `10045/10046` closure all passed. |
| WLS-GW-TARGET-002 | WLS runtime / E2E | Completed ambiguous Gateway apply verification with two live Gateway-enabled targets and a browser-form POST through Gateway Settings. | `WlsPanelGatewaySettingsService`, `WlsPanelProjectRegistryService`, WLS Panel template | Passed: `WlsPanelGatewaySettingsServiceTest` (`OK (2 tests, 10 assertions)`); live A/B Gateway instances on ports `9986`/`9988`; B-only `proxyApply()` returned `gateways=1`; B served `HTTP/1.1 200 OK` with `X-WLS-Mock: selected-b`; A failed the same SNI handshake; browser-form proof on dedicated host `9991` selected B `9994`, rendered `网关路由已应用。`, B served `X-WLS-Mock: ui-selected-b`, A failed the same SNI handshake, and cleanup left no matching PHP process or listening port. |
| WLS-PHP-EXT-001 | PHP ops plugin | Implemented first dry-run slice: the PHP Manager extension section accepts install/remove intent, validates safe extension names, blocks invalid/core/remove-missing cases, warns on profile-required removal, renders future adapter steps, and keeps the execution button disabled. No shell command, php.ini extension write, install, remove, or reload side effect exists in this slice. | `app/code/Weline/PhpManager`, WLS Panel plugin docs | Passed: PHP lint, i18n CSV parse, service dry-run probes for core-remove block and install dry-run, focused Chrome browser smoke with action disabled, desktop/mobile no-overflow, screenshots under the task artifact directory, and WLS instance cleanup for `ai-test-wls-php-ext-9997`. |
| WLS-DB-LC-001 | DB ops plugin | Implemented first dry-run slice: Database Manager now has a lifecycle plan service and panel section for create database, create user, and grant user intent. It validates driver support, safe identifiers, credential readiness, and host/grant scope while keeping execution disabled until vendor adapters exist. | `app/code/Weline/DbManager`, DB profile services/models | Passed: PHP lint, i18n CSV parse, service probes for supported/blocked states, focused browser smoke with action disabled, desktop/mobile no-overflow, screenshots under the task artifact directory, and WLS cleanup evidence for `ai-test-wls-db-lifecycle-9998`. |
| WLS-DB-ADAPTER-001 | DB ops plugin | Implemented first mysql/pgsql lifecycle adapter boundary as preview-only SQL planning. The adapter generates allowlisted create database, create user/role, and grant SQL, verification queries, rollback/cleanup guidance, audit event names, and the disabled `RUN_DB_LIFECYCLE` execution boundary while continuing to avoid DB connections or side effects. | `app/code/Weline/DbManager/Service/Adapter/WlsDatabaseLifecycleSqlPlanAdapter.php`, `WlsDatabaseLifecyclePlanService`, DB Manager template/i18n/docs | Passed: PHP lint, DbManager CSV parse, adapter smoke probes for mysql grant/pgsql create user/unsafe database/mysql missing host, mojibake probe, diff whitespace check, and CDP browser smoke on `ai-test-wls-db-adapter-9992` with desktop/mobile plus light/dark themes. The browser proof confirmed SQL plan rendering, `RUN_DB_LIFECYCLE`, `db.lifecycle.plan_ready`, disabled lifecycle execution button, no fatal text, and no horizontal overflow. |
| WLS-DB-LC-HARNESS-001 | DB ops plugin / environment | Converted the lifecycle success-path proof from local-env blocked to disposable MariaDB coverage without mutating the developer application database. The original readiness probe still records that local PostgreSQL lacks DBA privileges; the new Docker harness exercises the existing execution service against a temporary MariaDB 11.4 container. | `WlsDatabaseLifecycleExecutionService`, `dev/ai/codex/tasks/2026-06-21/2026-06-21-0610-wls-db-lifecycle-harness-readiness`, `dev/ai/codex/tasks/2026-06-21/2026-06-21-1658-wls-db-lifecycle-mysql-docker-success-harness`, DbManager README, panel evidence docs | Partially closed / passed for MySQL/MariaDB: `create_database`, `create_user`, and `grant_user` each executed with verification count 1; the target user created/inserted/read 2 rows; audit recorded `lifecycle_executed` x3 without password leakage; cleanup removed the database, user, and container. PostgreSQL-specific success proof is now closed by `WLS-DB-LIFECYCLE-PG-001`; the older local readiness probe remains useful only as evidence that the developer local PostgreSQL role lacks `createdb`/`createrole`. |
| WLS-DB-BACKUP-002 | DB ops plugin | Implemented first safe planning slice: Database Manager now has a backup/restore/migration dry-run plan service and panel section. It validates driver support, safe database identifiers, safe artifact names, profile source, backup scope, migration target, and future artifact confinement under `var/backups/wls/db-manager/database`; dangerous restore input is blocked and the execution button stays disabled until real adapters add preflight, confirmation phrase, pre-restore backup, audit records, verification probes, and rollback guidance. | `app/code/Weline/DbManager`, `app/code/Weline/Server/doc/wls-panel-plan` | Passed: PHP lint, i18n CSV parse, marketplace meta JSON parse, service probes for backup/restore/migration states, focused browser smoke for desktop dry-run, mobile restore-blocked state, dark theme inheritance, no horizontal overflow, screenshots under the task artifact directory, and WLS cleanup evidence for `ai-test-wls-db-backup-9999`. |
| WLS-FILE-EDIT-001 | File manager plugin | Implemented safe-text edit slices and the source-code edit policy slices: previewed safe text files under existing controlled roots can preload the guarded `SAVE_TEXT` form after root, extension, truncation, and writability checks; enabled source roots can preload existing small source files into a guarded `SAVE_SOURCE` form after source root policy, extension, protected-path, symlink, size, and writability checks; enabled source directories can create one new non-existing allowlisted source file through `SOURCE_CREATE_FILE`, rename one existing allowlisted source file in the same directory through `SOURCE_RENAME`, move one existing allowlisted source file under 128 KB into same-root `.wls-trash` through `SOURCE_TRASH`, queue that same single-file recoverable trash intent through `SOURCE_QUEUE_TRASH`, queue a read-only single-file ZIP snapshot through `SOURCE_QUEUE_ARCHIVE`, queue a read-only one-child-directory ZIP snapshot through `SOURCE_QUEUE_ARCHIVE_TREE`, and queue a read-only selected direct-child ZIP snapshot through `SOURCE_QUEUE_ARCHIVE_SELECTION` into `var/wls-panel/file-manager/source-archives/`. The editor keeps wrap/font controls, dirty state, line/character/byte/cursor metrics, safe revert, and mobile-safe toolbar wrapping without weakening ACL, confirmation phrase, root policy, extension whitelist, protected-path checks, or audit logging. Remaining work is broader multi-directory, multi-root, or source-write queue policy. | `app/code/Weline/FileManager` | PHP lint, worker payload probes, route refresh, and HTTP+queue smoke pass for source-edit/source-create/source-rename/source-trash/source-trash-queue/source-archive-queue/source-archive-tree-queue/source-archive-selection-queue slices; browser desktop/mobile edit smoke from the prior safe-text/source-archive visual slices remains the latest visual baseline until the next WLS runtime smoke refresh with browser tooling available. |
| WLS-FILE-SOURCE-002 | File manager plugin | Implemented executable source-write/source-read queue layers: broader source-tree writes are separated into dedicated layers instead of making source roots ordinary writable roots. The implemented layers are existing-file `SAVE_SOURCE`, single-file `SOURCE_CREATE_FILE`, same-directory source rename with `SOURCE_RENAME`, single-file recoverable source trash with `SOURCE_TRASH`, single-file recoverable source trash queue with `SOURCE_QUEUE_TRASH` / `source_trash_entry` worker revalidation, read-only single-file source archive queue with `SOURCE_QUEUE_ARCHIVE` / `source_archive_file` worker revalidation, read-only one-child-directory source archive queue with `SOURCE_QUEUE_ARCHIVE_TREE` / `source_archive_tree` worker revalidation, and read-only selected direct-child source archive queue with `SOURCE_QUEUE_ARCHIVE_SELECTION` / `source_archive_selection` worker revalidation into the panel-owned var archive directory. Future slices must add dedicated policy flags and phrases for any broader source queue flow. | `app/code/Weline/FileManager`, WLS Panel Plan docs | Route refresh and functional HTTP smoke passed for `source-trash` on `ai-test-wls-file-source-trash-9976`; `SOURCE_QUEUE_TRASH` HTTP+queue smoke passed on `ai-test-wls-file-source-queue-9985`; `SOURCE_QUEUE_ARCHIVE` validation is tracked in the 2026-06-20 source archive queue slice evidence; `SOURCE_QUEUE_ARCHIVE_TREE` HTTP+queue smoke passed on `ai-test-wls-file-source-tree-9988`: queue `77` was created pending, `php bin/w queue:run --id=77` wrote a ZIP under `var/wls-panel/file-manager/source-archives/`, final queue status was `done`, the source directory remained, path policy reset completed, and the archive contained `source/.../Example.php`; `SOURCE_QUEUE_ARCHIVE_SELECTION` validation is tracked in WLS-FILE-SOURCE-003. Browser visual screenshot capture was not rerun for these HTTP-only source queue slices because repository-local Playwright/browser automation was unavailable. |
| WLS-FILE-SOURCE-003 | File manager plugin | Implemented the bounded read-only selected-entry source archive queue: operators can post `SOURCE_QUEUE_ARCHIVE_SELECTION` for up to 20 explicitly named direct child files or directories under the current enabled source-policy directory. The controller resolves entries before queue creation, the worker revalidates `source_archive_selection` root/parent/entry paths, source extensions, protected path segments, symlinks, entry-count and byte limits, and the service writes the ZIP only under `var/wls-panel/file-manager/source-archives/`. Cross-directory selection, multi-root selection, source-root ZIP writes, upload, delete, purge, overwrite, and broad source queue operations remain blocked. | `app/code/Weline/FileManager`, WLS Panel Plan docs, task `2026-06-21-1224-wls-file-source-selection-archive` | Passed: PHP lint for controller/queue/service/template/probe; module-scoped route refresh registered `weline_filemanager/backend/wls-file-manager/source-archive-selection-queue::POST`; service ZIP probe wrote `source-selection-smoke.zip` with the requested file and nested directory entries; dedicated WLS runtime smoke on `ai-test-wls-file-source-selection-10020` returned auth-gate `302 Found` rather than 404/500 for the new POST route; cleanup stopped the instance and final status reported `0/0`. A follow-up full all-module route refresh exited 0 and retained the generated route plus snapshot entry, so the earlier route-refresh miss is not currently reproduced. |
| WLS-PROJECT-CONFIG-002 | Business module / panel | Implemented scoped editor entry slice: Project Config Center now links existing PHP/DB/security/file/deploy writable pages without duplicating their rules, splits Attack Logs from Security Policy, and labels each operation as scoped editor versus marketplace install. | `WlsPanelProjectConfigCenterService`, WLS Panel template, WLS Panel Plan docs | Passed focused browser smoke on `ai-test-wls-project-config-9996`: project card exposed safe context, four operation links, policy/log/gateway actions, dark theme toggle, desktop and 390px no-overflow, and no `project_path` in generated URLs. |
| WLS-PANEL-COMPAT-001 | Business module / panel | Added a backward-compatible old marketplace route so framework backend links that still point at `server/backend/panel-marketplace` enter the standalone WLS Panel marketplace instead of 404. The compatibility route is read-only and allowlists only marketplace filter parameters. | `app/code/Weline/Server/Controller/Backend/PanelMarketplace.php`, generated backend route registry, WLS Panel Plan docs | Passed: PHP lint, forbidden sleep/exit/native dialog scan, targeted `RouteUpdateService->updateRoutes(['Weline_Server'])`, generated route proof for `server/backend/panel-marketplace::GET`, unauthenticated old URL returns backend auth 302 instead of 404, authenticated old URL redirects to `server/backend/wls-panel/marketplace?tag=module%3Awls&panel_auto_refresh=plugins` without `project_path` or `return_url`, followed redirect returns HTTP 200, and dedicated WLS cleanup left instance stopped with port 10040 closed. |
| WLS-PANEL-MEM-001 | QA / runtime | Completed current Windows/Dispatcher plugin-heavy panel validation with FileManager, Deploy, PHP, DB, security, and marketplace loaded together. Evidence also fixed plugin sidebar anchor links that resolved to backend root under the backend base URL. | `app/code/Weline/Deploy/view/templates/Backend/WlsDeploy/index.phtml`, `app/code/Weline/PhpManager/view/templates/Backend/WlsPhpManager/index.phtml`, `app/code/Weline/DbManager/view/templates/Backend/WlsDbManager/index.phtml`, `app/code/Weline/FileManager/view/templates/Backend/WlsFileManager/index.phtml`, task artifacts | Passed on `ai-test-wls-panel-plugin-heavy-9990` with `--worker-memory-limit=512M`: full panel route sweep, desktop/mobile screenshots, theme toggles, anchor regression check, worker memory about 251 MB / 237 MB, `server:stop`, final status stopped, no LISTEN on 9990. |
| WLS-PANEL-MEM-002 | QA / runtime | Profiled the current FileManager/plugin-heavy WLS Panel under the 512M worker baseline after the latest plugin UI and FileManager growth. No production code changed. | `dev/ai/codex/tasks/2026-06-21/2026-06-21-0531-wls-panel-filemanager-memory-profile`, CDP screenshots/results, WLS logs | Passed on `ai-test-wls-panel-memory-9995` with `--worker-memory-limit=512M`: full CDP route sweep covered Dashboard, Marketplace, Security, PHP Manager, DB Manager, File Manager, and Deploy at 1440/390; theme spotcheck covered Dashboard/FileManager/Deploy in light/dark at desktop/mobile; highest observed worker memory after the full sweep was `309.63 MB`; the instance stopped cleanly with no LISTEN on 9995 and no matching PHP process. Observation: during the later theme spotcheck Worker #1 was self-healed from PID `7696` to `27796` after Master saw only one ready worker slot; final state before cleanup was all-running. |
| WLS-PANEL-SOAK-001 | WLS runtime / process | Reproduced the prior worker self-heal observation under a longer plugin-heavy WLS Panel route/theme soak. This is not a panel UI failure: all route/theme CDP checks passed and Master kept the pool available. The remaining scope is runtime root cause and observability for the old Worker exits. | `dev/ai/codex/tasks/2026-06-21/2026-06-21-0558-wls-panel-worker-self-heal-soak`, `app/code/Weline/Server/bin/worker.php`, `app/code/Weline/Server/Service/ServiceOrchestrator.php` | Partial/follow-up required: passed full route CDP pass 1, theme pass 1, full route CDP pass 2, and theme pass 2 on `ai-test-wls-panel-soak-9997` with `--worker-memory-limit=512M`; Worker #2 self-healed `44856 -> 49784` after theme pass 1, Worker #1 self-healed `20376 -> 41780` after theme pass 2; `error-2026-06-21.log` only recorded Master self-check ready-slot recovery; current Worker command lines did include `--memory-limit=512M`; cleanup left stopped status, no LISTEN on 9997, and no matching PHP process. |
| WLS-WORKER-EXIT-001 | WLS runtime / process | Implemented focused child-exit reason observability needed before the next plugin-heavy WLS Panel soak. `draining_complete` can now carry a reason, Worker/SSL/event-worker paths flush exit reasons before exit, Dispatcher reports global drain reason, and Master records shutdown attribution for non-shared children that cannot reply before termination. | `app/code/Weline/Server/bin/worker.php`, `app/code/Weline/Server/bin/worker_ssl.php`, `app/code/Weline/Server/bin/worker_ssl_event.php`, `app/code/Weline/Server/Dispatcher/Dispatcher.php`, `app/code/Weline/Server/IPC/*`, `app/code/Weline/Server/Service/ServiceOrchestrator.php`, task `2026-06-21-0628-wls-worker-exit-reason-observability` | Passed focused validation on `ai-test-wls-exit-reason-kernel-10004` with `--worker-memory-limit=512M`: HTTP returned `200 OK`; stop completed; `wls-startup-trace.log` recorded dispatcher `child_exit_reason` at line `97423` with `dispatcher_global_drain:port=10004` and worker `child_exit_reason` at line `97424` with `shutdown_command:role=worker,instance_id=1,pid=20548`; final status stopped, no LISTEN on 10004, no matching PHP process. GitNexus CLI remained unavailable in this checkout (`gitnexus status` returned `Not a git repository` while `git rev-parse` found the repo root). |
| WLS-PANEL-SOAK-002 | WLS runtime / process | Re-ran the plugin-heavy WLS Panel route/theme soak with the new child-exit observability. The previously unexplained worker replacements are now classified as intentional `memory_pressure_drain` at the 88% threshold of the configured 512M worker limit. | `dev/ai/codex/tasks/2026-06-21/2026-06-21-1516-wls-panel-worker-exit-reason-soak`, `var/log/wls-startup-trace.log`, WLS Panel CDP artifacts | Passed on `ai-test-wls-panel-exit-soak-10005`: full route CDP pass 1, theme pass 1, full route CDP pass 2, and post-replacement theme pass 2 all reported `passed=true`, no login fallback, no fatal hits, and no horizontal overflow. Worker #2 drained `2368 -> 18108` with `memory_pressure_drain:worker=2,memory=476MB,before=476MB,limit=512M,threshold=88%,requests=128`; Worker #1 drained `45652 -> 28596` with `memory_pressure_drain:worker=1,memory=474MB,before=474MB,limit=512M,threshold=88%,requests=165`; cleanup left status stopped, no LISTEN on 10005, and no matching PHP process. |
| WLS-PANEL-MEM-003 | WLS runtime / process | Implemented the first memory-retention optimization after SOAK-002. Workers now compact panel/runtime caches on request intervals, memory drain checks use actual used memory instead of allocated heap only, `/_wls/health` exposes memory/static/object diagnostics, ObjectManager diagnostics avoid internal/deprecated property reads, `WlsRuntime::reset()` clears current fiber output buffers, Phrase Parser skips heavy generated locale dictionary loads under persistent-worker memory pressure, and the DbManager lifecycle empty-plan undefined `$state` warning is removed. | `app/code/Weline/Server/bin/worker.php`, `app/code/Weline/Server/bin/worker_ssl.php`, `app/code/Weline/Server/Service/WorkerResponseMemoryGuard.php`, `app/code/Weline/Framework/Manager/ObjectManager.php`, `app/code/Weline/Framework/Phrase/Parser.php`, `app/code/Weline/Framework/Runtime/WlsRuntime.php`, `app/code/Weline/Admin/Controller/BaseController.php`, `app/code/Weline/DbManager/Service/Adapter/WlsDatabaseLifecycleSqlPlanAdapter.php`, task `2026-06-21-0738-wls-panel-memory-retention-optimization` | Passed on `ai-test-wls-panel-phraseguard-10013` with `--worker-memory-limit=512M`: full route CDP pass 1, theme spotcheck, and full route CDP pass 2 all returned `passed=true` across desktop/mobile, no login fallback, no fatal hits, and no horizontal overflow. `wls-startup-trace.log` had no `memory_pressure_drain` for the 10013 instance; both original workers stayed running after the second pass with health about `429.4 MB` and `392.9 MB` used. Recent php_error filtering showed no Parser `Allowed memory size` fatal and no DbManager `$state` warning. A follow-up `ai-test-wls-panel-diagfix-10014` health request with `objects=1` added `0` bytes to `php_error.log`, proving the diagnostic Deprecated side effect was removed. Both instances were stopped cleanly. |
| WLS-PANEL-MEM-004 | WLS runtime / config | Implemented panel-mode startup memory policy: `wls.panel.enabled`, `wls.panel.mode`, `WLS_PANEL_ENABLED`, or `WLS_PANEL_MODE` enables the panel baseline; when worker/dispatcher memory is still the generic 256M default and no env/CLI memory override is present, `server:start` raises both to 512M. Ordinary WLS instances still default to 256M. | `app/code/Weline/Server/Console/Server/Start.php`, `app/code/Weline/Server/Test/Unit/Console/StartCommandArgsSolidificationTest.php`, `app/etc/env.sample.php`, task `2026-06-21-0930-wls-panel-mode-memory-default` | Passed PHP lint for touched PHP files; focused PHPUnit with `--bootstrap app/bootstrap_phpunit.php` returned `OK (26 tests, 69 assertions)`; short WLS runtime smoke started `ai-test-wls-panel-defaultmem-10015` on port `10015` with `WLS_PANEL_ENABLED=1`, no memory CLI flags, and Dispatcher child command log showed `--memory-limit=512M`; `server:stop` completed and final status reported `鍏ㄩ儴鍋滄 (0/0)`. |
| WLS-PANEL-OBS-001 | WLS runtime / process observability | Added a follow-up worker self-heal observability slice on top of the SOAK-002/MEM-003 findings. Worker status reports and exit reasons can now carry sanitized runtime context (`worker_id`, connections, requests, active requests, memory used/allocated/peak, memory percent, uptime, planned exit reason, IPC drain state), while Master persists `last_status_report` / `last_exit_snapshot` metadata and logs readable diagnostics when self-audit sees missing or stale slots. This does not change lifecycle policy; it improves the next incident evidence. | `app/code/Weline/Server/IPC/ControlMessage.php`, `app/code/Weline/Server/bin/worker.php`, `app/code/Weline/Server/Service/ServiceOrchestrator.php`, task `2026-06-21-1959-wls-worker-self-heal-observability` | Passed: GitNexus impact for indexed IPC factories returned LOW risk; PHP lint passed for `ControlMessage.php`, `ServiceOrchestrator.php`, and `worker.php`; dedicated WLS instance `ai-test-wls-worker-observe-20260621-1959` started on port `10072` with `--no-ssl -c 2 --worker-memory-limit=512M`, health returned `status=healthy` with memory diagnostics, root returned `HTTP/1.1 200 OK`, status showed Master/2 Workers/Dispatcher all running, stop completed, final status showed stopped, final port probe failed to connect, and `var/log/wls-startup-trace.log:98360-98362` recorded dispatcher and both worker `child_exit_reason` rows. Direct IPC factory probes proved context keys are retained while reserved/invalid/nested context fields are filtered. |
| WLS-PANEL-PLUGIN-UI-001 | Panel/plugin UI | Historical shared JS proof. Superseded by the 2026-06-29 native shell direction: WLS Panel no longer loads `wls-panel-plugins.js`, and plugin navigation/theme integration is owned by WLS routes/templates plus embedded plugin display mode. | Historical evidence only; do not use as the current implementation target. | Current target is covered by `97-humanized-redesign-prototype.md`, `98-humanized-redesign-atomic-workplan.md`, and `99-plugin-native-shell-embedding-evidence.md`. |
| WLS-PANEL-REG-002 | QA / browser smoke | Revalidated the current integrated WLS Panel after the shared plugin interaction layer, using the standalone shell plus installed PHP/DB/File/Deploy plugin pages. This is a regression proof only; no production code was changed. | WLS Panel shell, Security page, Marketplace, PhpManager, DbManager, FileManager, Deploy plugin pages, task artifacts | Passed on `ai-test-wls-panel-regression-9994` with `--worker-memory-limit=512M`: CDP route sweep covered Dashboard, Marketplace, Security, PHP Manager, DB Manager, File Manager, and Deploy at 1440px and 390px with no login fallback, no fatal text, no horizontal overflow, and all expected text present. Theme spotcheck covered Dashboard/FileManager/Deploy in light and dark at desktop/mobile. The instance was stopped and final status showed `鍏ㄩ儴鍋滄 (0/0)`. |
| WLS-PANEL-REG-003 | QA / lightweight route smoke | Revalidated the current integrated WLS Panel after later plugin, Deploy, and DbManager slices without touching production code. This is a static/runtime route confidence pass because direct Browser/CDP tooling was not exposed in the session; it does not replace `WLS-PANEL-REG-002` screenshot evidence. | WLS Panel shell, ServerMonitor security rules, Marketplace, PhpManager, DbManager, FileManager, Deploy plugin pages, task `2026-06-21-1209-wls-panel-current-lightweight-regression-smoke` | Passed: PHP lint for the checked panel/plugin templates, `node --check` for `wls-panel-plugins.js`, shared selector contract for all five theme storage keys and plugin theme buttons, forbidden native dialog scan, and `git diff --check`. Dedicated WLS instance `ai-test-wls-panel-light-reg-10019` started on port `10019` with the 512M worker baseline and status `鍏ㄩ儴杩愯涓?(3/3)`. `curl.exe -I` reached the authorization gate with `302 Found` for dashboard, marketplace, security, `weline_phpmanager/backend/wls-php-manager`, `weline_dbmanager/backend/wls-db-manager`, `weline_filemanager/backend/wls-file-manager`, and `deploy/backend/wls-deploy`; final stop/status showed `鍏ㄩ儴鍋滄 (0/0)`. |
| WLS-PANEL-REG-004 | QA / browser visual smoke | Revalidated the current integrated WLS Panel with a real local Chrome CDP profile after the FileManager source-selection archive slice and route-refresh follow-up. This proof updates the lightweight `REG-003` route confidence with screenshot-level browser evidence while avoiding destructive panel submissions. | Dashboard, Marketplace, Security, PHP Manager, DB Manager, FileManager, Deploy, task `2026-06-21-1420-wls-panel-iab-visual-regression` | Passed on `ai-test-wls-panel-iab-10021` with the 512M worker baseline: full browser sweep covered Dashboard, Marketplace, Security, PHP Manager, DB Manager, FileManager, and Deploy at desktop `1440` and phone `390` with `passed=true`, no login fallback, no fatal text, no horizontal overflow, and fitting buttons. Theme spotcheck covered Dashboard, FileManager, and Deploy in light/dark at desktop `1280` and phone `390`; shell/root/body theme attributes matched expected values. Representative screenshots were manually inspected for Dashboard phone dark, FileManager phone dark, and Deploy desktop light. Cleanup stopped the instance; final status showed `0/0`, no LISTEN on `10021`, and no matching PHP process. |
| WLS-PANEL-REG-005 | QA / browser visual smoke | Revalidated the current integrated WLS Panel after explicit Database Manager restore rollback automation. This is a regression proof only; no production code changed in this packet. | Dashboard, Marketplace, Security, PHP Manager, DB Manager, FileManager, Deploy, task `2026-06-22-1240-wls-panel-integrated-rollback-regression` | Passed on `ai-test-wls-panel-reg-rollback-10040` with the 512M worker baseline: Chrome CDP route sweep covered Dashboard, Marketplace, Security, PHP Manager, DB Manager, FileManager, and Deploy at desktop `1440` and phone `390`, with `passed=true`, no login fallback, no fatal text, no horizontal overflow, all expected text present, and fitting buttons. Manual screenshot review focused on DB Manager desktop/phone and Dashboard phone. Cleanup stopped the instance; final `server:status` reported `全部停止 (0/0)` and port `10040` returned `000`. |
| WLS-DEPLOY-UI-002 | Deploy plugin / panel UI | Tightened the standalone WLS Deploy rollback action. The rollback button now renders with real disabled semantics, `aria-disabled`, and a scoped confirmation-gate script; it only becomes submit-capable after the operator checks the rollback confirmation and the server-rendered project context is rollback-ready. | `app/code/Weline/Deploy/view/templates/Backend/WlsDeploy/index.phtml`, tasks `2026-06-21-1750-wls-deploy-rollback-ui-gate` and `2026-06-21-1619-wls-deploy-rollback-gate-browser-smoke` | Passed: PHP lint, UTF-8/source scans, dedicated WLS render on `ai-test-wls-panel-rollback-ui-10016`, rendered HTML assertions for `disabled`, `aria-disabled`, `data-rollback-blocked`, rollback sync script, and manual release gate. The former click-level browser gap is now closed by Chrome CDP on `ai-test-wls-deploy-rollback-cdp-10028`: desktop `1280` and phone `390`, light/dark themes, no login fallback, no fatal text, no horizontal overflow, `data-ready=0`, `data-rollback-blocked=1`, before-click button disabled with `aria-disabled=true`, and after clicking the rollback confirmation checkbox the button remained disabled with `aria-disabled=true`. Screenshots and JSON evidence were saved under the task artifacts directory, and final cleanup status reported `鍏ㄩ儴鍋滄 (0/0)`. |
| WLS-PHP-I18N-001 | PHP ops plugin / i18n | Polished the standalone WLS PHP Manager Chinese text surface without changing PHP runtime/profile behavior. The zh locale now covers shell navigation, project context, runtime cards, php.ini apply/rollback, extension lifecycle dry-run, audit labels, theme labels, and the WLS typed-tag marketplace note; `en_US` also includes the missing source note key. | `app/code/Weline/PhpManager/i18n/en_US.csv`, `app/code/Weline/PhpManager/i18n/zh_Hans_CN.csv`, task `2026-06-21-1029-wls-panel-next-local-slice` | Passed: CSV parse with `zhRows=196`, `enRows=196`, no bad rows, extracted `__()` keys `192`, no missing zh/en keys after BOM normalization, no untranslated target keys; PHP lint passed for PhpManager template/controller/profile service/php.ini service; dedicated WLS render on `ai-test-wls-php-i18n-10017` returned page `200`, `notLoginPage=true`, expected Chinese strings present, English remnants only in translation dictionary JSON, and final cleanup status `鍏ㄩ儴鍋滄 (0/0)`. |
| WLS-PHP-EXT-ADAPTER-001 | PHP ops plugin / extension adapter | Completed the first executable PHP extension adapter after the earlier dry-run slice. Supported Windows bundled PHP plans can enable an existing allowlisted `extend/server/php/ext/php_*.dll` line inside PHP Manager's own managed extension block, or remove only a line previously managed by that block. Execution requires checkbox confirmation plus `RUN_PHP_EXTENSION_ACTION`, rebuilds the plan server-side, creates a backup, writes sanitized audit events, and shows post-verification plus rollback guidance. Unsupported hosts, core-extension removal, package-manager/PECL/download flows, and unmanaged php.ini extension lines remain blocked. | `app/code/Weline/PhpManager/Service/Adapter/WindowsBundledPhpExtensionAdapter.php`, `WlsPhpExtensionPlanService`, `WlsPhpExtensionExecutionService`, `WlsPhpManager` controller/template/i18n/meta/docs, task `2026-06-22-0558-wls-php-extension-guarded-adapter` | Passed: GitNexus impact LOW for `WlsPhpManager` and `WlsPhpExtensionPlanService` with new adapter/execution symbols recorded as index misses; PHP lint passed for touched controller/services/adapter/template; PhpManager i18n parity passed; marketplace meta JSON parse passed with `capability:php-extension-guarded-adapter`; route update added `weline_phpmanager/backend/wls-php-manager/extension-execute::POST`; service probes covered runnable bundled `bz2` install and blocked core removal; sandbox php.ini apply/remove proved backup-first managed-block writes without shell commands; logged-in in-app Browser smoke on port `10043` covered desktop/phone, light/dark, runnable adapter UI, disabled core-remove state, no fatal text, no horizontal overflow, and WLS cleanup with `全部停止 (0/0)` plus port `10043` closed. |
| WLS-PANEL-PLUGIN-UI-002 | Panel/plugin UI | Historical shared JS/hash-navigation proof. Superseded by the 2026-06-29 native shell direction: same-page `location.hash` plugin navigation is no longer accepted for WLS Panel menus. | Historical evidence only; do not reintroduce `wls-panel-plugins.js` or `[data-wls-plugin-nav]` hash behavior. | Current target is WLS-owned parent/child sidebar routes with wrapperless embedded plugin content and plugin-local chrome hidden by `embedded=1`. |
| WLS-DB-BACKUP-EXEC-001 | DB ops plugin | Implemented first real PostgreSQL read-only backup execution adapter for `backup_database` only. It requires an enabled Project Profile, server-side plan rebuild, checkbox confirmation, exact `RUN_DB_BACKUP`, safe `.sql/.dump/.backup` artifact name, `pg_dump` argv execution with password in `PGPASSWORD`, artifact confinement, metadata, checksum, and sanitized audit. Restore, migration, SQL apply, overwrite, and WLS reload side effects remain disabled. | `app/code/Weline/DbManager`, DB backup plan/execution service/controller/template/i18n/docs | Passed: PHP lint, marketplace meta JSON parse, DbManager i18n CSV parse, service probes for PG-ready/restore-disabled/mysql-preview/gzip-preview states, real local PostgreSQL `pg_dump --schema-only` probe created a 2.9MB artifact plus metadata and `backup_executed` event then cleaned it, autoload check passed, dedicated WLS instance `codex-dbmanager-9616` started on port 9616, browser smoke reached the backend login protection for the DbManager route, and the instance was stopped after validation. The logged-in visual gap is now closed by `WLS-DB-BACKUP-VIS-001`; compressed `.sql.gz` support is tracked separately by `WLS-DB-BACKUP-GZ-001`. |
| WLS-DB-BACKUP-GZ-001 | DB ops plugin | Adds compressed PostgreSQL `.sql.gz` backup execution to the existing guarded `backup_database` path. The plan gate now treats `.sql.gz` as executable for enabled PostgreSQL Project Profiles, while execution writes a temporary confined plain SQL dump, streams it through PHP `zlib`, deletes the temporary SQL file, and records final gzip size/checksum/compression metadata. Restore, migration, SQL apply, overwrite, and WLS reload side effects remain disabled. | `app/code/Weline/DbManager` backup plan/execution service/i18n/docs, task `2026-06-21-1351-wls-db-compressed-backup-execution` | Passed: PHP lint for both backup services and the task probe, DbManager i18n CSV parse (`en_US=404`, `zh_Hans_CN=404`), local PostgreSQL compressed-backup probe with `.sql.gz` plan `ready_to_execute`, `can_execute=true`, real `schema_only` dump, gzip-readable artifact, metadata `compression=gzip`, SHA-256 match, `backup_executed` audit event, restore execution still disabled, generated artifact cleanup, and `git diff --check` with CRLF warnings only. |
| WLS-DB-BACKUP-VIS-001 | DB ops plugin / browser smoke | Closed the logged-in DbManager visual-smoke gap for the PostgreSQL-focused backup execution UI without submitting destructive forms. The smoke opens the standalone DbManager shell with `backup_action=backup_database`, verifies the Backup Plan and the then-PostgreSQL-focused backup execution boundary, and keeps execution disabled when no enabled Project Profile is available. | `app/code/Weline/DbManager` backup panel, task `2026-06-21-1337-wls-dbmanager-visual-smoke` | Passed on `ai-test-wls-dbmanager-visual-10022`: local Chrome CDP login covered Backup Plan and Backup Execution sections across desktop `1280` and phone `390`, both light and dark themes. Assertions confirmed no login fallback, shell theme match, `backup_database` action, `RUN_DB_BACKUP` guard, `pg_dump` copy, restore/migration disabled copy, disabled submit state for `can_execute=0`, no fatal text, no horizontal overflow, fitting section buttons, and screenshot evidence. Representative desktop light and phone dark screenshots were manually inspected. |
| WLS-DB-BACKUP-MYSQL-001 | DB ops plugin | Extends the guarded `backup_database` execution boundary from PostgreSQL-only to mysql/pgsql. MySQL plans now become executable for enabled Project Profiles with safe `.sql` or `.sql.gz` artifacts; `.dump`/`.backup` remain PostgreSQL-only. Execution dispatches to `mysqldump` with argv shell bypass, `MYSQL_PWD`, confined artifacts, optional gzip compression, metadata, checksum, sanitized audit, and no restore/migration/WLS reload side effects. The MariaDB client compatibility fix removes the unsupported `--connect-timeout=5` option from the dump argv. | `app/code/Weline/DbManager` backup plan/execution service/controller/template/i18n/docs, tasks `2026-06-21-1425-wls-dbmanager-mysql-backup-execution` and `2026-06-21-1635-wls-dbmanager-mysql-backup-docker-success-harness` | Passed: PHP lint for backup plan/execution service, controller, template, and task probes; marketplace meta JSON parse; DbManager i18n CSV parse (`en_US=432`, `zh_Hans_CN=432`); MySQL backup probe confirmed `.sql` and `.sql.gz` plans are `ready_to_execute`, MySQL `.dump` is blocked, PostgreSQL `.backup` remains executable, failed MySQL execution writes `backup_execute_failed`, removes the partial artifact, and does not leak username/password. Real success-path evidence now also passes with a disposable MariaDB 11.4 container plus ephemeral `php:8.4-cli` client: `schema_and_data` backup succeeded, produced a 2167-byte SQL artifact with seeded `codex_items` rows, wrote metadata with `compression=none`, recorded `backup_executed`, removed artifact/metadata after validation, and removed the temporary container. |
| WLS-DB-RESTORE-PREFLIGHT-001 | DB ops plugin | Adds a read-only `restore_database` preflight boundary without enabling destructive restore. Restore plans can become `ready_to_preflight` for enabled mysql/pgsql Project Profiles with safe artifact names. The panel renders a separate `Run Restore Preflight` form guarded by `CHECK_DB_RESTORE`; the service resolves the existing artifact inside `var/backups/wls/db-manager/database`, requires adjacent Database Manager metadata, recalculates checksum/size, verifies driver/database/artifact match, checks readability, and writes sanitized `restore_preflight_passed` or `restore_preflight_failed` audit events. No restore command, SQL apply, migration apply, env write, overwrite, pre-restore backup, verification query, rollback, or WLS reload is enabled. | `app/code/Weline/DbManager` restore preflight service/backup plan/controller/template/i18n/docs, tasks `2026-06-21-1453-wls-db-restore-preflight-boundary` and `2026-06-21-1607-wls-db-restore-preflight-visual-smoke` | Passed: PHP lint for restore preflight service, backup plan service, controller, template, and task probe; DbManager i18n CSV parse (`en_US=476`, `zh_Hans_CN=476`); service probe created confined temporary artifacts and proved `ready_to_preflight`, successful metadata/checksum/readability preflight, confirmation phrase failure, checksum mismatch failure, missing metadata failure, sanitized audit event names, and cleanup of generated probe artifacts. Focused Chrome CDP browser smoke passed on `ai-test-wls-db-restore-vis-10026`, covering Backup Plan and Restore Preflight sections across desktop/mobile and light/dark themes with `CHECK_DB_RESTORE`, guarded disabled execution, expected restore artifact field, no login fallback, no fatal text, no horizontal overflow, fitting buttons, screenshot evidence, and dedicated-instance cleanup with final status `鍏ㄩ儴鍋滄 (0/0)`. |
| WLS-DB-MIGRATION-PREFLIGHT-001 | DB ops plugin | Adds a read-only `migration_dry_run` preflight boundary without enabling migration execution. Migration plans can become `ready_to_migration_preflight` for enabled mysql/pgsql Project Profiles with a safe target reference and verified backup artifact evidence. The panel renders a separate `Run Migration Preflight` form guarded by `CHECK_DB_MIGRATION`; the service validates Project Profile readiness, backup metadata, checksum/size, driver/database/artifact match, readability, and migration target risk classification while blocking destructive target keywords. No migration runner, schema diff execution, SQL apply, restore, env write, rollback, cleanup automation, or WLS reload is enabled. | `app/code/Weline/DbManager` migration preflight service/backup plan/controller/template/i18n/docs, task `2026-06-21-1524-wls-db-migration-preflight-boundary` | Passed: PHP lint for migration preflight service, backup plan service, controller, template, and task probe; DbManager i18n CSV parse (`en_US=522`, `zh_Hans_CN=522`); marketplace meta JSON parse (`tags=13`, `capabilities=8`); static forbidden-call scans for PHP exits/sleeps and browser native dialogs had no matches; task probe created confined temporary artifacts and proved `ready_to_migration_preflight`, successful metadata/checksum/readability preflight, `release_reference` risk classification, confirmation phrase failure, destructive target blocking, checksum mismatch failure, sanitized audit event names, and cleanup of generated probe artifacts; `git diff --check` reported CRLF warnings only. |
| WLS-PROJECT-CONFIG-003 | Business module / panel | Implemented cross-plugin readiness summary for the native Project Config Center. The panel now emits global ready project/operation-slot counts and each project card has a read-only readiness area for core links, operation slots, security scope, and gateway mode. It preserves the existing scoped-editor links and still avoids raw `project_path` in URLs. | `WlsPanelProjectConfigCenterService`, WLS Panel dashboard template, Server i18n, task `2026-06-22-0002-wls-panel-project-readiness-summary` | Passed: PHP lint, Server i18n CSV parse (`en_US=2526`, `zh_Hans_CN=2526`), forbidden-call scan, dedicated WLS browser smoke on `ai-test-wls-project-readiness-10041` with desktop 1440 light and phone 390 dark screenshots, no horizontal overflow, no control overflow, no fatal text, no `project_path` leakage, and WLS cleanup/port-closed evidence. |

| WLS-DB-RESTORE-EXEC-001 | DB ops plugin | Adds the first guarded destructive `restore_database` execution boundary for MySQL/MariaDB `.sql` and `.sql.gz` artifacts. Restore plans can become `ready_to_restore_execute`; the panel renders a separate `Database Restore Execution Boundary` form guarded by `CHECK_DB_RESTORE` and `RUN_DB_RESTORE`; the service re-runs restore preflight, creates a fresh pre-restore backup through the guarded backup execution service, streams the selected artifact into `mysql`/`mariadb` with shell bypass and `MYSQL_PWD`, verifies the target connection, and writes sanitized `restore_executed` or `restore_execute_failed` audit events. PostgreSQL plain SQL restore execution, migration execution, SQL apply, rollback automation, and WLS reload were intentionally left disabled in this initial slice; rollback automation is now closed separately by `WLS-DB-ROLLBACK-001`. | `app/code/Weline/DbManager` restore execution service/backup plan/controller/template/i18n/docs, task `2026-06-21-1714-wls-db-restore-execution-boundary` | Passed: PHP lint for restore execution service, backup plan service, controller, template, and task probe; Node harness syntax passed; disposable MariaDB 11.4 success harness passed with ephemeral `php:8.4-cli` client plus `mariadb-client`/`pdo_mysql`, source backup plan `ready_to_execute`, restore plan `ready_to_restore_execute`, source backup artifact 2192 bytes, pre-restore backup present with mutated `gamma` data, restored rows `alpha,beta`, verification count 1, audit chain `backup_executed -> restore_preflight_passed -> backup_executed -> restore_executed`, artifact/metadata cleanup, database/user cleanup, and container cleanup. |
| WLS-DB-RESTORE-PG-EXEC-001 | DB ops plugin | Extends the guarded destructive `restore_database` execution boundary to PostgreSQL custom-format `.dump` and `.backup` artifacts. PostgreSQL custom-format restore plans can become `ready_to_restore_execute`; the service re-runs restore preflight, creates a fresh pre-restore backup, invokes `pg_restore --clean --if-exists --no-owner --exit-on-error --single-transaction` with shell bypass and `PGPASSWORD`, verifies the target connection, and writes sanitized `restore_executed` or `restore_execute_failed` audit events. PostgreSQL `.sql` / `.sql.gz` remained preflight-only in this slice and was enabled later by `WLS-DB-PG-PLAIN-RESTORE-RESET-001`. | `app/code/Weline/DbManager` restore execution service/backup plan/template/i18n/meta/docs, task `2026-06-21-1746-wls-db-postgres-restore-execution` | Passed: PHP lint for restore execution service, backup plan service, template, task probe, and validator; Node harness syntax passed; DbManager i18n CSV parse returned `en_US=567`, `zh_Hans_CN=567`; marketplace meta JSON parse returned `tags=14`, `capabilities=9`, `database.restore_execute=guarded-restore-adapters`; disposable PostgreSQL 15 success harness passed with ephemeral `php:8.4-cli` client plus PGDG `postgresql-client-15`/`pdo_pgsql`, source `.dump` backup plan `ready_to_execute`, restore plan `ready_to_restore_execute`, plain SQL restore plan `ready_to_preflight` with execution disabled, source backup artifact 3007 bytes, pre-restore backup present with mutated `gamma` data, restored rows `alpha,beta`, verification count 1, audit chain `backup_executed -> restore_preflight_passed -> backup_executed -> restore_executed`, artifact/metadata cleanup, database/user cleanup, and container cleanup. |
| WLS-DB-PG-PLAIN-RESTORE-CONTRACT | DB ops plugin | Makes the PostgreSQL plain SQL restore gate explicit before destructive execution was enabled. PostgreSQL `.sql` / `.sql.gz` restore plans stayed preflight-only, user-facing copy named the missing safe schema reset adapter, and PostgreSQL `.dump` / `.backup` execution remained unchanged. This row is now the predecessor proof for `WLS-DB-PG-PLAIN-RESTORE-RESET-001`. | `app/code/Weline/DbManager/Service/WlsDatabaseBackupPlanService.php`, DbManager template/i18n/docs, task `2026-06-21-1926-wls-db-pgsql-plain-restore-safety-contract` | Passed: PHP lint for backup plan service, DbManager template, and focused task probe; focused plan probe returned `plain_sql_state=ready_to_preflight`, `plain_sql_can_restore_execute=false`, `dump_state=ready_to_restore_execute`, and `dump_can_restore_execute=true`; DbManager i18n row counts remain aligned at `en_US=562`, `zh_Hans_CN=562`. |
| WLS-DB-PG-PLAIN-RESTORE-DESIGN | DB ops plugin / PostgreSQL restore | Defines the safe schema reset adapter contract required before PostgreSQL plain `.sql` / `.sql.gz` restore execution can move beyond preflight. The design keeps first execution scoped to `public` schema reset, requires a custom-format pre-restore backup, advisory/session safety checks, explicit `RESET_PG_SCHEMA` confirmation, sanitized audit, and isolated PostgreSQL harness proof. | `app/code/Weline/DbManager/doc/postgresql-plain-sql-restore-schema-reset-adapter.md` | Implemented as the first public-schema reset slice in `WLS-DB-PG-PLAIN-RESTORE-RESET-001`; whole-database and explicit schema-list reset modes remain future work. |
| WLS-DB-PG-PLAIN-RESTORE-RESET-001 | DB ops plugin / PostgreSQL restore | Implements guarded PostgreSQL plain SQL restore execution with a public-schema reset adapter. `.sql` / `.sql.gz` restore plans can reach `ready_to_restore_execute` only with `restore_reset_required=true`, `restore_reset_mode=public_schema`, and `restore_reset_confirmation_phrase=RESET_PG_SCHEMA`. Execution re-runs preflight, creates a forced custom `.dump` pre-restore backup, checks active sessions, prepared transactions, advisory locks, and extra user schemas, resets only `public`, streams the artifact through `psql --single-transaction --set=ON_ERROR_STOP=1`, verifies the target connection, and writes sanitized restore audit metadata. | `app/code/Weline/DbManager/Service/WlsDatabaseRestoreExecutionService.php`, `app/code/Weline/DbManager/Service/Adapter/WlsDatabasePostgreSqlPlainRestoreAdapter.php`, `app/code/Weline/DbManager/Service/WlsDatabaseBackupPlanService.php`, DbManager template/i18n/docs, task `2026-06-21-2017-wls-db-postgres-plain-restore-schema-reset` | Passed: GitNexus impact for the new/changed restore symbols returned target-not-found in the current stale index and was recorded as an index miss; PHP lint passed for the backup plan service, restore execution service, new adapter, DbManager template, and harness; plan probe returned `plain_state=ready_to_restore_execute`, `plain_can_restore_execute=true`, `plain_reset_required=true`, `plain_reset_phrase=RESET_PG_SCHEMA`, `custom_state=ready_to_restore_execute`, and `custom_reset_required=false`; disposable PostgreSQL 15 harness passed with a Database Manager-compatible plain `.sql` artifact, restored rows `alpha,beta`, forced pre-restore `.dump` captured mutated `gamma`, audit events were `restore_preflight_passed`, `backup_executed`, and `restore_executed`, and container/artifact cleanup passed. Residual risk: local `pg_dump 18.1` plain SQL output against PG15 emitted `SET transaction_timeout`, so a later compatibility guard should record or compare dump/server major versions for plain SQL artifacts. |

| WLS-DB-LIFECYCLE-PG-001 | DB ops plugin / PostgreSQL lifecycle | Closes the PostgreSQL lifecycle proof gap and fixes the real target-schema grant defect exposed by the disposable harness. PostgreSQL lifecycle statements can now mark schema/table/sequence grants for the target database connection, and `read_write` grants include the schema `CREATE` permission needed for project-owned tables. | `app/code/Weline/DbManager/Service/WlsDatabaseLifecycleExecutionService.php`, `app/code/Weline/DbManager/Service/Adapter/WlsDatabaseLifecycleSqlPlanAdapter.php`, DbManager i18n/docs, task `2026-06-21-1809-wls-db-postgres-lifecycle-harness-proof` | Passed: initial disposable PostgreSQL 15 harness failed on `permission denied for schema public`; after the fix, PHP lint passed for the service/adapter/probe/validator, Node harness syntax passed, PostgreSQL harness passed with `create_database` and `create_user` executing 1 statement each, `grant_user` executing 4 statements, target-role create/insert/read verified, `lifecycle_executed` audit x3 without password leakage, database/role/container cleanup true, MariaDB lifecycle regression harness still passed, and the task validator confirmed `en_US=570`, `zh_Hans_CN=570`, and `grant_executed_count=4`. |
| WLS-DB-SLAVE-MGMT-001 | DB ops plugin | Adds explicit `db.slaves` create/remove flows so list-level slave changes are not hidden inside ordinary Env Apply. The service now builds a sanitized slave plan, creates a new safe-key slave from the enabled Project Database Profile after `CREATE_DB_SLAVE`, removes an existing slave after `REMOVE_DB_SLAVE`, creates an env backup before each write, audits success/failure without credentials, and can optionally request WLS reload. | `app/code/Weline/DbManager/Service/WlsDatabaseEnvApplyService.php`, `app/code/Weline/DbManager/Controller/Backend/WlsDbManager.php`, DbManager template/i18n/docs, tasks `2026-06-21-1826-wls-db-explicit-slave-create-remove` and `2026-06-21-1909-wls-db-slave-management-visual-smoke` | Passed: PHP lint for touched service/controller/template/probe/validator; service probe created a temporary slave, blocked duplicate create, removed the slave, verified two env backups, verified `env_slave_created` and `env_slave_removed`, and confirmed the audit log did not leak the probe password. DbManager i18n CSV parity passed with `en_US=560` and `zh_Hans_CN=560`; route refresh generated `slave-create::POST` and `slave-remove::POST`; dedicated WLS instance `ai-test-wls-db-slave-ui-10023` reached all-running status, root `curl` returned `200 OK`, DbManager GET and both POST operation routes reached the backend auth gate with `302 Found` instead of 404/500. Logged-in browser visual proof now passes on dedicated WLS instance `ai-test-wls-db-slave-vis-10030`: Chrome CDP rendered `#slave-management` inside the standalone DbManager shell at desktop `1280` and phone `390` in light/dark themes, with no login fallback, no fatal text, no horizontal overflow, fitting buttons, and the correct guarded empty state (`data-wdb-slave-can-create="0"`, `data-wdb-slave-count="0"`) when no Project Database Profile or slave entries are configured. Cleanup left status `鍏ㄩ儴鍋滄 (0/0)` and port `10030` closed. |

| WLS-DB-HEALTH-001 | DB ops plugin / health | Adds the read-only Project Health summary to the standalone Database Manager shell. The summary normalizes selected profile, Project Profile, driver runtime, backup directory, env backup, backup/migration/restore plan, and slave profile coverage into ready/attention/blocked counters and suggested actions without opening DB connections, running SQL, applying migrations, restoring data, writing env config, or reloading WLS. | `app/code/Weline/DbManager/Service/WlsDatabaseProjectHealthService.php`, DbManager controller/template/i18n/docs, task `2026-06-22-0535-wls-db-project-health-summary` | Passed: PHP lint for the new service, touched controller, backup-plan service, and template; DbManager i18n CSV parity with `en_US=618` and `zh_Hans_CN=618`; logged-in browser smoke on `ai-test-wls-db-health-10035` rendered `#project-health` across desktop/mobile and light/dark themes with seven health checks, read-only copy, no fatal text, no horizontal overflow, manually reviewed screenshots, and cleanup leaving status `鍏ㄩ儴鍋滄 (0/0)` plus port `10035` closed. |
| WLS-DB-HEALTH-002 | DB ops plugin / health | Adds the first guarded active Project Health connection probe. The shared probe service powers the existing connection test and a new Project Health POST action, requires `CHECK_DB_HEALTH` plus checkbox confirmation, opens a short PDO connection with a 3 second timeout, executes only `SELECT 1`, writes sanitized audit metadata, and never runs migrations, SQL apply, restore, env writes, or WLS reload. | `app/code/Weline/DbManager/Service/WlsDatabaseConnectionProbeService.php`, DbManager controller/template/i18n/meta/docs, task `2026-06-21-2152-wls-db-health-active-probe` | Passed: PHP lint passed for the new service, controller, template, and SQLite probe harness; DbManager i18n/meta parse passed with `en_US=628`, `zh_Hans_CN=628`, no duplicate keys, no malformed rows, `capability:database-health-probe`, and `database.health_probe`; route refresh generated `weline_dbmanager/backend/wls-db-manager/health-probe::POST` mapped to `postHealthProbe`; service smoke returned SQLite `success=true` for `SELECT 1` and blocked incomplete MySQL config; browser smoke on dedicated instance `ai-test-wls-db-health-probe-10036` rendered the standalone Database Manager `#project-health` probe form across desktop and `390px` mobile, light/dark themes, with no login fallback, no fatal text, no horizontal overflow, `CHECK_DB_HEALTH` placeholder/copy, one selectable `Master` profile, and the unconfirmed submit returning the guarded error instead of running a probe. Screenshot and JSON evidence were saved under the task artifacts directory, and cleanup left status `鍏ㄩ儴鍋滄 (0/0)` plus port `10036` closed. |
| WLS-DB-HEALTH-003 | DB ops plugin / browser smoke | Closes the browser-level success-path proof for the guarded Project Health active probe. No production PHP changed in this slice; it validates the existing form and POST action by submitting the `Master` profile with checkbox confirmation and `CHECK_DB_HEALTH` through the standalone Database Manager panel. | DbManager panel runtime, audit log, task `2026-06-21-2228-wls-db-health-browser-success-probe` | Passed: dedicated WLS instance `ai-test-wls-db-health-success-10037` reached `鍏ㄩ儴杩愯涓?(2/2)`, root curl returned `200 OK`, and the direct DbManager route returned the expected backend auth redirect without a browser session. In-app Browser then rendered the standalone DbManager shell, set the confirmation checkbox and phrase, submitted the active probe, and received visible success copy `鏁版嵁搴撳仴搴锋帰娴嬮€氳繃銆俙 with URL `dbm_notice=health_probe_passed`. Desktop and `390px` mobile light/dark checks had no login fallback, no fatal text, no horizontal overflow, and the probe form stayed visible. The last `health_probe` audit event was `success=true`, `connection_key=master`, `driver=pgsql`, `duration_ms=54`, and sanitized fields only (`success`, `profile_key`, `connection_key`, `driver`, `duration_ms`, `message`). Browser result JSON reported `pass=true`, screenshots/audit evidence were saved under the task artifacts directory, and cleanup left status `鍏ㄩ儴鍋滄 (0/0)` plus port `10037` closed. |
| WLS-DB-ROLLBACK-001 | DB ops plugin / restore rollback | Adds explicit restore rollback automation backed only by recent successful `restore_executed` audit records with a valid `pre_restore_artifact`. The service validates artifact confinement, sidecar metadata, byte size, SHA-256, driver, database, current Project Profile, and source connection key before rendering a ready rollback plan; execution requires `ROLLBACK_DB_RESTORE`, replays the normal restore execution gate internally, preserves PostgreSQL plain SQL `RESET_PG_SCHEMA`, and appends sanitized `restore_rollback_executed` / `restore_rollback_failed` audit events. | `app/code/Weline/DbManager/Service/WlsDatabaseRestoreExecutionService.php`, `WlsDbManager` controller/template/i18n/docs, task `2026-06-22-1128-wls-db-restore-rollback-automation` | Passed: GitNexus impact for `WlsDatabaseRestoreExecutionService`, `WlsDatabaseBackupExecutionService`, and `WlsDbManager` returned LOW risk; PHP lint passed for the restore service, controller, template, fixture, and guard probe; DbManager i18n CSV parity passed with `en_US=659` and `zh_Hans_CN=659`; focused guard probe passed empty audit, ready MySQL candidate, disabled Profile block, wrong rollback phrase block, unrecorded artifact block, PostgreSQL reset requirement, PostgreSQL missing-reset block, restore-plan readiness, audit failure events, and fixture cleanup. Logged-in Chrome CDP browser smoke on `ai-test-wls-db-restore-rollback-10038` rendered the ready rollback form for a temporary PostgreSQL Project Profile at desktop `1280` and phone `390` in light/dark themes with `data-wdb-restore-can-rollback=1`, artifact match, `ROLLBACK_DB_RESTORE`, `RESET_PG_SCHEMA`, two confirmation checkboxes, enabled submit button, audit/destructive copy, no login fallback, no fatal text, no horizontal overflow, fitting controls, screenshot/JSON evidence, fixture cleanup, `server:stop`, stopped status, port `10038` closed, and zero remaining `codex-rollback-ui-*` artifacts. |
| WLS-DB-SQL-APPLY-001 | DB ops plugin / SQL apply | Adds explicit guarded SQL Apply for existing reviewed `.sql` / `.sql.gz` artifacts inside the Database Manager backup directory. Plans can reach `ready_to_sql_apply` only for an enabled mysql/pgsql Project Profile and a safe artifact name. Execution requires checkbox confirmation plus `RUN_DB_SQL_APPLY`, re-resolves the artifact inside `var/backups/wls/db-manager/database`, rejects oversized or unsafe SQL, allows only additive `CREATE TABLE`, `CREATE INDEX`, or `ALTER TABLE ADD` statements, creates a fresh schema/data pre-apply backup, applies through PDO, verifies `SELECT 1`, and writes sanitized `sql_apply_executed` / `sql_apply_failed` audit events without raw SQL or credentials. | `app/code/Weline/DbManager/Service/WlsDatabaseSqlApplyExecutionService.php`, `WlsDatabaseBackupPlanService`, `WlsDbManager` controller/template/i18n/meta/docs, task `2026-06-22-0415-wls-db-sql-apply-guarded-adapter` | Passed: GitNexus impact for `WlsDbManager`, `WlsDatabaseBackupPlanService`, and `WlsDatabaseBackupExecutionService` returned LOW risk; PHP lint passed for the new SQL Apply service, backup plan service, controller, template, fixture, guard probe, static validator, and MariaDB harness; `node --check` passed for the CDP smoke script; SQL Apply guard probe passed `ready_to_sql_apply`, confirmation phrase, missing/unsafe artifact blocks, Project Profile requirement, safe artifact checks, additive DDL allowlist, and destructive keyword blocks; DbManager i18n CSV parity passed with `en_US=717` and `zh_Hans_CN=717`; marketplace meta JSON parse passed with `capability:database-sql-apply` and `database.sql_apply`; logged-in Chrome CDP browser smoke on `ai-test-wls-db-sql-apply-10041` rendered the ready SQL Apply form at desktop `1280` and phone `390` in light/dark themes with artifact match, `RUN_DB_SQL_APPLY`, additive allowlist copy, enabled submit button, no login fallback, no fatal text, no horizontal overflow, fitting controls, screenshot/JSON evidence, fixture cleanup, `server:stop`, stopped status, and port `10041` closed; disposable Docker MariaDB 11.4 execution proof created a real pre-apply backup artifact and metadata, applied 3 additive DDL statements through PDO, verified row readback `alpha`, recorded sanitized `backup_executed` and `sql_apply_executed` audit events, and removed the container/network. The browser smoke deliberately did not submit SQL Apply against a production database. |
| WLS-DB-MIGRATION-EXEC-001 | DB ops plugin / migration import | Adds the first guarded migration execution slice after the existing read-only migration preflight. Plans can reach `ready_to_migration_execute` only for an enabled MySQL/MariaDB Project Profile, a safe migration target, and an existing `.sql` / `.sql.gz` Database Manager backup artifact. Execution requires `CHECK_DB_MIGRATION`, `RUN_DB_MIGRATION`, preflight replay, checksum stability, a fresh pre-migration backup, MySQL client import with shell bypass, `SELECT 1` verification, and sanitized `migration_executed` / `migration_execute_failed` audit records. It does not run project-code migrations, schema diff generators, arbitrary SQL, PostgreSQL migration execution, rollback automation, cleanup automation, or WLS reload. | `app/code/Weline/DbManager/Service/WlsDatabaseMigrationExecutionService.php`, `WlsDatabaseMigrationPreflightService`, `WlsDatabaseBackupPlanService`, `WlsDbManager` controller/template/i18n/meta/docs, task `2026-06-22-0504-wls-db-migration-execution-guarded-adapter` | Passed: GitNexus impact returned LOW risk for the touched production symbols; PHP lint passed for the new/touched service/controller/template files and task harnesses; DbManager i18n CSV parity passed with `en_US=781` and `zh_Hans_CN=781`; marketplace meta parse passed with `capability:database-migration-execute` and `database.migration_execute`; guard probe passed 20 assertions including MySQL `ready_to_migration_execute` and PostgreSQL execution disabled; disposable MariaDB 11.4 proof imported a real artifact, created a fresh pre-migration backup, restored rows `alpha,beta`, verified `SELECT 1`, and wrote sanitized `migration_executed` audit; in-app Browser smoke on `ai-test-wls-db-migration-10042` passed desktop `1280x900` light/dark and phone `390x844` dark/light with runnable guarded forms, `CHECK_DB_MIGRATION`, `RUN_DB_MIGRATION`, enabled `执行迁移导入`, no login fallback, no visible fatal text, no console errors, no horizontal overflow, screenshot/JSON evidence, fixture cleanup, WLS cleanup, port `10042` closed, and no remaining `codex-migration-ui-*` artifacts. |
| WLS-PANEL-FINAL-REG-001 | QA / browser visual smoke | Revalidated the current WLS Panel release candidate after the PHP extension guarded adapter slice. This packet did not change production code; it only added task evidence and a local Chrome CDP sweep harness. | Dashboard, Gateway Settings, Marketplace, Security, PHP Manager, DB Manager, FileManager, Deploy, task `2026-06-22-0703-wls-panel-current-release-candidate-browser-sweep` | Passed on `ai-test-wls-panel-rc-10044` with the 512M worker/dispatcher baseline: route precheck reached backend auth redirects for Dashboard, PHP Manager, and Deploy; Chrome CDP covered all eight pages at desktop `1440` light/dark and phone `390` light/dark; final result JSON reported `passed=true`, `consoleIssueCount=0`, no login fallback, no fatal text, no horizontal overflow, expected user-visible page copy present, and fitting buttons/links. The first harness run exposed only over-specific internal expected words for PHP/DB, so the assertion was corrected to visible capability copy before the passing rerun. Cleanup stopped the instance; final status reported `全部停止 (0/0)` and no LISTEN socket remained on port `10044`. |
| WLS-MARKETPLACE-ENDPOINT-OBS-001 | AppStore / marketplace | Adds visible and machine-readable marketplace endpoint observability to WLS Panel and AppStore backend marketplace pages. Local `deploy=dev/local` resolves to `https://app.weline.test:9523`; deployed production mode resolves from `var/deploy/current.json`, which must record `https://app.aiweline.com` for production launch checks. | AppStore resolver/account service/backend template, WLS Panel controller/template, AppStore and Server i18n, task `2026-06-22-2056-wls-appstore-endpoint-observability` | Passed: GitNexus impact LOW risk for `AccountBindService` and `WlsPanel`; PHP lint passed for touched resolver/controller/template files; AppStore focused PHPUnit passed 30 tests and 69 assertions; isolated resolver probe proved local and production branches; Chrome CDP smoke on `ai-test-wls-appstore-endpoint-10052` covered WLS Panel marketplace and AppStore backend marketplace at desktop `1440` and phone `390`, with endpoint/source visible as `https://app.weline.test:9523` / `config:appstore.platform_url`, no login fallback, no fatal text, no target-page console errors, no horizontal overflow, screenshot/JSON evidence, and WLS cleanup with port `10052` closed. |

Concurrency rules:

- Gateway runtime tasks can run in parallel with PHP/DB/File plugin tasks if
  they use distinct non-9501 ports and unique WLS instance names.
- PHP/DB/File plugin tasks must not edit shared WLS Panel shell CSS without a
  UI owner review; they should keep plugin-local styles scoped to their shell.
- DB lifecycle and Deploy release harnesses must not share temporary
  repositories, databases, or project profile keys.
- Every packet must append evidence to this plan directory and record WLS
  cleanup (`server:stop`, process check, env restore when applicable).

Current open validation gaps:

- `direct_listen` is still unavailable on the Windows host and must remain
  capability-gated there, but `WLS-GW-PERF-001` now has positive evidence from
  a disposable Linux PHP 8.4.22 Docker runner with `SO_REUSEPORT=true`.
  Future runtime work should focus on production benchmark tuning, `event`
  extension performance, or business-route throughput rather than basic
  shared-port feasibility.
- File Manager source handling is intentionally bounded; broader multi-root,
  multi-directory, or source-write queue policy needs its own guarded design.
- Typed meta tags are validated locally, the local AppStore client normalizes
  common online response shapes before rendering, and the official
  `PlatformAppStore` source-contract test now proves exact server-side
  `module:wls` filtering. The endpoint-selection slice is now proven: WLS
  Panel and AppStore backend pages visibly select
  `https://app.weline.test:9523` in explicit local `deploy=dev` mode, and an
  isolated deploy-artifact probe selects `https://app.aiweline.com` for
  production. The remaining marketplace gate is a live token-authenticated API
  E2E against the local App Store project at `app.weline.test:9523` after the
  approved DEV-to-App sync, App WLS startup, and local bearer token/account
  setup. A current read-only recheck found the App checkout on branch `dev` at
  `f130551de`, already configured for `deploy=dev`, sqlite, and
  `app.weline.test:9523`, but still not listening on port `9523` and still
  missing DEV's sqlite composite-primary-key `AUTO_INCREMENT` guard.
- Source-code implementation packets can use the restored GitNexus CLI path
  against the refreshed index. The previous `gitnexus status`
  `Not a git repository` result was traced to Node child-process PATH
  resolution: PowerShell could find `git`, but GitNexus' Node process could
  not. With PATH restricted to
  `C:\Program Files\Git\cmd;C:\Windows\System32;C:\Windows;C:\nvm4w\nodejs;C:\Users\17142\AppData\Roaming\npm`
  and the direct CLI entrypoint
  `C:\Users\17142\AppData\Roaming\npm\node_modules\gitnexus\dist\cli\index.js`,
  pure index recovery completed on 2026-06-22 11:16:36 local time. `status`
  now reports indexed/current commit `7eb6dd6`, and
  `impact WlsPanelProjectConfigCenterService -r dev-workspace -d upstream
  --depth 1` returns LOW risk with one direct upstream import from
  `app/code/Weline/Server/Controller/Backend/WlsPanel.php`.
- PHP extension adapters beyond bundled Windows php.ini, project-health
  remediation/deeper probes, PostgreSQL migration execution, and broader
  source-write queue policy remain future guarded work.
- Plugin-heavy WLS Panel UI is viable with 512M workers, but the self-healed
  worker exits observed in longer soaks still need runtime root-cause evidence.
