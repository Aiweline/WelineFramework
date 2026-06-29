# WLS Panel Requirement Traceability

Date: 2026-06-22

This file maps the full WLS Panel discussion into verifiable delivery rows. It
is intentionally stricter than the prototype notes: every user-visible
requirement must either point to current evidence, a final gate, or an explicit
future guarded adapter.

## Environment Endpoint Policy

The marketplace endpoint split is a hard delivery rule:

- Local development uses the local App Store checkout
  `E:\WelineFramework\Framework-Official\App\weline` through
  `https://app.weline.test:9523`.
- Deployed or production verification uses `https://app.aiweline.com`.
- `https://www.weline.test:9518` and `https://www.aiweline.com` are official
  website endpoints and must not be used as WLS marketplace endpoints.
- Deploy writes the selected App Store endpoint into
  `deploy_root/var/deploy/current.json` as `appstore_environment`,
  `appstore_platform_url`, `appstore_platform_url_source`, and
  `deploy_mode_source`.
- Runtime AppStore clients and WLS Panel marketplace pages must resolve the
  endpoint through the same rule: explicit local `deploy=dev/local` keeps the
  local URL; non-local or missing deploy mode must read production deployment
  information that records both `appstore_platform_url=https://app.aiweline.com`
  and `appstore_platform_url_source=production_default`. Runtime code may keep
  a last-resort `https://app.aiweline.com` fallback for non-acceptance runtime
  safety, but production deployment proof, production live gates, and typed-tag
  E2E endpoint resolution require the URL and source to be present in
  `current.json`.
- `appstore_platform_url` must record the platform root only. Deployment
  artifacts that store the full module-list API endpoint in that field fail
  `production_records_exact_app_aiweline_platform_url` for production and
  `local_records_exact_app_weline_platform_url` for local fixtures.
- Non-local resolver paths ignore leftover local `WELINE_APPSTORE_PLATFORM_URL`
  and `appstore.platform_url` values so deployment checks cannot be pulled back
  to `app.weline.test:9523` by stale local configuration.
- The final browser sweep must show the resolved endpoint/source in the WLS
  Panel marketplace and AppStore backend marketplace before an API call is
  attempted.
- The local App Store checkout readiness is checked by
  `tools/local-appstore-readiness-probe.php`. The probe is read-only and reports
  the App checkout path, checkout identity checks
  `app_checkout_is_framework_official_app`,
  `app_checkout_has_platform_appstore_module`, and
  `app_checkout_has_appstore_module`, sqlite guard status,
  `app.weline.test:9523` listener status, local deploy-current metadata,
  `local_deploy_current_matches_probe_endpoint`,
  official `module:wls` manifest source, strict `module:wls-extra` negative
  canary source, and bearer-token environment presence without printing
  secrets. The probe derives its default host/port from
  `tools/deploy-current-local-development.json` and requires
  `app_env_deploy_mode_local=true`,
  `app_env_wls_endpoint_matches_deploy_current=true`, and
  `app_env_wls_endpoint_matches_probe_endpoint=true`; if `app/etc/env.php` is
  not explicitly `deploy=dev/local`, if the App checkout is not
  `E:\WelineFramework\Framework-Official\App\weline`, if the checkout does not
  expose both `PlatformAppStore` and `AppStore`, or if deployment metadata is
  missing, not `local`, not exactly `https://app.weline.test:9523`, uses a
  `www.*` host, or disagrees with the probe endpoint, it emits
  `select_local_appstore_checkout` or
  `fix_local_deploy_current_marketplace_metadata` and blocks the live call.
  It also reports `official_manifest_materialize` with the generated dry-run
  command, authorized write command, target path, and confirmation phrase for
  the guarded App manifest preparation step. It also reports
  `authorized_source_write_command`,
  `authorized_catalog_write_command`, and
  `WRITE_WLS_OFFICIAL_SOURCES` for the guarded source catalog preparation
  step. Its
  `next_actions` output maps remaining blockers to ordered actions,
  authorization requirements, safe-to-run status, working directories,
  commands, and side-effect boundaries without running sync, setup, WLS start,
  token writes, or live API calls. The ordered actions must include
  `run_local_app_setup_after_sync` with
  `php bin/w setup:upgrade --route --skip-env-check --skip-composer-dump`
  before App WLS startup. `--action-plan-only=1` provides the same readiness
  exit code with compact blocker/action output for operator and CI loops.
- The official manifest shape is checked by
  `tools/validate-official-appstore-manifest-contract.php` and documented in
  `93-official-appstore-manifest-contract.md`. The same tool has a read-only
  `--template=1` mode that emits `manifest_template` from the current DEV WLS
  plugin meta plus `source_plan` for `official-apps/modules/*` so the App-side
  catalog can be prepared without tag guessing.
  Its `--template-target=...` dry-run reports the exact manifest path without
  writing, and the source plan reports the exact module source targets without
  writing. Manifest write mode requires
  `--write=1 --confirm=WRITE_WLS_OFFICIAL_MANIFEST`; source catalog write mode
  requires `--write-sources=1 --confirm-sources=WRITE_WLS_OFFICIAL_SOURCES`.
- `tools/wls-panel-final-preflight.php` now runs the same official manifest
  self-test and template dry-run as part of the aggregate pre-live-E2E gate. It
  must report `official_manifest_self_test_passed=true`,
  `authorization_pack_self_test_passed=true`,
  `live_e2e_evidence_validator_self_test_passed=true`,
  `live_e2e_capture_self_test_passed=true`,
  `official_manifest_template_dry_run_passed=true`, and
  `official_manifest_template_would_write=true`,
  `official_manifest_source_plan_ready=true`, and
  `official_manifest_source_plan_would_write=true`,
  `local_live_gate_premature_allow_blocked_no_live_call=true`,
  `production_live_gate_premature_allow_blocked_no_live_call=true`,
  `local_readiness_app_checkout_identity_ok=true`,
  `local_readiness_app_env_deploy_mode_local=true`,
  `local_readiness_app_env_wls_endpoint_locked=true`,
  `local_readiness_deploy_current_locked=true`,
  `local_deploy_endpoint_policy_passed=true`,
  `local_deploy_endpoint_policy_exact_root=true`,
  `production_deploy_endpoint_policy_passed=true`,
  `production_deploy_endpoint_policy_exact_root=true`,
  `blocked_preflight_no_evidence_files=true`,
  `local_endpoint_locked=true`, and `production_endpoint_locked=true` before
  any authorized App checkout catalog write can be counted as ready. The
  blocked-preflight evidence check requires
  `var\wls-panel-plan\local-appstore-live-e2e.json`,
  `var\wls-panel-plan\production-appstore-live-e2e.json`, and `var\leak.json`
  to be absent while the live gates are blocked or preflight-only.
- Deployment artifact endpoint policy is checked by
  `tools/validate-deploy-appstore-endpoint-policy.php`. The checker is read-only
  and fails production artifacts that resolve to local App Store or `www.*`
  marketplace hosts, omit `appstore_platform_url`, or store an API endpoint path
  in that platform-root field; `--self-test=1` proves those negative cases
  without live App Store access.
- The source-code endpoint contract is checked by
  `tools/validate-appstore-endpoint-source-contract.php`. It is read-only and
  confirms `DeployOrchestratorService`, `AppStorePlatformUrlResolver`,
  `AccountBindService`, and WLS Panel still implement the same local
  `app.weline.test:9523` versus deployed `app.aiweline.com` rule. It must also
  keep WLS Panel's own fallback path under the same policy:
  `panel_has_locked_fallback_defaults`,
  `panel_fallback_local_mode_is_explicit_only`, and
  `panel_fallback_reads_deployed_production_current` prove that the standalone
  panel can still render the correct local or deployed marketplace endpoint
  when the AppStore resolver class is unavailable or throws during an upgrade.
  Runtime guard checks
  `deploy_rejects_official_website_marketplace_sources`,
  `resolver_rejects_official_website_marketplace_sources`, and
  `panel_fallback_rejects_official_website_marketplace_sources` prove that
  known official `www.*` website hosts are not accepted as marketplace roots.
  The same checker now includes a real `--self-test=1` mode and covers
  `ModuleInstallerService`, AppStore backend marketplace and installed-module
  controllers, AppStore endpoint/source strip rendering, WLS Panel return
  context propagation, and post-install/update redirects back to the standalone
  WLS Panel. Its negative cases reject `www.aiweline.com`, a missing endpoint
  strip, missing WLS return URL/redirect, and installer code that bypasses
  `AppStorePlatformUrlResolver`.
- The local live typed-tag API is guarded by
  `tools/local-appstore-typed-tag-live-gate.php`. In default/report mode it is
  preflight-only and must report `local_live_gate_no_live_call`; only
  `--allow-live=1` plus `ready_for_live=true` may invoke the underlying
  `marketplace-typed-tag-e2e.php` request. The wrapper guard must also report
  `local_deploy_policy_exact_root=true`,
  `readiness_app_env_deploy_mode_local=true`, and
  `readiness_deploy_current_locked=true`.
- The production live typed-tag API is guarded by
  `tools/production-appstore-typed-tag-live-gate.php`. In default/report mode
  it is preflight-only and must report `production_live_gate_no_live_call`; it
  rejects manual `--endpoint` input and insecure mode, requires deployed
  `current.json` to record `appstore_platform_url=https://app.aiweline.com` and
  `appstore_platform_url_source=production_default`,
  checks token readiness without printing secrets, reports
  `production_deploy_policy_exact_root=true`, and only `--allow-live=1` plus
  `ready_for_live=true` may invoke the production typed-tag request. The
  production wrapper keeps fixture deploy-current files preflight-only:
  `ready_for_live=true` also requires
  `production_deploy_current_is_deployed_artifact=true`, which is true only for
  the deployed workspace `var/deploy/current.json`.
  The underlying `tools/marketplace-typed-tag-e2e.php --deploy-current=...`
  path also blocks when production `current.json` omits
  `appstore_platform_url`; it does not silently substitute the production
  default when deployment metadata is incomplete.
- Captured live E2E JSON is checked by
  `tools/validate-appstore-live-e2e-evidence.php`. The validator is read-only,
  has `--self-test=1`, accepts guarded wrapper `live_evidence`, and requires
  the deployment-derived endpoint, `single_tag_module_wls`,
  `structured_tags_all_match`, conclusive
  `negative_exact_match_module_wls-extra`, `require_negative_conclusive`, and
  `no_secret_values`. Capture-wrapper evidence must also prove
  `evidence_endpoint_source_deploy_current=true` and
  `capture_metadata_endpoint_source_deploy_current=true`, so a matching URL
  from `default:production`, manual `--endpoint`, or another remembered source
  is not accepted. For `--expect=production`, the validator also requires
  `production_evidence_source_is_deployed_current_json=true` and
  `production_capture_source_is_deployed_current_json=true`, which rejects
  fixture deploy-current files such as
  `tools/deploy-current-production-default.json`; final production evidence
  must reference the deployed `var/deploy/current.json`. Capture-wrapper
  evidence must also include
  `capture_metadata.evidence_output_path` matching the actual `--evidence`
  file being validated, plus
  `capture_metadata.workorder_authorization_consistency` with `passed=true`,
  locked local and production roots/endpoints, and the shared
  `drift_review_fingerprint`. Local evidence must pass `--expect=local`; production
  evidence must pass `--expect=production`.
- The same consistency metadata must also prove
  `preflight_local_app_checkout_identity_ok=true`,
  `preflight_local_app_env_wls_endpoint_locked=true`,
  `workorder_local_app_checkout_identity_ok=true`,
  `workorder_local_app_env_wls_endpoint_locked=true`,
  `authorization_local_app_checkout_identity_ok=true`,
  `authorization_local_app_env_wls_endpoint_locked=true`,
  `local_app_checkout_identity_consistent=true`, and
  `local_app_env_wls_endpoint_consistent=true` so local development cannot drift
  from `E:\WelineFramework\Framework-Official\App\weline` /
  `https://app.weline.test:9523` before live evidence is accepted.
- Final live E2E capture should go through
  `tools/wls-panel-live-e2e-capture.php`. Its `--self-test=1` mode is
  read-only and is mirrored by final preflight as
  `live_e2e_capture_self_test_passed=true`. Default `--environment=local` and
  `--environment=production` calls are preflight-only and write nothing. With
  `--allow-live=1`, it writes sanitized JSON under
  `var\wls-panel-plan\local-appstore-live-e2e.json` or
  `var\wls-panel-plan\production-appstore-live-e2e.json` only after
  `live_executed=true`, then immediately calls
  `validate-appstore-live-e2e-evidence.php --evidence=...` and
  `wls-panel-live-evidence-final-gate.php`. It must copy the live gate
  `endpoint_source`, normalized evidence output path, and the
  workorder/authorization consistency metadata into
  `capture_metadata`, and segment-normalize custom `--evidence-output` paths
  before the allowed-root check; `--self-test=1` must
  include `path_traversal_outside_var_rejected`,
  `local_final_gate_uses_local_evidence_arg`, and
  `production_final_gate_uses_production_evidence_arg`, and
  `var\wls-panel-plan\..\leak.json` must fail with
  `evidence_output_inside_var=false`. A final captured proof must include
  `tool_results.final_evidence_gate.ready=true`.
- Final captured live E2E JSON is accepted by
  `tools/wls-panel-live-evidence-final-gate.php`. This read-only gate is
  mirrored by final preflight as `live_e2e_final_gate_self_test_passed=true`,
  rejects raw runner output for final acceptance, rejects missing
  `capture_metadata`, rejects non-`deploy-current:*` endpoint sources, supports
  `--environment=local`, `--environment=production`, and `--environment=both`,
  requires the validator's `capture_metadata_output_path_present` and
  `capture_metadata_output_path_matches_file` checks, requires
  `capture_consistency_metadata_present`,
  `capture_consistency_passed`,
  `capture_consistency_drift_fingerprints_match`,
  `capture_consistency_endpoint_contract_exact`, and
  `capture_consistency_drift_fingerprint_present`, requires local evidence to
  use `app.weline.test:9523`, and requires production evidence to use
  `app.aiweline.com` from deployed `var/deploy/current.json`. The same final
  gate self-test must accept valid production capture-wrapper evidence only
  when the validator reports `production_evidence_source_is_deployed_current_json`
  and `production_capture_source_is_deployed_current_json`, and reject
  production fixture deploy-current sources even if the endpoint URL is correct.
- The final live E2E authorization packet is built by
  `tools/wls-panel-live-e2e-authorization-pack.php`. It is read-only and must
  support `--self-test=1`; that self-test must report `passed=true`, and the
  aggregate final preflight mirrors this as
  `authorization_pack_self_test_passed=true`. The packet itself must report
  `authorization_pack_ready_for_review=true`,
  `current_state=blocked_before_live_run` until the live gate is truly ready,
  `local_endpoint_exact_root=true`, `production_endpoint_exact_root=true`,
  `local_env_is_explicit_dev_or_local=true`, `sync_manifest_ok=true`,
  `preflight_kept_no_live_call=true`, `premature_allow_is_blocked=true`,
  `capture_self_test_passed=true`, `capture_path_traversal_guarded=true`,
  `blocked_preflight_no_evidence_files=true`,
  `all_side_effect_steps_deferred=true`,
  `only_live_step_runnable_when_ready=true`, and `no_secret_values=true` before
  any explicit App checkout sync, App manifest/source write, WLS start, token
  export, or live AppStore API call. When all blockers are cleared, only
  `run_live_typed_tag_e2e` may become runnable.
  With `--include-rollback-review=1 --fail-if-unsafe=1`, the same packet must
  also prove `rollback_review_safe_when_requested=true`,
  `allowed_status_count=0`, and a non-empty `out_of_scope_fingerprint`, so
  unrelated App checkout rows are preserved for before/after comparison while
  any local status under the allowed sync paths blocks authorization.
  CI and release scripts should call the same packet with
  `--fail-if-unsafe=1`, which exits non-zero if the packet ever loses those
  no-secret, no-live-call, execution-order, or capture-path guard properties.
- The final compact workorder is built by
  `tools/wls-panel-final-workorder.php`. It mirrors the aggregate preflight and
  exports the remaining AppStore marketplace work as `deferred_action_plan`
  with `readiness_action_count`, `readiness_action_plan_contract_ok`,
  `local_readiness_app_checkout_identity_ok`,
  `local_readiness_app_env_wls_endpoint_locked`,
  `deferred_action_plan_all_blocked`, `authorized_app_checkout_sync`,
  `run_local_app_setup_after_sync`, `prepare_official_manifest`,
  `set_local_marketplace_bearer_token`, and `run_live_typed_tag_e2e`. This is
  the handoff surface for the corrected endpoint split: local development uses
  `E:\WelineFramework\Framework-Official\App\weline` plus
  `https://app.weline.test:9523`, while production/deployed tests use
  `https://app.aiweline.com` only from deployed `var/deploy/current.json` with
  `appstore_platform_url_source=production_default`.
- The workorder action chain is independently guarded by
  `tools/validate-final-workorder-deferred-actions.php`. The live validator
  checks `required_actions_ordered`, `blocked_state_all_actions_not_runnable`,
  `ready_state_only_live_action_runnable`,
  `local_policy_records_framework_official_app_checkout`,
  `local_policy_records_app_env_wls_endpoint`,
  `local_app_checkout_identity_preflight_locked`,
  `local_app_env_wls_endpoint_preflight_locked`,
  `sync_requires_user_authorization`,
  `manifest_requires_confirmed_writes`,
  `token_requires_secret_placeholder`, `start_targets_app_weline_9523`, and
  `live_uses_guarded_local_gate`,
  `production_capture_requires_production_default_source`; its `--self-test=1`
  rejects missing actions, secret leakage, `www.aiweline.com` as a production
  marketplace root, and
  `rejects_production_capture_without_production_default_source`. The
  aggregate final preflight exposes this as
  `deferred_actions_validator_self_test_passed`.
- The final goal-completion decision is guarded by
  `tools/wls-panel-goal-completion-gate.php`. It must be the last read-only
  command before calling the goal complete: it requires
  `completion_audit_complete`, `completion_matrix_all_proven`,
  `traceability_matrix_all_proven`, `final_preflight_goal_complete`,
  `workorder_authorization_consistency_passed`,
  `workorder_authorization_consistency_roots_locked`,
  `workorder_authorization_consistency_local_app_locked`,
  `workorder_authorization_consistency_no_secret_values`,
  `local_final_gate_ready`, `production_final_gate_ready`, and `inside_var=true`
  from both final evidence gates. The consistency check forces local
  development to stay on `E:\WelineFramework\Framework-Official\App\weline` and
  `https://app.weline.test:9523`, while deployed production proof must stay on
  `https://app.aiweline.com` from deployment metadata. While captured local or
  production evidence is absent, or while the handoff consistency proof is
  stale, it must return `complete=false` and report
  `workorder_authorization_consistency_not_current`,
  `local_live_evidence_not_accepted`, or
  `production_live_evidence_not_accepted`. Its `--self-test=1` rejects
  incomplete audits, missing local evidence, missing production evidence,
  endpoint-policy drift, workorder/authorization consistency drift,
  `www.aiweline.com` as a production marketplace root, and final evidence
  outside `var/wls-panel-plan/`; final preflight exposes that as
  `goal_completion_gate_self_test_passed`.
- The scoped DEV-to-App sync manifest can be run with `--with-drift=1` to
  compare only allowed paths between the DEV workspace and
  `E:\WelineFramework\Framework-Official\App\weline`. The report is read-only
  and exists to make the later authorized `鍒嗛」` operation explicit rather than
  guessed. After the authorized sync, `--fail-on-drift=1` turns any remaining
  allowed-path difference into a failing gate before live local AppStore API
  evidence is accepted. `--drift-summary-only=1` can be added to either command
  for compact CI or deployment logs; it omits row details but preserves the same
  gate semantics and reports `rows_omitted`.

## Requirement Matrix

| User requirement | Evidence or design source | Current status | Final gate |
| --- | --- | --- | --- |
| WLS is exposed in the framework backend only as an authorized menu entry, then opens as an independent WLS Panel. | `10-prototype.md`, `75-stage-1-panel-shell-e2e-evidence.md`, `77-current-integrated-verification-evidence.md`, `90-completion-audit-and-next-gates.md`. | Proven for current shell. | Re-run final browser sweep after any new panel code. |
| WLS Panel visually separates itself from the ordinary framework backend while still allowing project admin jumps. | Panel shell evidence, Project Config Center links, old `panel-marketplace` compatibility checks. | Proven for current shell and current project links. | Browser sweep must prove Project Admin, Child Panel, and plugin links stay scoped and do not fall back to ordinary backend pages. |
| Panel supports light/dark theme switching and responsive desktop/mobile layouts. | `75`, `76`, `77`, `90`; plugin shell normalization evidence. | Proven for current Dashboard, Marketplace, Security, PHP, DB, FileManager, and Deploy pages. | Re-run desktop `1440` and phone `390` in light/dark after further UI changes. |
| Panel can manage local WLS and child WLS projects. | Project registry, Project Config Center, Gateway route cards, safe context link checks in `30` and `77`. | Proven for current registry and links. | Future multi-project UX breadth is optional unless new workflows are added. |
| Panel can enable Gateway mode and manage multi-site proxy rules. | Stage 2 project registry and Gateway runtime-apply evidence in `00`, `30`, `75`, `77`, `90`. | Proven for passthrough/Gateway role apply. | Re-run only after Gateway runtime edits. |
| Direct-listen mode should be preferred where supported while passthrough remains available. | Runtime capability detector, Windows negative proof, Linux supported-runner direct-listen proof in `90`. | Proven for capability gate and supported Linux runner. | Production benchmark tuning is future work, not a current completion blocker. |
| PHP configuration can be clicked and managed from WLS Panel. | `Weline_PhpManager` typed plugin, Project Config Center operation slot, php.ini and extension adapter evidence in `77` and `90`. | Proven for current PHP profiles, php.ini block apply/rollback, and Windows bundled extension adapter. | Future non-Windows/package-manager adapters remain guarded backlog. |
| Database configuration can be clicked and managed from WLS Panel. | `Weline_DbManager` typed plugin, backup/restore/health/SQL Apply/migration evidence in `77` and `90`. | Proven for current guarded MySQL/MariaDB flows and profiles. | PostgreSQL migration execution remains future guarded adapter. |
| Project path management should lead to file management, but FileManager is a WLS Panel plugin. | `Weline_FileManager` typed plugin, controlled roots, path policy, source archive/trash evidence. | Proven for bounded roots and staged source policies. | Broader source writes, uploads, purge, overwrite, and multi-root breadth need new guarded flags and evidence. |
| Security rules and attack protection logs are native WLS Panel functions. | Native Security page, visual rule editor, attack-log filters, project policies, audit log evidence in `30`, `77`, `90`. | Proven for current page. | Re-run browser sweep after new Security UI changes. |
| WLS Panel marketplace reads only WLS-compatible plugins from AppStore through typed module tags. | `20-plugin-tag-logic.md`, `93-official-appstore-manifest-contract.md`, AppStore WMP-Meta tags, `module:wls`, `custom:*`, installed-module query contract, readiness manifest checks, official manifest validator/template/source-plan/materialize output, guarded live gate wrapper, `tools/wls-panel-live-e2e-authorization-pack.php --self-test=1`, `tools/wls-panel-live-e2e-authorization-pack.php --fail-if-unsafe=1`, `tools/validate-appstore-live-e2e-evidence.php --self-test=1`, `tools/wls-panel-live-e2e-capture.php --self-test=1`, `tools/wls-panel-live-evidence-final-gate.php --self-test=1`, `tools/marketplace-typed-tag-e2e.php --self-test=1`, `var\wls-panel-plan\local-appstore-live-e2e.json`, `var\wls-panel-plan\production-appstore-live-e2e.json`, and `78-appstore-demo-plugin-install-evidence.md`. | Proven: client parsing, local resolver, installed-module discovery, endpoint observability, offline runner parsing/exact-match behavior, captured-evidence validator self-test, capture wrapper self-test, final evidence gate self-test, capture consistency metadata/fingerprint guards, local App checkout/env endpoint evidence guards, manifest contract/template/source-plan shape, guarded materialization behavior, authorization packet self-test, no-live-call guard, CI-safe unsafe-packet failure mode, concrete `Weline_WlsDemoPlugin` AppStore catalog/install path, and canonical local plus production capture-wrapper evidence are proven. Local and production live captures both returned 5 `module:wls` plugins, exactly `Weline_WlsDemoPlugin` for `module:wls + custom:wls-panel-plugin`, exactly `Weline_WlsTagCanary` for the conclusive `module:wls-extra` negative canary, and no secret values. | Closed for the current marketplace slice. Re-run local and production capture/final-gate commands only if endpoint resolution, typed-tag filtering, official WLS catalog metadata, or demo plugin package/install behavior changes. |
| Module tags use the existing meta system, not a separate inheritance protocol. | `20-plugin-tag-logic.md`, AppStore module meta docs, WMP-Meta v1 rows. | Proven as design and local package/client contract. | Live marketplace API must return normalized typed tags. |
| Tags support typed `type:value` format such as `module:wls`, `custom:wls-file-manager`, `system:false`. | AppStore tag logic docs, package validation rows, and typed-tag runner self-test. | Proven locally, including string, JSON-string, structured object, locale-grouped, `system:false`, negative `module:wls-extra`, strict negative-canary conclusive cases, and runner-level deploy-current source/root guard cases. | Local/production App Store API E2E must prove the live platform route returns the same exact-match behavior with `--require-negative-conclusive=1`. |
| WLS Panel installs plugins from online marketplace, while local development uses local App Store. | Endpoint resolver, endpoint observability, manifest/source catalog guard, source contract checker, guarded live gate wrapper, live capture wrapper, final evidence gate, deploy endpoint policy checker, final work order, authorization packet, read-only App readiness probe evidence in `77` and `90`, `var\wls-panel-plan\local-appstore-live-e2e.json`, `var\wls-panel-plan\production-appstore-live-e2e.json`, plus `78-appstore-demo-plugin-install-evidence.md`. | Proven: local development uses `https://app.weline.test:9523`, deployed production uses `https://app.aiweline.com`, forbidden `www.*` marketplace roots are rejected, the demo plugin install path is proven for both local and production, and the canonical capture-wrapper payloads have been consumed by the final evidence gate. Local and production both listed `Weline_WlsDemoPlugin`, downloaded matching packages, installed `app/code/Weline/WlsDemoPlugin/register.php`, validated exact typed tags, and stored no token values in evidence. | Closed for the current marketplace slice. Re-run only if AppStore endpoint policy, local/production environment split, package install behavior, or marketplace typed-tag semantics change. |
| Deployed tests and production checks automatically use `app.aiweline.com`. | `DeployOrchestratorService` deployment payload fields, `AppStorePlatformUrlResolver`, `tools/validate-appstore-endpoint-source-contract.php`, tracked runner `tools/marketplace-typed-tag-e2e.php` with `--deploy-current=var/deploy/current.json` plus `--resolve-endpoint-only=1`, guarded production wrapper `tools/production-appstore-typed-tag-live-gate.php`, live evidence checker `tools/validate-appstore-live-e2e-evidence.php`, live capture wrapper `tools/wls-panel-live-e2e-capture.php`, final evidence gate `tools/wls-panel-live-evidence-final-gate.php`, manifest guard `tools/validate-local-appstore-sync-manifest.php`, deploy endpoint policy checker `tools/validate-deploy-appstore-endpoint-policy.php`, workorder/authorization consistency gate, and fixtures `tools/deploy-current-local-development.json` / `tools/deploy-current-production-default.json`. | Proven by isolated resolver probe, deployment artifact shape, static source contract check, manifest self-check, deploy endpoint policy fixture checks, deploy endpoint self-test negative cases, typed-tag runner self-test cases that reject production non-`production_default` source and API-path platform URLs, production wrapper preflight guards, workorder/authorization consistency self-test and read-only gate, live-evidence validator self-test, live capture wrapper self-test, final evidence gate self-test, and no-token/no-network runner preflights that resolve local metadata to `https://app.weline.test:9523/api/v1/platform/module/list` and production metadata that explicitly records `https://app.aiweline.com` plus `appstore_platform_url_source=production_default` to `https://app.aiweline.com/api/v1/platform/module/list`; the same evidence path now also rejects wrong local checkout identity and missing env endpoint lock through `rejects_wrapper_consistency_wrong_local_checkout`, `rejects_wrapper_consistency_missing_env_endpoint_lock`, `rejects_consistency_wrong_local_checkout`, and `rejects_consistency_missing_env_endpoint_lock`. | Production launch check after `app.aiweline.com` is live with external token/account: first run `tools/wls-panel-live-e2e-capture.php --environment=production --deploy-current=var\deploy\current.json`, then run the same wrapper with `--allow-live=1 --evidence-output=var\wls-panel-plan\production-appstore-live-e2e.json` only after the underlying production live gate reports `ready_for_live=true`; it must report `captured_valid`, include `capture_metadata.workorder_authorization_consistency` with exact local and production roots/endpoints plus the shared drift fingerprint, exact `local_development_checkout`, exact `local_development_env_wls_endpoint`, `capture_consistency_local_app_identity_locked=true`, `capture_consistency_local_app_env_endpoint_locked=true`, and `capture_consistency_local_env_wls_endpoint_exact=true`, validate with `tools/validate-appstore-live-e2e-evidence.php --evidence=... --expect=production`, and pass `tools/wls-panel-live-evidence-final-gate.php --environment=production`. |
| Plugin install/update returns to the standalone WLS Panel and refreshes installed capabilities. | AppStore return context, plugin-refresh result strip, normalized `panel_entry_url` evidence. | Proven for current local flow shape. | Re-run browser install/update journey after local Official App package API is token/route ready. |
| WLS Panel can load new module-contributed menus and capabilities after module updates. | `WlsPanelPluginDiscoveryService`, AppStore `installedModules` query provider, plugin shell normalization. | Proven for current plugin refresh path. | Final package journey must prove new menu/capability appears without leaving the standalone panel. |
| Deploy is a WLS Panel capability with webhook/tag release support. | `Weline_Deploy` panel plugin, Project Profile, webhook replay, manual plan, controlled tag/branch harness, rollback evidence. | Proven for local guarded success path and rollback harness. | Production credentials and real release targets remain outside local UI completion. |
| Default Deploy release policy is tag-only. | Deploy stage notes in `30`, `75`, `77`, `95`. | Proven for guarded Profile/preflight behavior. | Final Deploy gate must keep branch mode opt-in and dry-run separate from execution. |
| Panel update/marketplace update should recognize new WLS Panel function changes and refresh the control panel. | Plugin refresh flow, command refresh, route refresh, registry summary evidence. | Proven for current local refresh path. | Final AppStore install/update journey must prove one-click refresh from WLS Panel origin. |
| The whole plan needs prototypes, logic diagrams, atomic tasks, and final evidence. | `10`, `20`, `30`, `75`, `76`, `77`, `90`, `95`, this file, and `tools/wls-panel-completion-audit.php`. | Proven: current docs are organized in `app/code/Weline/Server/doc/wls-panel-plan`; the completion audit tool parses both the completion matrix and requirement traceability matrix, then reports remaining non-`Proven` rows. | Keep `00-INDEX.md` current as new evidence files are added. |
| Final panel must actually work, pass browser testing, and have acceptable UI quality. | `77` current release-candidate browser sweep, `95` final acceptance runbook, `tools/wls-panel-final-preflight.php`, and `tools/wls-panel-completion-audit.php --fail-on-incomplete=1`. | Proven for current release candidate except the marketplace App API gate; the completion audit correctly reports `complete=false`, and final preflight reports `ready_for_live_local_appstore_e2e=false` until the App checkout blockers clear. | Goal cannot complete until all rows in `90` are `Proven`, the local App Store typed-tag API gate passes, final preflight is green, and the completion audit exits successfully with `--fail-on-incomplete=1`. |

## Current Hard Gate

The earlier hard gate is no longer the raw App Store demo install path: the
demo plugin install is proven locally and in production by
`78-appstore-demo-plugin-install-evidence.md`. The remaining hard gate is to
turn that proven path into the canonical capture-wrapper evidence shape that
the completion tools require:

1. Local capture:
   Run
   `tools/wls-panel-live-e2e-capture.php --environment=local --allow-live=1 --evidence-output=var\wls-panel-plan\local-appstore-live-e2e.json`
   against `https://app.weline.test:9523/api/v1/platform/module/list`.
   The captured JSON must list `Weline_WlsDemoPlugin` with exact typed tags,
   include canonical `capture_metadata.workorder_authorization_consistency`,
   keep `local_development_checkout=E:\WelineFramework\Framework-Official\App\weline`,
   keep `local_development_env_wls_endpoint=https://app.weline.test:9523`,
   and prove the `module:wls-extra` negative canary is conclusive.
2. Local validation: pass
   `tools/validate-appstore-live-e2e-evidence.php --evidence=var\wls-panel-plan\local-appstore-live-e2e.json --expect=local`
   and `tools/wls-panel-live-evidence-final-gate.php --environment=local`.

3. Production capture: run
   `tools/wls-panel-live-e2e-capture.php --environment=production --deploy-current=var\deploy\current.json --allow-live=1 --evidence-output=var\wls-panel-plan\production-appstore-live-e2e.json`
   after deployment. The resolved endpoint must be
   `https://app.aiweline.com/api/v1/platform/module/list`; deployed metadata
   must record `appstore_platform_url=https://app.aiweline.com` and
   `appstore_platform_url_source=production_default`, with no `www.*` root.
4. Production validation: pass
   `tools/validate-deploy-appstore-endpoint-policy.php --expect=production`,
   `tools/validate-appstore-live-e2e-evidence.php --evidence=var\wls-panel-plan\production-appstore-live-e2e.json --expect=production`,
   `tools/wls-panel-live-evidence-final-gate.php --environment=production`,
   and the final workorder `tools/wls-panel-final-workorder.php` check named
   `production_capture_must_require_production_default_source`.

Only after both canonical capture files exist and pass their validators can the
marketplace typed-tag install rows move from `Partial` to `Proven`.
