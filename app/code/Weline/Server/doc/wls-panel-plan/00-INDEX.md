# WLS Panel Plan

This directory is the working plan for turning WLS into an independent server panel.

## Goal

WLS should appear in the framework backend as a single authorized entry. After opening it, the user works inside an independent WLS Panel that can manage local and child WLS projects, gateway rules, PHP/database/path profiles, security rules, attack logs, marketplace plugins, and later tag/webhook based deploy flows.

## Current Navigation Contract

The 2026-06-29 humanized redesign in `97`-`99` is the current WLS Panel interaction contract. It supersedes the earlier shared `wls-panel-plugins.js` and hash-anchor plugin navigation direction recorded in historical evidence files such as `76` and parts of `77`.

- Primary and secondary navigation must use real WLS Panel routes, not `#anchor` state.
- WLS owns plugin parent/child menu rendering in the panel sidebar.
- Plugin pages may render inside a wrapperless WLS iframe host, but must not bring their own visible shell/sidebar/titlebar when `embedded=1`.
- The standalone `view/statics/assets/js/wls-panel-plugins.js` prototype was removed because the current shell no longer loads it; plugin integration is server-route/template driven.

## Files

| File | Purpose |
| --- | --- |
| [10-prototype.md](10-prototype.md) | UI prototype, page map, and responsive shell sketch. |
| [20-plugin-tag-logic.md](20-plugin-tag-logic.md) | Typed meta tag logic for WLS marketplace plugins. |
| [30-atomic-task-plan.md](30-atomic-task-plan.md) | Stage split and atomic implementation tasks. |
| [75-stage-1-panel-shell-e2e-evidence.md](75-stage-1-panel-shell-e2e-evidence.md) | Panel shell, marketplace, project registry, and gateway-sync validation evidence. |
| [76-wls-panel-plugin-ui-normalization-evidence.md](76-wls-panel-plugin-ui-normalization-evidence.md) | Historical shared WLS plugin shell theme/nav normalization evidence; its shared JS/hash-navigation direction is superseded by `97`-`99`. |
| [77-current-integrated-verification-evidence.md](77-current-integrated-verification-evidence.md) | Historical integrated WLS Panel browser smoke with responsive, theme, plugin, route, and cleanup evidence; newer plugin navigation rules are in `97`-`99`. |
| [78-appstore-demo-plugin-install-evidence.md](78-appstore-demo-plugin-install-evidence.md) | Local and production AppStore WLS demo plugin install proof, including typed tags, package hash match, install target, and production HTTPS/WLS reachability. |
| [90-completion-audit-and-next-gates.md](90-completion-audit-and-next-gates.md) | Requirement-level completion audit, remaining gates, and parallel work packets. |
| [92-local-appstore-sync-manifest.md](92-local-appstore-sync-manifest.md) | Scoped execution manifest for the authorized local AppStore `分项` sync, App WLS startup, and typed-tag API gate. |
| [93-official-appstore-manifest-contract.md](93-official-appstore-manifest-contract.md) | Contract for the App Store `official-apps/manifest.json` WLS plugin entries and strict `module:wls-extra` negative canary. |
| [95-final-acceptance-runbook.md](95-final-acceptance-runbook.md) | Executable final acceptance sequence for static checks, WLS browser sweep, local App Store typed-tag API E2E, plugin refresh, Deploy webhook/tag proof, and production App Store launch check. |
| [96-requirement-traceability.md](96-requirement-traceability.md) | Full requirement-to-evidence traceability matrix, including the locked local `app.weline.test:9523` and production `app.aiweline.com` marketplace endpoint policy. |
| [97-humanized-redesign-prototype.md](97-humanized-redesign-prototype.md) | Humanized WLS Panel redesign prototype that replaces anchor/mega-page behavior with native WLS navigation, focused routes, and wrapperless embedded plugin content. |
| [98-humanized-redesign-atomic-workplan.md](98-humanized-redesign-atomic-workplan.md) | Atomic implementation work packets for the humanized redesign, including no-anchor, wrapperless embedding, and parallel ownership boundaries. |
| [99-plugin-native-shell-embedding-evidence.md](99-plugin-native-shell-embedding-evidence.md) | Evidence for WLS-owned plugin parent/child menus, wrapperless iframe host, and persistent `embedded=1` plugin rendering. |

## Tools

| Tool | Purpose |
| --- | --- |
| [tools/marketplace-typed-tag-e2e.php](tools/marketplace-typed-tag-e2e.php) | Credential-safe AppStore typed-tag verifier with `--self-test=1`, local `app.weline.test:9523`, deployed `var/deploy/current.json`, production endpoint-only preflight, and runner-level hard blocks when deploy metadata has a missing environment, unsupported environment, wrong local/production root, production API-path platform URL, production source other than `appstore_platform_url_source=production_default`, or local source outside `local_default` / `env:WELINE_APPSTORE_PLATFORM_URL` / `config:appstore.platform_url`. |
| [tools/local-appstore-readiness-probe.php](tools/local-appstore-readiness-probe.php) | Read-only local App Store readiness probe for the App checkout path and identity (`E:\WelineFramework\Framework-Official\App\weline`, `PlatformAppStore`, and `AppStore`), env WLS endpoint lock (`app.weline.test:9523`), local endpoint, sqlite guard, listener, token precondition, WLS-positive official manifest entries, `module:wls-extra` negative canary, guarded official manifest/source materialize command readiness, and `next_actions` that map blockers to authorization, commands, working directories, and side-effect boundaries. Its `schema_sync` section proves whether the DEV `SchemaMigrationExecutor.php` already has the sqlite composite-primary-key guard, whether the local App checkout still lacks it, which exact allowed sync path is needed, and why setup must run after the authorized scoped sync. The `authorized_app_checkout_sync` action includes the sync-manifest self-test, compact drift preflight, drift+rollback authorization packet, rollback review command, post-sync drift gate, and the same `schema_sync` diagnostic. Use `--action-plan-only=1` for compact blocker/action output. |
| [tools/local-appstore-typed-tag-live-gate.php](tools/local-appstore-typed-tag-live-gate.php) | Guarded local AppStore typed-tag live gate wrapper. Default mode is preflight-only with no network/API call; `--self-test=1` proves the locked `app.weline.test:9523` endpoint, manual endpoint rejection, and `local_insecure_disabled`; `--allow-live=1` executes the local typed-tag runner only after readiness, endpoint source contract, local deploy policy, and local endpoint lock all pass. |
| [tools/production-appstore-typed-tag-live-gate.php](tools/production-appstore-typed-tag-live-gate.php) | Guarded production AppStore typed-tag live gate wrapper. Default/report mode is preflight-only with no network/API call; `--self-test=1` proves production endpoint selection must come from `var/deploy/current.json`, resolve to `app.aiweline.com` with `appstore_platform_url_source=production_default`, reject manual `--endpoint` / `production_insecure_disabled`, and only execute with `--allow-live=1` after token readiness plus a deployed workspace `var/deploy/current.json` source. Fixture deploy-current files remain preflight material and cannot become production live execution proof. |
| [tools/validate-local-appstore-sync-manifest.php](tools/validate-local-appstore-sync-manifest.php) | Manifest guard for the passphrase-gated local AppStore sync: checks App-only target, exact include paths, forbidden paths, local/production marketplace endpoint rules, rejects hard-coded `out_of_scope_fingerprint=<hex>` values in the sync manifest, optional read-only DEV/App file drift with `--with-drift=1`, compact CI drift output with `--drift-summary-only=1`, App checkout rollback/status fingerprinting with `--rollback-review=1`, in-memory parser/guard coverage with `--self-test=1`, and post-sync residual drift failure with `--fail-on-drift=1`. |
| [tools/wls-panel-final-preflight.php](tools/wls-panel-final-preflight.php) | Read-only aggregate gate for the final marketplace slice: runs completion audit, local readiness action plan, App sync drift, sync-manifest self-test, deploy endpoint policy, endpoint source-contract self-test, guarded local/production live-gate wrappers and their self-tests, typed-tag self-test, official manifest self-test/template dry-run/source plan, authorization-pack self-test, final-workorder self-test, deferred-actions validator self-test, goal-completion gate self-test, live evidence validator self-test, live evidence capture self-test, live evidence final-gate self-test, and production endpoint-only resolution; reports top-level and `summary` values for `ready_for_live_local_appstore_e2e` / `goal_complete`, explicit readiness action-chain checks for sync self-test, drift preflight, authorization review, rollback review, post-sync gate, local App checkout identity, and env WLS endpoint lock, plus `local_readiness_app_checkout_identity_ok`, `local_readiness_app_env_wls_endpoint_locked`, `local_live_gate_self_test_passed`, `local_live_gate_guard_passed`, `production_live_gate_self_test_passed`, `production_live_gate_guard_passed`, `production_live_gate_deploy_current_is_deployed_artifact`, `blocked_preflight_no_evidence_files`, `sync_manifest_self_test_passed`, `endpoint_source_contract_self_test_passed`, `endpoint_source_contract_passed`, `official_manifest_self_test_passed`, `authorization_pack_self_test_passed`, `final_workorder_self_test_passed`, `deferred_actions_validator_self_test_passed`, `goal_completion_gate_self_test_passed`, `live_e2e_evidence_validator_self_test_passed`, `live_e2e_capture_self_test_passed`, `live_e2e_final_gate_self_test_passed`, `official_manifest_template_dry_run_passed`, `official_manifest_template_catalog_contract_ok`, `official_manifest_template_would_write`, `official_manifest_source_plan_ready`, and `official_manifest_source_plan_would_write`, and supports `--report-only=1`. |
| [tools/wls-panel-final-workorder.php](tools/wls-panel-final-workorder.php) | Read-only final work order that runs the aggregate preflight and condenses it into a copy-safe operator sequence plus a machine-readable `deferred_action_plan`. It reports `current_state`, `blocked_checks`, `user_authorization_required`, `user_secret_required`, locked local `https://app.weline.test:9523` and deployed `https://app.aiweline.com` marketplace roots, the exact local App checkout `E:\WelineFramework\Framework-Official\App\weline`, the App env WLS endpoint, forbidden `www.*` marketplace roots, `readiness_action_count`, `readiness_action_plan_contract_ok`, `local_readiness_app_checkout_identity_ok`, `local_readiness_app_env_wls_endpoint_locked`, `deferred_action_plan_all_blocked`, the local capture command, production capture command, and final-gate commands. Its deferred plan preserves `authorized_app_checkout_sync`, `run_local_app_setup_after_sync`, `prepare_official_manifest`, `set_local_marketplace_bearer_token`, and `run_live_typed_tag_e2e` without marking them runnable while blockers remain, and now carries `schema_sync` on the sync/setup actions so the final handoff retains the exact DEV/App sqlite guard status. `--self-test=1` proves blocked/ready/complete state mapping, local/production root retention, local checkout/env endpoint gate retention, deferred-action export, blocked-action safety, secret-value non-leakage, final evidence acceptance-contract retention, required `capture_metadata.workorder_authorization_consistency`, and that production capture stays unavailable until launch. |
| [tools/validate-final-workorder-deferred-actions.php](tools/validate-final-workorder-deferred-actions.php) | Read-only validator for the final workorder `deferred_action_plan`, `operator_sequence`, and `acceptance_contract`. It runs the final workorder, then checks `required_actions_ordered`, `blocked_state_all_actions_not_runnable`, `ready_state_only_live_action_runnable`, `acceptance_contract_has_required_invariants`, `local_policy_records_framework_official_app_checkout`, `local_policy_records_app_env_wls_endpoint`, `local_app_checkout_identity_preflight_locked`, `local_app_env_wls_endpoint_preflight_locked`, `sync_requires_user_authorization`, `sync_schema_sync_diagnostic_present`, `setup_schema_sync_diagnostic_present`, `manifest_requires_confirmed_writes`, `token_requires_secret_placeholder`, `start_targets_app_weline_9523`, `live_uses_guarded_local_gate`, `local_capture_operator_step_present`, `local_capture_requires_reviewed_appstore_prerequisites`, `local_final_gate_operator_step_present`, `production_capture_uses_deploy_current`, `production_capture_requires_deployed_app_aiweline`, `production_capture_requires_production_default_source`, and `production_final_gate_operator_step_present`. The acceptance contract must preserve `captured_valid`, final evidence gate readiness, local App checkout identity, local env endpoint lock, production deploy-current endpoint source, automatic deployed `app.aiweline.com` capture, conclusive `module:wls-extra` negative canary, and no-secret evidence invariants. The local capture operator command must use the capture wrapper, write `var\wls-panel-plan\local-appstore-live-e2e.json`, and require verified App checkout identity, App env WLS endpoint lock, reviewed App sync/setup, official manifest/source catalog, `app.weline.test:9523` listener, and out-of-repository bearer token readiness. The production capture operator command must use `--deploy-current=var\deploy\current.json`, write `var\wls-panel-plan\production-appstore-live-e2e.json`, and require deployed `appstore_platform_url=https://app.aiweline.com` with `appstore_platform_url_source=production_default`. `--self-test=1` proves the contract accepts blocked/ready plans and rejects missing actions, secret leakage, `www.aiweline.com` as a production marketplace root, missing schema sync diagnostics, missing acceptance invariants, missing local checkout identity, missing local env endpoint lock, local capture without reviewed prerequisites, missing production capture, and production capture without deployment metadata or accepted source. |
| [tools/wls-panel-workorder-authorization-consistency.php](tools/wls-panel-workorder-authorization-consistency.php) | Read-only consistency gate that cross-checks the aggregate final preflight, compact final workorder, and live E2E authorization packet before any App checkout sync, setup, WLS start, token export, manifest write, or live AppStore call. It requires matching drift fingerprints, locked local and deployed marketplace endpoints, `preflight_local_app_checkout_identity_ok`, `preflight_local_app_env_wls_endpoint_locked`, `workorder_local_app_checkout_identity_ok`, `workorder_local_app_env_wls_endpoint_locked`, `authorization_local_app_checkout_identity_ok`, `authorization_local_app_env_wls_endpoint_locked`, `local_app_checkout_identity_consistent`, `local_app_env_wls_endpoint_consistent`, and `no_secret_values`. `--self-test=1` rejects mismatched fingerprints, `www.aiweline.com` as a marketplace root, missing authorization fingerprint checks, wrong local App checkout identity, missing authorization env endpoint lock, and bearer-value leaks. |
| [tools/wls-panel-goal-completion-gate.php](tools/wls-panel-goal-completion-gate.php) | Strict read-only goal-completion gate. It runs completion audit, final preflight, deferred-action validation, the real workorder/authorization consistency gate, local captured evidence final gate, and production captured evidence final gate, then requires `completion_audit_complete`, `completion_matrix_all_proven`, `traceability_matrix_all_proven`, `final_preflight_goal_complete`, `workorder_authorization_consistency_passed`, `workorder_authorization_consistency_roots_locked`, `workorder_authorization_consistency_local_app_locked`, `workorder_authorization_consistency_no_secret_values`, `local_final_gate_ready`, `production_final_gate_ready`, single-environment final-gate scopes (`environment=local` / `required_environments=["local"]` and `environment=production` / `required_environments=["production"]`), canonical `var/wls-panel-plan/local-appstore-live-e2e.json` / `var/wls-panel-plan/production-appstore-live-e2e.json` evidence paths, `inside_var=true` from both final evidence gates, and distinct local/production evidence files before the WLS Panel goal can be marked complete. Its blocked output reports `workorder_authorization_consistency_not_current`, `local_live_evidence_not_accepted`, and `production_live_evidence_not_accepted` when the live handoff is stale or captured evidence is missing. `--self-test=1` proves it accepts only complete goal evidence and rejects incomplete audits, missing local evidence, missing production evidence, endpoint-policy drift, workorder/authorization consistency drift, `www.aiweline.com` as a production marketplace root, swapped local/production final-gate payloads, local evidence pointing at the production capture path, final-gate evidence outside `var/wls-panel-plan/`, and final-gate payloads scoped to both environments. |
| [tools/wls-panel-live-e2e-authorization-pack.php](tools/wls-panel-live-e2e-authorization-pack.php) | Read-only authorization packet for the final local AppStore live E2E: aggregates readiness, final preflight, sync-manifest drift, local/production deploy endpoint policy, capture wrapper self-test, exact local/production marketplace roots, official manifest catalog summary, deferred execution order, no-secret/no-live-call checks, `local_live_gate_self_test_passed`, `production_live_gate_self_test_passed`, `live_gate_self_test_case_counts_ok`, `capture_path_traversal_guarded`, `capture_consistency_contract_guarded`, `blocked_preflight_no_evidence_files`, and `official_manifest_catalog_contract_ok` before any explicit App sync, manifest write, WLS start, token export, or live API call. `--include-drift-rows=1` expands the sync-manifest result with bounded per-file drift rows for human review; `--include-rollback-review=1` adds the App checkout git-status snapshot, requires `rollback_review_safe_when_requested=true`, preserves an out-of-scope fingerprint for before/after `分项` comparison, and rejects packets when allowed sync paths already have App-local status. Default output stays compact. `--self-test=1` proves no-secret, runnable-step, capture-guard, consistency-contract, rollback-review, and live-gate self-test summary behavior in memory; `--fail-if-unsafe=1` is the CI-safe non-zero gate for unsafe packets. |
| [tools/validate-appstore-live-e2e-evidence.php](tools/validate-appstore-live-e2e-evidence.php) | Read-only local/production live E2E evidence validator. It accepts captured `marketplace-typed-tag-e2e.php` output or guarded live-gate `live_evidence`, enforces the deployment-derived endpoint, `single_tag_module_wls`, `structured_tags_all_match`, conclusive `negative_exact_match_module_wls-extra`, `require_negative_conclusive`, `no_secret_values`, and for capture-wrapper evidence also requires `capture_metadata_present`, matching environment/source gate, exact endpoint, `endpoint_source=deploy-current:*`, inside-var flag, UTC capture timestamp, `capture_metadata.evidence_output_path` matching the actual `--evidence` file, and `capture_metadata.workorder_authorization_consistency` with exact local/deployed roots, endpoints, local App checkout, local env WLS endpoint, and drift fingerprint. |
| [tools/wls-panel-live-e2e-capture.php](tools/wls-panel-live-e2e-capture.php) | Guarded live E2E capture wrapper. Default mode is preflight-only with no write; `--allow-live=1` first runs the workorder/authorization consistency gate, then runs the local or production live gate, writes sanitized evidence under `var\wls-panel-plan\*.json` only after `live_executed=true`, adds `capture_metadata` with endpoint, endpoint source, inside-var flag, normalized evidence output path, and `workorder_authorization_consistency`, validates it with `validate-appstore-live-e2e-evidence.php`, then runs `wls-panel-live-evidence-final-gate.php` so `captured_valid` requires both validator and final gate acceptance. The consistency metadata must preserve exact local App checkout identity and env WLS endpoint through `local_development_checkout`, `local_development_env_wls_endpoint`, `local_app_checkout_identity_consistent`, and `local_app_env_wls_endpoint_consistent`. It segment-normalizes `--evidence-output`; `--self-test=1` must include `path_traversal_outside_var_rejected`, `captured_payload_has_metadata`, `captured_payload_preserves_consistency_contract`, `production_captured_payload_preserves_consistency_contract`, `production_captured_payload_uses_deploy_current_metadata`, `local_final_gate_uses_local_evidence_arg`, and `production_final_gate_uses_production_evidence_arg` so `var\wls-panel-plan\..\leak.json` cannot pass `evidence_output_inside_var` and final evidence cannot omit local or production capture provenance. |
| [tools/wls-panel-live-evidence-final-gate.php](tools/wls-panel-live-evidence-final-gate.php) | Read-only final evidence gate for captured local/production AppStore typed-tag live proofs. It runs after `wls-panel-live-e2e-capture.php`, delegates to `validate-appstore-live-e2e-evidence.php`, requires capture-wrapper provenance instead of raw runner JSON, verifies the environment-specific endpoint and `endpoint_source=deploy-current:*` (`app.weline.test:9523` for local, `app.aiweline.com` from deployed `var/deploy/current.json` for production), requires the validator's `capture_metadata_output_path_present`, `capture_metadata_output_path_matches_file`, `capture_consistency_local_app_identity_locked`, `capture_consistency_local_app_env_endpoint_locked`, `capture_consistency_local_checkout_exact`, `capture_consistency_local_env_wls_endpoint_exact`, and other `capture_consistency_*` checks, rejects custom evidence paths outside the current workspace `var/wls-panel-plan/` directory, supports `--environment=local`, `--environment=production`, and `--environment=both`, and its `--self-test=1` covers `rejects_raw_runner_payload_for_final_gate`, `rejects_missing_capture_metadata`, `rejects_non_deploy_current_endpoint_source`, `rejects_missing_validator_output_path_match`, `rejects_missing_consistency_metadata`, `rejects_consistency_www_aiweline_production_root`, `rejects_consistency_wrong_local_checkout`, `rejects_consistency_missing_env_endpoint_lock`, `rejects_missing_consistency_fingerprint`, `rejects_path_traversal_outside_var`, and `rejects_evidence_path_outside_var` with no network, token, WLS start, or writes. |
| [tools/wls-panel-completion-audit.php](tools/wls-panel-completion-audit.php) | Machine-readable completion gate for `90` / `96` / `95` / `92`; requires every indexed WLS Panel Plan gate/tool file to exist, reports incomplete rows plus a compact `summary` with stable `completion_total` / `completion_proven_count` / `traceability_total` / `traceability_proven_count` aliases, open-row count, and first incomplete requirement, and can fail release gates while any requirement is not `Proven`. |
| [tools/deploy-current-local-development.json](tools/deploy-current-local-development.json) | Non-secret fixture proving local development deployment metadata resolves to `app.weline.test:9523`. |
| [tools/deploy-current-production-default.json](tools/deploy-current-production-default.json) | Non-secret fixture proving production deployment metadata records `appstore_platform_url=https://app.aiweline.com`, `appstore_platform_url_source=production_default`, and resolves to `app.aiweline.com`. |
| [tools/validate-deploy-appstore-endpoint-policy.php](tools/validate-deploy-appstore-endpoint-policy.php) | Read-only deploy current.json policy gate that blocks missing, local, API-path, `www.*`, or wrong `appstore_platform_url_source` marketplace endpoints from production AppStore checks. Production deployment metadata must record `appstore_platform_url_source=production_default`; local metadata must use `local_default`, `env:WELINE_APPSTORE_PLATFORM_URL`, or `config:appstore.platform_url`. |
| [tools/validate-appstore-endpoint-source-contract.php](tools/validate-appstore-endpoint-source-contract.php) | Read-only static source contract gate proving `DeployOrchestratorService`, `AppStorePlatformUrlResolver`, `AccountBindService`, `ModuleInstallerService`, WLS Panel, AppStore backend marketplace, and installed-module pages all share the locked local `app.weline.test:9523` and deployed `app.aiweline.com` marketplace rule. It also proves WLS Panel fallback coverage through `panel_has_locked_fallback_defaults`, `panel_fallback_local_mode_is_explicit_only`, and `panel_fallback_reads_deployed_production_current`, AppStore endpoint/source strip rendering, WLS Panel return-context preservation, and runtime guards that reject known official `www.*` website hosts as marketplace roots. Its real `--self-test=1` mode mutates the contract to prove `www.aiweline.com`, missing endpoint strip, missing WLS return URL/redirect, and installer resolver drift are rejected without network, token, WLS, or write side effects. |
| [tools/validate-official-appstore-manifest-contract.php](tools/validate-official-appstore-manifest-contract.php) | Official AppStore manifest contract checker, template/source-plan emitter, catalog summary reporter, and confirmation-gated materializer for WLS-positive entries, real plugin source directories, and the strict `module:wls-extra` negative canary. |

Authorization packet note: `tools/wls-panel-live-e2e-authorization-pack.php` must expose the local App checkout identity, local App env WLS endpoint lock, exact local `https://app.weline.test:9523` root, exact deployed `https://app.aiweline.com` root, and bounded drift/rollback review data before any sync, setup, WLS start, token export, manifest write, or live AppStore call. If readiness emits `select_local_appstore_checkout`, the packet orders that review step before sync/setup actions; `--self-test=1` includes a checkout-selection ordering case.

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
- Windows remains a negative direct-listen host: PHP 8.4.16 reports
  `PHP_OS_FAMILY=Windows` and no `SO_REUSEPORT` constant, while
  `server:start --topology direct` exits before spawning the test instance.
  The positive supported-runner proof is now complete in task
  `2026-06-22-0729-wls-direct-listen-supported-runner-proof`: Linux PHP 8.4.22
  reports `SO_REUSEPORT=true`, WLS `server:doctor` reports
  `supports_reuse_port=true`, direct mode runs two workers on shared public
  port `10045`, dispatcher comparison uses public port `10046`, and
  high-concurrency health probes show direct `360.89 QPS` versus dispatcher
  `290.96 QPS` with balanced worker hits.

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
- `Weline_FileManager` now includes dedicated source-code queue slices:
  `SOURCE_QUEUE_TRASH`, `SOURCE_QUEUE_ARCHIVE`, and
  `SOURCE_QUEUE_ARCHIVE_TREE`, plus `SOURCE_QUEUE_ARCHIVE_SELECTION`. They
  require the source path policy to be enabled for `project`, `local_project`,
  or `app_code`; trash/archive-file remain limited to one existing allowlisted
  source file under 128 KB, while archive-tree is limited to one existing child
  directory under the current enabled source-policy path and archive-selection
  is limited to up to 20 direct child files or directories explicitly named
  under that same current source-policy directory.
  `SOURCE_QUEUE_TRASH` creates a `source_trash_entry` payload and moves the
  file into same-root `.wls-trash` after worker-side revalidation.
  `SOURCE_QUEUE_ARCHIVE` creates a `source_archive_file` payload and writes a
  read-only ZIP snapshot under
  `var/wls-panel/file-manager/source-archives/`, not beside the source file.
  `SOURCE_QUEUE_ARCHIVE_TREE` creates a `source_archive_tree` payload and
  writes a read-only ZIP snapshot of that one child directory under the same
  panel-owned archive root, capped at 200 entries and 10 MB. The worker
  re-checks root key, root/source path consistency, extension allowlist or
  directory-relative path, protected paths, symlink traversal, operation limits,
  and the archive-root target before executing.
  `SOURCE_QUEUE_ARCHIVE_SELECTION` creates a `source_archive_selection` payload
  and writes a read-only ZIP snapshot of the selected direct children under the
  same panel-owned archive root, capped at 20 selected entries, 200 traversed
  entries, and 10 MB. The worker re-checks root key, parent/source path
  consistency, extension allowlist, protected paths, symlink traversal,
  operation limits, and archive-root target before executing. Multi-root, broad
  multi-directory, upload, delete, purge, overwrite, and source-root ZIP writes
  remain blocked.
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
  satisfaction, and now routes bundled Windows PHP extension install/remove
  intent through a guarded php.ini adapter. The adapter only enables existing
  allowlisted `extend/server/php/ext/php_*.dll` files inside a WLS-managed
  extension block, removes only lines it previously managed, requires
  `RUN_PHP_EXTENSION_ACTION`, writes sanitized audit events, shows
  post-verification and rollback guidance, and can optionally request an
  operator-selected WLS reload.
- The PhpManager standalone shell now has a focused `zh_Hans_CN` i18n polish
  slice: shell navigation, project context, current runtime cards, php.ini
  apply/rollback, extension lifecycle guard labels, audit labels, light/dark labels,
  and the typed WLS marketplace note all have Chinese translations while the
  `en_US` baseline remains aligned.
- `WLS-PANEL-PLUGIN-UI-002` has landed: the shared
  `wls-panel-plugins.js` interaction layer now covers DB/Deploy/PHP/FileManager
  theme toggles, writes all five WLS panel/plugin theme storage keys, keeps
  button label/icon/`aria-pressed` state aligned, applies host `color-scheme`,
  and makes `[data-wls-plugin-nav]` active states follow same-page
  `location.hash`. Dedicated runtime smoke on
  `ai-test-wls-plugin-ui-10018` rendered all four plugin shells with the shared
  script and no login fallback; Playwright screenshot automation remains
  unavailable in the current shell. Evidence is recorded in
  `76-wls-panel-plugin-ui-normalization-evidence.md`.
- `WLS-PANEL-REG-003` has landed as a lightweight current-regression pass after
  later plugin, Deploy, and DbManager slices. It verifies PHP/JS/static selector
  contracts plus dedicated WLS route reachability for Dashboard, Marketplace,
  Security, PHP, DB, File, and Deploy through backend auth-gate `302` responses
  on `ai-test-wls-panel-light-reg-10019`, then confirms cleanup with final
  `全部停止 (0/0)`. It is intentionally not a replacement for the earlier
  screenshot-level `WLS-PANEL-REG-002` browser proof because direct Browser/CDP
  tooling was not exposed in that session.
- `WLS-PANEL-REG-004` now refreshes the integrated panel with real local Chrome
  CDP screenshot evidence after the later plugin/FileManager/route-refresh
  slices: Dashboard, Marketplace, Security, PHP Manager, DbManager, FileManager,
  and Deploy passed desktop/mobile route sweep, and Dashboard/FileManager/Deploy
  passed light/dark theme spotchecks on `ai-test-wls-panel-iab-10021`.
- `WLS-PANEL-REG-005` refreshes the current integrated panel state on
  `ai-test-wls-panel-refresh-10023`: backend auth-gate 302s were confirmed
  before login, browser login opened the standalone Dashboard, Dashboard,
  Security, Marketplace, DbManager, PhpManager, FileManager, and Deploy all
  rendered without fatal text or real horizontal overflow, Dashboard passed
  1440 light/dark plus 390 mobile checks, plugin pages inherited dark theme
  from DbManager into PhpManager, screenshots were saved under
  `var/tmp/wls-panel-refresh/`, and cleanup ended with
  `全部停止 (0/0)`. Evidence is recorded in
  `77-current-integrated-verification-evidence.md`.
- `WLS-PANEL-FINAL-REG-001` refreshes the current release-candidate panel after
  the PHP extension guarded adapter slice: local Chrome CDP covered Dashboard,
  Gateway Settings, Marketplace, Security, PHP Manager, DbManager, FileManager,
  and Deploy across desktop `1440` and phone `390`, both light and dark themes,
  with `passed=true`, `consoleIssueCount=0`, no login fallback, no fatal text,
  no horizontal overflow, fitting buttons/links, screenshot/JSON evidence, WLS
  cleanup, stopped status, and no LISTEN socket on port `10044`.
- The DbManager backup execution UI also has a focused logged-in visual proof:
  task `2026-06-21-1337-wls-dbmanager-visual-smoke` opened the standalone
  DbManager shell, selected a PostgreSQL `backup_database` preview, scrolled to
  the Backup Plan and the then-PostgreSQL-focused backup execution boundary,
  and passed
  desktop/mobile plus light/dark checks for `RUN_DB_BACKUP`, disabled submit
  state, no fatal text, no horizontal overflow, and screenshot evidence on
  `ai-test-wls-dbmanager-visual-10022`.
- The DbManager restore preflight UI now has focused logged-in Chrome CDP
  visual proof: task
  `2026-06-21-1607-wls-db-restore-preflight-visual-smoke` opened the
  standalone DbManager shell on `ai-test-wls-db-restore-vis-10026`, rendered
  Backup Plan and Restore Preflight sections across desktop/mobile and
  light/dark themes, and passed checks for `CHECK_DB_RESTORE`, guarded
  disabled execution, expected restore artifact field, no login fallback, no
  fatal text, no horizontal overflow, and screenshot evidence.
- The DbManager migration preflight UI now also has focused logged-in Chrome
  CDP visual proof: task
  `2026-06-21-1553-wls-db-migration-preflight-visual-smoke` opened the
  standalone DbManager shell on `ai-test-wls-db-migration-vis-10024`, rendered
  Backup Plan and Migration Preflight sections across desktop/mobile and
  light/dark themes, and passed checks for `CHECK_DB_MIGRATION`, guarded
  disabled execution, expected migration target/artifact fields, no login
  fallback, no fatal text, no horizontal overflow, and screenshot evidence.
- The DbManager explicit Slave Create / Remove UI now also has focused
  logged-in Chrome CDP visual proof: task
  `2026-06-21-1909-wls-db-slave-management-visual-smoke` opened the
  standalone DbManager shell on `ai-test-wls-db-slave-vis-10030`, rendered
  `#slave-management` across desktop/mobile and light/dark themes, and passed
  checks for no login fallback, no fatal text, no horizontal overflow, fitting
  buttons, and the correct guarded empty state when no Project Database Profile
  or `db.slaves` entries are configured.
- The DbManager Project Health summary now has focused logged-in browser proof:
  task `2026-06-22-0535-wls-db-project-health-summary` opened the standalone
  DbManager shell on `ai-test-wls-db-health-10035`, rendered
  `#project-health` across desktop/mobile and light/dark themes, and passed
  checks for read-only copy, seven health checks, no fatal text, no horizontal
  overflow, and cleanup with port `10035` closed.
- The DbManager guarded active Project Health probe is now wired and browser
  proven:
  it adds `CHECK_DB_HEALTH` plus checkbox confirmation, a short read-only
  `SELECT 1` probe against Project Profile or env profile, sanitized audit, the
  `capability:database-health-probe` typed tag, and a generated POST route at
  `weline_dbmanager/backend/wls-db-manager/health-probe::POST`. Task
  `2026-06-21-2228-wls-db-health-browser-success-probe` submitted the `Master`
  profile through the standalone panel on `ai-test-wls-db-health-success-10037`,
  received visible success copy, confirmed desktop/mobile light/dark rendering
  without horizontal overflow, verified a sanitized `health_probe` audit event,
  and cleaned up with port `10037` closed.

Remaining Stage 2 work is now optional broader multi-project UX around the
proven control path and any future production benchmark tuning beyond the
current direct-listen supported-runner proof. Remaining Stage 3 hardening is
limited to future platform or breadth adapters beyond the proven release
candidate scope: PHP extension platform adapters beyond the current bundled
Windows php.ini adapter, project-health remediation/deeper probes, PostgreSQL
migration/reset modes beyond the current public-schema restore adapter, and
broader FileManager source-tree write policy beyond the current opt-in
existing-file `SAVE_SOURCE`, single-file `SOURCE_CREATE_FILE`,
same-directory `SOURCE_RENAME`, single-file recoverable `SOURCE_TRASH`,
dedicated single-file `SOURCE_QUEUE_TRASH` / `SOURCE_QUEUE_ARCHIVE`, bounded
read-only child-directory `SOURCE_QUEUE_ARCHIVE_TREE`, and selected direct-child
`SOURCE_QUEUE_ARCHIVE_SELECTION` source queue layers. Guarded SQL Apply and
guarded MySQL/MariaDB migration import are no longer remaining Stage 3 items;
their current release-candidate evidence is recorded in
`77-current-integrated-verification-evidence.md` and
`90-completion-audit-and-next-gates.md`.
The broader FileManager source-tree policy is now split as a staged contract:
source roots remain read-only by default; the current implemented layers only
edit existing small files through `SAVE_SOURCE`, create one new small file
through `SOURCE_CREATE_FILE`, rename one existing file in the same directory
through `SOURCE_RENAME`, move one existing small source file into same-root
`.wls-trash` through `SOURCE_TRASH`, queue that recoverable trash intent
through `SOURCE_QUEUE_TRASH`, queue one read-only file ZIP snapshot through
`SOURCE_QUEUE_ARCHIVE`, or queue one read-only child-directory ZIP snapshot
through `SOURCE_QUEUE_ARCHIVE_TREE`, or queue one read-only selection ZIP
snapshot through `SOURCE_QUEUE_ARCHIVE_SELECTION` into the panel-owned archive
directory under `var/`.
Any broader source queue operation still requires a separate policy flag,
confirmation phrase, worker-side validation, and smoke evidence before it
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
- The standalone Deploy rollback button now mirrors the manual-release UI gate:
  it starts as a real disabled control, keeps `aria-disabled` synchronized, and
  becomes submit-capable only when the selected project Profile is
  rollback-ready and the operator checks the rollback confirmation.
- Focused browser validation has passed for the Deploy shell, including route
  refresh evidence from earlier slices and a Chrome CDP rollback-gate smoke on
  `ai-test-wls-deploy-rollback-cdp-10028`.
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
- The current integrated panel was revalidated again after the shared
  `wls-panel-plugins.js` interaction layer on
  `ai-test-wls-panel-regression-9994` with `--worker-memory-limit=512M`:
  Dashboard, Marketplace, Security, PHP Manager, DB Manager, File Manager, and
  Deploy passed CDP browser smoke at 1440px and 390px with no login fallback,
  no fatal text, no horizontal overflow, and all expected text present. The
  light/dark theme spotcheck also passed for Dashboard, File Manager, and
  Deploy across desktop and mobile. The instance stopped cleanly and final
  status reported `全部停止 (0/0)`. Edge/CDP used `127.0.0.1:9994` because the
  `.weline.test` browser path returned HTTP 502 while curl to the same WLS
  instance remained reachable.
- A current FileManager/plugin-heavy memory profile was then captured on
  `ai-test-wls-panel-memory-9995` with `--worker-memory-limit=512M`:
  the same full CDP route sweep and light/dark theme spotcheck passed against
  `127.0.0.1:9995`; the highest observed worker memory after the full sweep
  was `309.63 MB`; screenshots covered FileManager desktop/phone dark plus
  light-theme samples; cleanup left no LISTEN on 9995 and no matching PHP
  process.
- A longer plugin-heavy soak on `ai-test-wls-panel-soak-9997` reproduced Worker
  replacement twice under the same 512M baseline: after theme pass 1 Worker #2
  changed `44856 -> 49784`, and after theme pass 2 Worker #1 changed
  `20376 -> 41780`. All full route and theme browser checks still passed, and
  cleanup was clean, but `error-2026-06-21.log` only recorded Master self-check
  slot recovery. This is now tracked as `WLS-PANEL-SOAK-001`: panel UI remains
  viable, while WLS runtime needs exit-reason/root-cause observability for the
  replaced workers.
- `WLS-WORKER-EXIT-001` now closes the observability gap needed by the next
  soak. `ai-test-wls-exit-reason-kernel-10004` proved that stop flow records
  both dispatcher `child_exit_reason` (`dispatcher_global_drain:port=10004`)
  and worker `child_exit_reason`
  (`shutdown_command:role=worker,instance_id=1,pid=20548`) in
  `wls-startup-trace.log`, with status stopped, no listener on 10004, and no
  matching PHP process after cleanup.
- `WLS-PANEL-SOAK-002` classified the longer plugin-heavy replacements as
  intentional `memory_pressure_drain` around `474-476MB` at the configured
  512M worker limit. `WLS-PANEL-MEM-003` then added cache compaction,
  actual-used-memory drain checks, Phrase Parser pressure-aware locale loading,
  health diagnostics, and diagnostic-safe object inspection. The follow-up
  soak on `ai-test-wls-panel-phraseguard-10013` passed full route pass 1,
  theme spotcheck, and full route pass 2 without any 10013
  `memory_pressure_drain` record or Parser OOM, and the diagnostic fix was
  proven on `ai-test-wls-panel-diagfix-10014` with no new php_error bytes.
- `WLS-PANEL-MEM-004` solidifies the 512M panel baseline into startup config:
  when panel mode is enabled by `wls.panel.enabled`, `wls.panel.mode`,
  `WLS_PANEL_ENABLED`, or `WLS_PANEL_MODE`, and no explicit worker/dispatcher
  memory is configured, `server:start` raises worker and dispatcher memory to
  512M. Ordinary WLS defaults remain 256M, and explicit env/CLI memory still
  wins.
- `WLS-PANEL-OBS-001` adds the next worker self-heal observability slice:
  worker `status_report` / `exit_reason` IPC payloads can carry sanitized
  runtime snapshots, Master persists `last_status_report` /
  `last_exit_snapshot`, self-audit logs include slot diagnostics, and a
  dedicated WLS smoke on `ai-test-wls-worker-observe-20260621-1959` proved
  healthy runtime, clean stop, closed port `10072`, and
  `wls-startup-trace.log:98360-98362` exit-reason evidence.
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
  create user, and grant user lifecycle intent through a mysql/pgsql SQL plan
  adapter that renders allowlisted SQL, verification queries, rollback
  guidance, and the disabled `RUN_DB_LIFECYCLE` boundary. The first real
  mysql/pgsql `backup_database` execution adapters now run only behind an
  enabled Project Profile, checkbox confirmation, exact `RUN_DB_BACKUP`,
  server-side plan rebuild, driver-specific safe artifact names, confined
  `pg_dump`/`mysqldump` output, metadata, checksum, and sanitized audit
  records. Migration, SQL apply, overwrite, and WLS reload side effects remain
  disabled from that path; `.sql.gz` artifacts are supported by temporary SQL
  dump plus PHP zlib streaming compression. The backup plan
  validates artifact names, confines artifacts to
  `var/backups/wls/db-manager/database`, blocks unsafe restore input, and now
  exposes a read-only restore preflight boundary for `restore_database`.
  Restore preflight verifies existing artifact metadata, checksum, size,
  driver, database, name, and readability after `CHECK_DB_RESTORE`, then writes
  sanitized audit events. MySQL/MariaDB restore execution now has a guarded
  destructive boundary after both `CHECK_DB_RESTORE` and `RUN_DB_RESTORE`: it
  creates a fresh pre-restore backup, streams `.sql`/`.sql.gz` through the
  `mysql`/`mariadb` client with shell bypass, verifies the target connection,
  and records sanitized `restore_executed` audit. The shell also exposes a
  read-only migration preflight boundary for `migration_dry_run`.
  Migration preflight requires `CHECK_DB_MIGRATION`, an enabled Project
  Profile, a safe target reference, and verified Database Manager backup
  metadata; it recalculates checksum/size, checks driver/database/name
  consistency, probes readability, classifies target risk, and blocks
  destructive target keywords before any migration runner exists. Migration
  dry-run remains free of DDL, DML, dump writes, restore writes, and WLS reload
  side effects. Slave writes are restricted to already configured env entries
  and do not create, delete, or reorder replicas. A focused logged-in Chrome CDP
  visual smoke now proves the Backup Plan and Backup Execution Boundary in
  desktop/mobile and light/dark themes. The MySQL success-path harness now
  passes against disposable MariaDB 11.4 in task
  `2026-06-21-1635-wls-dbmanager-mysql-backup-docker-success-harness`; it
  generated a real `schema_and_data` `.sql` artifact, verified seeded table
  data, metadata, checksum, sanitized audit, artifact cleanup, and temporary
  container cleanup. The harness also removed the incompatible
  `--connect-timeout=5` option after MariaDB `mariadb-dump` rejected it.
  MySQL/MariaDB lifecycle success-path proof now also passes in task
  `2026-06-21-1658-wls-db-lifecycle-mysql-docker-success-harness`; it reused
  the existing lifecycle execution service to create a disposable database,
  create a disposable user, grant access, verify target-user create/insert/read
  behavior, record sanitized `lifecycle_executed` audit events, and clean up
  the database, user, and container. PostgreSQL lifecycle success-path proof now
  also passes in task
  `2026-06-21-1809-wls-db-postgres-lifecycle-harness-proof`; it first exposed a
  real `public` schema permission gap, then proved the fixed target-database
  grant path by creating a disposable database, creating a role, granting
  database/schema/table/sequence access, verifying target-role
  create/insert/read behavior, recording sanitized `lifecycle_executed` audit
  events, and cleaning up the database, role, and container.
  MySQL/MariaDB restore execution success-path proof now also passes in task
  `2026-06-21-1714-wls-db-restore-execution-boundary`; it created a real backup,
  mutated the disposable database, verified the pre-restore backup captured the
  mutated `gamma` state, restored the original `alpha,beta` rows, recorded
  `backup_executed -> restore_preflight_passed -> backup_executed -> restore_executed`,
  and cleaned up artifacts, database, user, and container.
  PostgreSQL custom-format restore execution success-path proof now passes in
  task `2026-06-21-1746-wls-db-postgres-restore-execution`; it created a real
  custom `.dump` artifact, verified PostgreSQL plain `.sql` was still
  preflight-only in that earlier slice, restored the original `alpha,beta` rows through
  `pg_restore`, verified the pre-restore backup captured the mutated `gamma`
  state, recorded the same sanitized audit chain, and cleaned up artifacts,
  database, user, and container.
  PostgreSQL plain SQL restore now also has a public-schema reset execution
  proof in task `2026-06-21-2017-wls-db-postgres-plain-restore-schema-reset`:
  `.sql` / `.sql.gz` plans can become `ready_to_restore_execute` only when the
  plan exposes `restore_reset_required=true`, `restore_reset_mode=public_schema`,
  and `restore_reset_confirmation_phrase=RESET_PG_SCHEMA`; the disposable
  PostgreSQL 15 harness restored `alpha,beta`, verified the forced custom
  `.dump` pre-restore backup captured `gamma`, recorded sanitized
  `restore_preflight_passed -> backup_executed -> restore_executed` events, and
  cleaned up the container and generated artifacts. The focused browser proof
  for the reset restore execution form now also passes in task
  `2026-06-21-2055-wls-db-postgres-reset-restore-visual-smoke`, covering
  `CHECK_DB_RESTORE`, `RUN_DB_RESTORE`, `RESET_PG_SCHEMA`, desktop/mobile, and
  light/dark states on dedicated instance `ai-test-wls-db-reset-vis-10034`.
