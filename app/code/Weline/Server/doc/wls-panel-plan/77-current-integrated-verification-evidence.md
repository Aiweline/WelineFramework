# WLS Panel Current Integrated Verification Evidence

Date: 2026-06-21

Task: `dev/ai/codex/tasks/2026-06-21/2026-06-21-1408-wls-panel-current-integrated-verification-refresh`

Status: passed for the current integrated browser smoke on a dedicated WLS
instance. This is an acceptance refresh only; it does not change production
code.

## 2026-06-25 Local AppStore Schema Sync Diagnostic

Scope:

- Hardened the read-only local AppStore readiness probe output so the
  `app_schema_has_sqlite_composite_pk_guard` blocker is no longer a bare check
  name.
- The probe now reports `schema_sync.source`, `schema_sync.target`,
  `authorized_sync_required`, `setup_required_after_sync`, and the exact
  allowed sync path for the sqlite composite-primary-key guard.
- This keeps the next action bounded: DEV already has the guard; the local App
  checkout still needs the reviewed scoped sync and then App setup before WLS
  start/live typed-tag E2E.

Commands:

```text
extend\server\php\php.exe -l app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php
extend\server\php\php.exe app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php --action-plan-only=1
extend\server\php\php.exe app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php
```

Results:

- PHP lint passed for `local-appstore-readiness-probe.php`.
- The action-plan output reports
  `schema_sync.source.guard_present=true`,
  `schema_sync.target.guard_present=false`,
  `schema_sync.authorized_sync_required=true`,
  `schema_sync.setup_required_after_sync=true`, and
  `schema_sync.allowed_sync_path=app/code/Weline/Framework/Database/Schema/SchemaMigrationExecutor.php`.
- The full readiness probe remains intentionally blocked with no sync, setup,
  WLS start, token write, live API call, or repository write.

## 2026-06-25 Marketplace Endpoint Lock Regression

Scope:

- Locked the AppStore runtime resolver regression around the current environment
  decision: local development uses `https://app.weline.test:9523`, while
  deployed production tests use `https://app.aiweline.com` from deployment
  metadata.
- Hardened `AppStorePlatformUrlResolver` so an invalid local config lookup or
  pre-bootstrap config layer cannot prevent the local fallback from resolving.
- Added resolver tests proving `www.weline.test:9518` and
  `www.aiweline.com` are official website hosts, not marketplace roots.

Commands:

```text
extend\server\php\php.exe -l app\code\Weline\AppStore\Service\AppStorePlatformUrlResolver.php
extend\server\php\php.exe -l app\code\Weline\AppStore\test\Unit\AppStorePlatformUrlResolverTest.php
extend\server\php\php.exe vendor\phpunit\phpunit\phpunit --bootstrap app\code\Weline\AppStore\test\bootstrap.php app\code\Weline\AppStore\test\Unit\AppStorePlatformUrlResolverTest.php
extend\server\php\php.exe app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-endpoint-source-contract.php
extend\server\php\php.exe app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-local-development.json --expect=local
extend\server\php\php.exe app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --expect=production
gitnexus impact AppStorePlatformUrlResolver -r dev-workspace -d upstream --depth 1
gitnexus impact resolveAppStoreMarketplaceInfo -r dev-workspace -d upstream --depth 1
rg -n "AppStorePlatformUrlResolver|resolveAppStoreMarketplaceInfo" app\code tests dev -S
```

Results:

- PHP lint passed for the resolver and resolver test.
- `AppStorePlatformUrlResolverTest` passed: 5 tests, 15 assertions.
- Source-contract gate passed and reported:
  `local_development_appstore=https://app.weline.test:9523`,
  `production_deployed_appstore=https://app.aiweline.com`, and deployment
  artifact `var/deploy/current.json`.
- Local deploy-current policy passed with resolved endpoint
  `https://app.weline.test:9523/api/v1/platform/module/list`.
- Production deploy-current policy passed with resolved endpoint
  `https://app.aiweline.com/api/v1/platform/module/list`.
- GitNexus did not find the new/local symbols in the current index, so the
  impact check falls back to `rg`: direct runtime callers are
  `AccountBindService`, `ModuleInstallerService`, and the WLS Panel endpoint
  display path; deploy metadata is written by `DeployOrchestratorService`.
- Historical sections below may mention then-current
  `out_of_scope_fingerprint` values from older rollback reviews. Treat those
  as point-in-time evidence only. The operator must rerun
  `validate-local-appstore-sync-manifest.php --with-drift=1 --rollback-review=1`
  immediately before and after an authorized scoped sync, and compare only the
  freshly captured fingerprint values.

## 2026-06-25 Pre-Authorization Live Readiness Handoff

Scope:

- Refreshed the read-only final workorder, deferred-action validator,
  workorder/authorization consistency gate, completion audit, local AppStore
  sync-manifest drift review, rollback review, official manifest contract
  self-test, and live E2E authorization packet.
- This handoff does not close the live marketplace rows. It records the current
  state before any explicit App checkout sync, App setup, official manifest
  materialization, App WLS startup, bearer-token export, or live AppStore API
  call.

Commands:

```text
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-workorder.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-final-workorder-deferred-actions.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-workorder-authorization-consistency.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-goal-completion-gate.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php --with-drift=1 --drift-summary-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php --with-drift=1 --rollback-review=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php --template=1 --template-target="E:\WelineFramework\Framework-Official\App\weline\official-apps\manifest.json"
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-capture.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-live-e2e-evidence.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-evidence-final-gate.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php --include-drift-rows=1 --include-rollback-review=1 --fail-if-unsafe=1
```

Results:

- Final workorder returned `workorder_ready=true`,
  `current_state=blocked_before_local_live_capture`,
  `ready_for_local_live_capture=false`, and `goal_complete=false`.
- Deferred-action validation returned `passed=true`.
- Workorder/authorization consistency returned `passed=true` with local
  marketplace root `https://app.weline.test:9523`, local App checkout
  `E:\WelineFramework\Framework-Official\App\weline`, local App env WLS
  endpoint `https://app.weline.test:9523`, and deployed production root
  `https://app.aiweline.com`.
- Strict goal completion gate still exits non-zero with `complete=false`, as
  expected, because both captured evidence files are absent:
  `var\wls-panel-plan\local-appstore-live-e2e.json` and
  `var\wls-panel-plan\production-appstore-live-e2e.json`.
- Completion audit still reports three open rows: one completion-matrix row and
  two traceability rows. The first incomplete requirement is the WLS marketplace
  typed-tag live AppStore E2E gate.
- Current readiness blockers remain
  `app_schema_has_sqlite_composite_pk_guard`, `app_port_listening`,
  `official_manifest_readable`, `official_manifest_has_wls_positive`,
  `official_manifest_has_negative_canary`,
  `official_manifest_negative_canary_exact`, and
  `bearer_token_env_present`.
- The local AppStore sync manifest self-test passed. The current read-only drift
  review reports 47 allowed-path differences: 19 `different` and 28
  `missing_app`. The drift review fingerprint is intentionally not hard-coded in
  this evidence section because this file itself is part of the reviewed sync
  surface; operators must use the latest command output immediately before an
  authorized sync.
- Rollback review for
  `E:\WelineFramework\Framework-Official\App\weline` reports two out-of-scope
  App checkout status rows and zero allowed-path status rows. The out-of-scope
  rows are the existing Admin return-url service/test changes and must be
  preserved; do not reset the App checkout to clear them.
- Official manifest contract self-test passed with a valid shape that includes
  WLS-positive entries and a strict `module:wls-extra` negative canary, but the
  real App checkout still lacks the materialized
  `official-apps/manifest.json` and source catalog until the guarded write
  commands are explicitly authorized.
- Official manifest template dry-run returned `passed=true`,
  `materialize.would_write=true`, `source_plan.ready=true`, five catalog
  entries, four positive WLS modules (`Weline_PhpManager`, `Weline_DbManager`,
  `Weline_FileManager`, `Weline_Deploy`), and one non-installable
  `Weline_WlsTagCanary` canary tagged `module:wls-extra`.
- Live capture, live evidence validator, and final evidence gate self-tests all
  passed. Together they prove local/production evidence paths stay inside
  `var\wls-panel-plan`, production capture must use deployed
  `var\deploy\current.json`, raw runner payloads cannot satisfy the final gate,
  and final evidence is rejected when it uses a wrong endpoint,
  `www.aiweline.com`, a fixture production current file, missing capture
  metadata, missing local App checkout/env endpoint consistency, or leaked
  secret values.
- The live E2E authorization packet returned
  `authorization_pack_ready_for_review=true`,
  `rollback_review_safe_when_requested=true`, no secret values, no live API
  call, and all side-effect steps deferred.

Operator boundary:

- Before local live evidence can be captured, rerun the authorization packet,
  compare the latest drift review fingerprint, then explicitly authorize only
  the scoped App checkout sync paths, run App setup, materialize the official
  manifest/source catalog, start App WLS on `app.weline.test:9523`, set the
  bearer token outside repository files, and only then run the guarded local
  live capture command.
- Production evidence remains a post-launch gate and must use deployed
  `var\deploy\current.json` with
  `appstore_platform_url=https://app.aiweline.com`.
- No App checkout sync, setup, deploy-current write, manifest/source write, WLS
  start, token read/export, live AppStore request, or browser automation was
  performed by this handoff.

## 2026-06-24 Local AppStore Checkout And Env WLS Endpoint Gate

Scope:

- Hardened `tools/local-appstore-readiness-probe.php` so the local AppStore
  live E2E preflight proves the marketplace checkout identity before any live
  API call can run.
- The probe now requires the local checkout to be
  `E:\WelineFramework\Framework-Official\App\weline` and to expose both
  `app/code/Weline/PlatformAppStore` and `app/code/Weline/AppStore`.
- The same probe now extracts the App checkout `app/etc/env.php` WLS endpoint
  without printing secrets and requires it to resolve to
  `https://app.weline.test:9523`, matching both deploy-current metadata and
  the probe endpoint.
- `tools/wls-panel-final-preflight.php` now mirrors these checks as
  `local_readiness_app_checkout_identity_ok` and
  `local_readiness_app_env_wls_endpoint_locked` so the aggregate gate can
  distinguish a correct local App marketplace checkout from the official
  website project.

Validation:

```text
gitnexus impact wlsPanelCompletionRequiredTextChecks -r dev-workspace -d upstream --depth 2
gitnexus impact wlsPanelReadinessLocalDeployCurrent -r dev-workspace -d upstream --depth 2
gitnexus impact wlsPanelReadinessEnvDeployMode -r dev-workspace -d upstream --depth 2
php -n -l app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php
php -n -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php
php -n -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php --action-plan-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
```

Expected read-only behavior:

- No App checkout sync, setup, deploy-current write, manifest/source write, WLS
  start, token read/export, live AppStore request, or browser automation is
  performed by this gate.
- The remaining blockers may still keep
  `ready_for_live_local_appstore_e2e=false`; this section only proves the local
  marketplace identity and env WLS endpoint are no longer guessed.

Results:

- GitNexus returned `Target not found` for the three plan-tool symbols, so this
  change is treated as plan-tool scoped and validated through lint and
  existing read-only gates.
- PHP lint passed for `local-appstore-readiness-probe.php`,
  `wls-panel-final-preflight.php`, and `wls-panel-completion-audit.php`.
- `local-appstore-readiness-probe.php --action-plan-only=1` returned
  `app_checkout_is_framework_official_app=true`,
  `app_checkout_has_platform_appstore_module=true`,
  `app_checkout_has_appstore_module=true`,
  `app_env_wls_endpoint_matches_deploy_current=true`, and
  `app_env_wls_endpoint_matches_probe_endpoint=true`.
- The same readiness output still reports `ready=false` because the old live
  blockers remain: App checkout sqlite guard sync, App WLS listener,
  official manifest/source catalog, strict negative canary, and bearer token.
- Aggregate final preflight returned `ok=true`,
  `local_readiness_app_checkout_identity_ok=true`,
  `local_readiness_app_env_wls_endpoint_locked=true`,
  `ready_for_live_local_appstore_e2e=false`, and `goal_complete=false`.
- Completion audit returned `ok=true`, `complete=false`,
  `completion_matrix_total=14`, `completion_proven_rows=13`,
  `traceability_matrix_total=22`, and `traceability_proven_rows=20`.

## 2026-06-24 Workorder Local AppStore Checkout And Deployment Endpoint Lock

Scope:

- Hardened `tools/wls-panel-final-workorder.php` so the operator workorder
  carries the local App checkout identity
  `E:\WelineFramework\Framework-Official\App\weline`, the local App env WLS
  endpoint `https://app.weline.test:9523`, and the deployed production
  endpoint source `var\deploy\current.json -> https://app.aiweline.com`.
- Hardened `tools/validate-final-workorder-deferred-actions.php` so a workorder
  fails if it omits the local checkout identity, omits the local env WLS endpoint
  lock, or omits the production deploy-current requirement.
- Hardened `tools/wls-panel-live-e2e-authorization-pack.php` so the review
  packet exposes the same local checkout/env endpoint gates and promotes
  `select_local_appstore_checkout` ahead of sync/setup if readiness ever emits
  that action.

Validation:

```text
php -n -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-workorder.php
php -n -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php
php -n -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-final-workorder-deferred-actions.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-workorder.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-final-workorder-deferred-actions.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-workorder.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php --include-drift-rows=1 --include-rollback-review=1 --fail-if-unsafe=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-final-workorder-deferred-actions.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-workorder-authorization-consistency.php
```

Results:

- PHP lint passed for all three touched tools. The local PHP CLI still prints
  existing duplicate-extension warnings before normal output.
- Workorder self-test returned `passed=true`; authorization-pack self-test
  returned `passed=true` including checkout-selection ordering; deferred-action
  validator self-test returned `passed=true` including rejection of missing local
  checkout identity and missing local env endpoint lock.
- Real final workorder returned `workorder_ready=true`,
  `current_state=blocked_before_local_live_capture`,
  `environment_policy.local_development.checkout=E:\WelineFramework\Framework-Official\App\weline`,
  `environment_policy.local_development.env_wls_endpoint=https://app.weline.test:9523`,
  `preflight_checks.local_readiness_app_checkout_identity_ok=true`, and
  `preflight_checks.local_readiness_app_env_wls_endpoint_locked=true`.
- The authorization packet returned `authorization_pack_ready_for_review=true`
  with `local_app_checkout_identity_ok=true`,
  `local_app_env_wls_endpoint_locked=true`,
  `local_app_env_wls_endpoint_exact_root=true`, local endpoint
  `https://app.weline.test:9523/api/v1/platform/module/list`, and production
  endpoint `https://app.aiweline.com/api/v1/platform/module/list`.
- The deferred-action validator returned `passed=true` with
  `local_policy_records_framework_official_app_checkout=true`,
  `local_policy_records_app_env_wls_endpoint=true`,
  `local_app_checkout_identity_preflight_locked=true`,
  `local_app_env_wls_endpoint_preflight_locked=true`,
  `production_capture_uses_deploy_current=true`, and
  `production_capture_requires_deployed_app_aiweline=true`.
- Aggregate final preflight remains `ok=true`,
  `ready_for_live_local_appstore_e2e=false`, and `goal_complete=false`.
  Completion audit remains `complete=false` because the remaining live blockers
  are still App checkout sync/schema guard, App WLS listener, official
  manifest/source catalog, strict negative canary, and bearer token readiness.

## 2026-06-24 Workorder Authorization Consistency Checkout Lock

Scope:

- Hardened `tools/wls-panel-workorder-authorization-consistency.php` so the
  final preflight, compact workorder, and live E2E authorization packet must
  agree on more than local/production roots and the drift fingerprint.
- The gate now also requires
  `preflight_local_app_checkout_identity_ok`,
  `preflight_local_app_env_wls_endpoint_locked`,
  `workorder_local_app_checkout_identity_ok`,
  `workorder_local_app_env_wls_endpoint_locked`,
  `authorization_local_app_checkout_identity_ok`,
  `authorization_local_app_env_wls_endpoint_locked`,
  `local_app_checkout_identity_consistent`, and
  `local_app_env_wls_endpoint_consistent`.
- This closes the gap where the reports could all agree on
  `https://app.weline.test:9523` while one report omitted the concrete local
  App checkout identity or env-derived endpoint lock.

Validation:

```text
gitnexus impact wlsPanelAuthConsistencyAssess -r dev-workspace -d upstream --depth 2
gitnexus impact wlsPanelAuthConsistencySelfTest -r dev-workspace -d upstream --depth 2
gitnexus impact wlsPanelCompletionRequiredTextChecks -r dev-workspace -d upstream --depth 2
php -n -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-workorder-authorization-consistency.php
php -n -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-workorder-authorization-consistency.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-workorder-authorization-consistency.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
```

Expected read-only behavior:

- No App checkout sync, setup, deploy-current write, manifest/source write, WLS
  start, token read/export, live AppStore request, or browser automation is
  performed by this gate.

Results:

- GitNexus returned `Target not found` for the plan-tool helper functions, so
  the change is treated as WLS Panel Plan tool scope and validated through
  lint, self-tests, and aggregate gate output.
- The consistency self-test must now reject wrong local App checkout identity
  and missing authorization env endpoint lock in addition to mismatched drift
  fingerprint, `www.aiweline.com`, missing authorization fingerprint checks, and
  bearer leaks.
- The real consistency gate must report `passed=true` with the eight checkout
  and endpoint-lock checks listed above before captured live evidence can be
  trusted.

## 2026-06-24 Production AppStore Deploy-Current Live Source Gate

Scope:

- Hardened `tools/production-appstore-typed-tag-live-gate.php` so production
  live execution requires the current deployed workspace
  `var/deploy/current.json`.
- The production fixture
  `tools/deploy-current-production-default.json` remains valid for no-network
  endpoint preflight, but cannot make `ready_for_live=true` and cannot become
  production live proof.
- `tools/wls-panel-final-preflight.php` now exposes
  `production_live_gate_deploy_current_is_deployed_artifact` and
  `production_live_gate_premature_allow_deploy_current_is_deployed_artifact`
  so the aggregate report shows whether production live execution is using the
  real deployed artifact.

Validation:

```text
gitnexus impact wlsPanelProductionLiveGateGuardReady -r dev-workspace -d upstream --depth 2
gitnexus impact wlsPanelProductionLiveGateSelfTest -r dev-workspace -d upstream --depth 2
gitnexus impact wlsPanelProductionLiveGateLiveArgs -r dev-workspace -d upstream --depth 2
gitnexus impact wlsPanelProductionLiveGateTokenReady -r dev-workspace -d upstream --depth 2
php -n -l app\code\Weline\Server\doc\wls-panel-plan\tools\production-appstore-typed-tag-live-gate.php
php -n -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\production-appstore-typed-tag-live-gate.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\production-appstore-typed-tag-live-gate.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\production-appstore-typed-tag-live-gate.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --allow-live=1 --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
```

Results:

- GitNexus returned `Target not found` for the plan-tool helper functions, so
  the change is treated as WLS Panel Plan tool scope and validated through lint,
  self-tests, and aggregate preflight output.
- PHP lint passed for the production live gate and final preflight tools.
- Production live-gate self-test returned `passed=true` with `case_count=8`,
  including `allow_live_requires_workspace_var_deploy_current` and
  `fixture_deploy_current_is_not_live_deployment_artifact`.
- The production fixture preflight resolved
  `https://app.aiweline.com/api/v1/platform/module/list`, but reported
  `production_deploy_current_is_deployed_artifact=false`,
  `ready_for_live=false`, and `live_executed=false`.
- The same fixture with `--allow-live=1 --report-only=1` stayed blocked and
  did not execute a live AppStore request.
- Aggregate final preflight returned `ok=true`,
  `production_live_gate_self_test_case_count=8`,
  `production_live_gate_deploy_current_is_deployed_artifact=false`,
  `production_live_gate_premature_allow_deploy_current_is_deployed_artifact=false`,
  `production_endpoint=https://app.aiweline.com/api/v1/platform/module/list`,
  `ready_for_live_local_appstore_e2e=false`, and `goal_complete=false`.
- Completion audit remains intentionally incomplete while the local and
  production captured live evidence files are absent.
- No App checkout sync, setup, deploy-current write, manifest/source write, WLS
  start, token read/export, live AppStore request, or browser automation was
  performed.

## 2026-06-25 AppStore Demo Plugin Local And Production Install

Scope:

- Added and deployed `Weline_WlsDemoPlugin` through the AppStore official
  catalog path.
- Verified local AppStore list, package metadata, zip hash match, and install
  through `https://app.weline.test:9523`.
- Deployed the AppStore update to `https://app.aiweline.com`, fixed production
  HTTPS routing to the App WLS listener, and verified production list, package
  metadata, zip hash match, and install through the HTTPS production endpoint.

Evidence:

- Detailed evidence is recorded in
  `78-appstore-demo-plugin-install-evidence.md`.
- Local evidence file:
  `E:\WelineFramework\Framework-Official\App\weline\var\wls-panel-plan\local-demo-install-evidence.json`.
- Production evidence file:
  `/www/wwwroot/app.aiweline.com/var/wls-panel-plan/production-demo-install-evidence.json`.

Results:

- Both local and production returned `passed=true`.
- Both environments found `Weline_WlsDemoPlugin` with exact WLS typed tags:
  `module:wls`, `custom:wls-demo-plugin`, `category:server-tools`,
  `feature:marketplace-demo`, and `system:false`.
- Both package downloads matched their expected SHA-256 hashes.
- Both installs wrote `app/code/Weline/WlsDemoPlugin/register.php`.
- Production external HTTPS returned HTTP 200 with WLS headers and route hint
  `port=9523,sni=app.aiweline.com`.

Completion note:

- This closes the concrete demo plugin install proof requested for the local
  and deployed AppStore environments.
- It does not yet close the strict WLS Panel completion gate, because the final
  gate still requires canonical capture-wrapper JSON under
  `var\wls-panel-plan\local-appstore-live-e2e.json` and
  `var\wls-panel-plan\production-appstore-live-e2e.json`.

## 2026-06-25 Marketplace Endpoint Source Contract Refresh

Scope:

- Rechecked the runtime and deployment contract behind the latest marketplace
  endpoint decision: local development uses the local App Store root
  `https://app.weline.test:9523`, while deployed production evidence must come
  from deployed `var/deploy/current.json` and carry
  `appstore_platform_url=https://app.aiweline.com` plus
  `appstore_platform_url_source=production_default`.
- Confirmed the contract is enforced in the deployment artifact writer,
  AppStore URL resolver, WLS Panel fallback resolver, deploy endpoint policy
  validator, source-contract validator, and final workorder deferred-action
  gate.
- Tightened the typed-tag runner itself so `--deploy-current=...` now blocks
  wrong local/production roots, production platform URLs that already include
  the API path, production sources other than `production_default`, local
  sources outside the allowed local set, missing environments, and unsupported
  environments before any live request can be attempted.

Validation:

```text
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-endpoint-source-contract.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-final-workorder-deferred-actions.php --self-test=1
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --resolve-endpoint-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-local-development.json --resolve-endpoint-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-typed-tag-live-gate.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\production-appstore-typed-tag-live-gate.php --self-test=1
php -l app\code\Weline\AppStore\Service\AppStorePlatformUrlResolver.php
php -l app\code\Weline\Deploy\Service\DeployOrchestratorService.php
php vendor\bin\phpunit --bootstrap vendor\autoload.php app\code\Weline\AppStore\test\Unit\AppStorePlatformUrlResolverTest.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
```

Results:

- Deploy endpoint policy self-test passed. It accepts local
  `app.weline.test:9523`, accepts production `app.aiweline.com` only when the
  production source is `production_default`, and rejects empty production URL,
  local App Store in production, `www.aiweline.com`, API-path platform URLs,
  and `config:appstore.platform_url` as a production source.
- Endpoint source contract self-test passed against current source files:
  `DeployOrchestratorService` writes `deploy_mode_source`,
  `appstore_environment`, `appstore_platform_url`, and
  `appstore_platform_url_source`; `AppStorePlatformUrlResolver` keeps production
  deployment metadata ahead of env/config residue; AccountBind,
  ModuleInstaller, AppStore backend, and WLS Panel all stay on the shared
  resolver/fallback contract.
- Deferred-action validator self-test passed with
  `rejects_production_capture_without_production_default_source`, proving the
  final production capture step cannot become runnable if the deployed
  `current.json` lacks the accepted source marker.
- `marketplace-typed-tag-e2e.php` PHP lint passed, and its self-test now covers
  the endpoint-source contract directly: `production_deploy_current_explicit_app_aiweline_endpoint_and_source`,
  `production_deploy_current_rejects_non_production_default_source`,
  `production_deploy_current_rejects_api_path_platform_url`,
  `local_deploy_current_explicit_app_weline_endpoint_and_source`, and
  `local_deploy_current_rejects_production_default_source` all passed.
- Endpoint-only runner preflights passed for both fixtures:
  `deploy-current-production-default.json` resolved to
  `https://app.aiweline.com/api/v1/platform/module/list`, and
  `deploy-current-local-development.json` resolved to
  `https://app.weline.test:9523/api/v1/platform/module/list`.
- Guarded local and production live-gate wrapper self-tests still passed after
  the runner-level source/root validation was tightened.
- PHP lint passed for `AppStorePlatformUrlResolver.php` and
  `DeployOrchestratorService.php`; PHPUnit passed
  `AppStorePlatformUrlResolverTest` with 7 tests and 21 assertions. The first
  `vendor\bin\phpunit.bat` attempt failed because the batch wrapper could not
  resolve `chcp` or `php` in the current command environment, so the successful
  run used `php vendor\bin\phpunit ...` directly.
- Completion audit returned `ok=true`, `complete=false`, `13/14` completion
  rows proven, and `20/22` traceability rows proven; the remaining open rows
  are still the guarded local App Store manifest/start/token/live typed-tag E2E
  chain.
- Aggregate final preflight returned `ok=true`,
  `ready_for_live_local_appstore_e2e=false`, `goal_complete=false`, and locked
  local/production endpoints
  `https://app.weline.test:9523/api/v1/platform/module/list` /
  `https://app.aiweline.com/api/v1/platform/module/list`.
- No App checkout sync, setup, deploy-current write, manifest/source write, WLS
  start, token read/export, live AppStore request, or browser automation was
  performed.

## 2026-06-25 Captured Evidence Local App Identity Contract

Scope:

- Hardened `tools/wls-panel-live-e2e-capture.php` so `--allow-live=1` requires
  the workorder/authorization consistency gate to prove
  `preflight_local_app_checkout_identity_ok`,
  `preflight_local_app_env_wls_endpoint_locked`,
  `workorder_local_app_checkout_identity_ok`,
  `workorder_local_app_env_wls_endpoint_locked`,
  `authorization_local_app_checkout_identity_ok`,
  `authorization_local_app_env_wls_endpoint_locked`,
  `local_app_checkout_identity_consistent`, and
  `local_app_env_wls_endpoint_consistent` before any live AppStore call can be
  reached.
- Captured evidence now preserves `local_development_checkout` and
  `local_development_env_wls_endpoint` inside
  `capture_metadata.workorder_authorization_consistency`, alongside the locked
  local and production roots/endpoints and shared drift fingerprint.
- `tools/validate-appstore-live-e2e-evidence.php` now rejects wrapper evidence
  unless `capture_consistency_local_checkout_exact=true`,
  `capture_consistency_local_env_wls_endpoint_exact=true`, and the local App
  checkout/env endpoint checks from preflight, workorder, and authorization are
  all present. Its self-test adds
  `rejects_wrapper_consistency_wrong_local_checkout` and
  `rejects_wrapper_consistency_missing_env_endpoint_lock`.
- `tools/wls-panel-live-evidence-final-gate.php` now requires
  `capture_consistency_local_app_identity_locked`,
  `capture_consistency_local_app_env_endpoint_locked`,
  `capture_consistency_local_checkout_exact`, and
  `capture_consistency_local_env_wls_endpoint_exact`, and its self-test adds
  `rejects_consistency_wrong_local_checkout` plus
  `rejects_consistency_missing_env_endpoint_lock`.

Validation:

```text
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-capture.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-live-e2e-evidence.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-evidence-final-gate.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-capture.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-live-e2e-evidence.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-evidence-final-gate.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
```

Expected result:

- Lint must pass for all four touched plan tools.
- Capture wrapper, evidence validator, final evidence gate, and completion audit
  must remain read-only in these commands.
- Completion audit must keep `complete=false` until local and production live
  evidence files exist and pass the final gates.
- No App checkout sync, setup, deploy-current write, manifest/source write, WLS
  start, token read/export, live AppStore request, or browser automation is part
  of this hardening step.

## 2026-06-25 Goal Completion Deployment Endpoint Consistency Gate

Scope:

- Hardened `tools/wls-panel-goal-completion-gate.php` so the final goal gate
  runs the real `tools/wls-panel-workorder-authorization-consistency.php`
  before it can ever report `complete=true`.
- This turns the deployment endpoint rule into a final completion invariant:
  local development must use the local App Store checkout
  `E:\WelineFramework\Framework-Official\App\weline` and
  `https://app.weline.test:9523`; deployed production proof must use
  `https://app.aiweline.com` from deployment metadata.
- The strict goal gate now requires
  `workorder_authorization_consistency_passed`,
  `workorder_authorization_consistency_roots_locked`,
  `workorder_authorization_consistency_local_app_locked`, and
  `workorder_authorization_consistency_no_secret_values`, and reports
  `workorder_authorization_consistency_not_current` when the preflight,
  workorder, or authorization packet has drifted.

Validation:

```text
gitnexus impact wlsPanelGoalGateAssess -r dev-workspace -d upstream --depth 2
gitnexus impact wlsPanelGoalGateSelfTest -r dev-workspace -d upstream --depth 2
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-goal-completion-gate.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-goal-completion-gate.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-workorder-authorization-consistency.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-goal-completion-gate.php
```

Expected result:

- Goal-gate self-test must include
  `rejects_workorder_authorization_consistency_drift` and
  `rejects_workorder_authorization_production_www_root`.
- The real consistency gate must pass before captured local or production live
  evidence is trusted.
- The strict goal gate must still return `complete=false` until both
  `var\wls-panel-plan\local-appstore-live-e2e.json` and
  `var\wls-panel-plan\production-appstore-live-e2e.json` exist and pass their
  final evidence gates.
- No App checkout sync, setup, deploy-current write, manifest/source write, WLS
  start, token read/export, live AppStore request, or browser automation is part
  of this hardening step.

## 2026-06-25 Completion Audit Tool Inventory Hardening

Scope:

- Hardened `tools/wls-panel-completion-audit.php` so its `requiredFiles` list
  covers every indexed WLS Panel Plan gate/tool file, not only a subset of the
  files named by text checks.
- This closes the gap where `00-INDEX.md` and the runbook could still mention a
  gate such as `local-appstore-typed-tag-live-gate.php`,
  `production-appstore-typed-tag-live-gate.php`,
  `validate-appstore-endpoint-source-contract.php`,
  `validate-final-workorder-deferred-actions.php`,
  `wls-panel-final-workorder.php`,
  `wls-panel-goal-completion-gate.php`, or
  `wls-panel-workorder-authorization-consistency.php` while the completion
  audit did not require the physical file to exist.

Validation:

```text
gitnexus impact wlsPanelCompletionMissingFiles -r dev-workspace -d upstream --depth 2
gitnexus impact wlsPanelCompletionRequiredTextChecks -r dev-workspace -d upstream --depth 2
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
```

Expected result:

- Completion audit must keep `missing_files=[]` while all indexed tools exist.
- If any indexed WLS Panel Plan gate tool is removed, completion audit must add
  `required_file_missing:*` and return `ok=false`.
- No App checkout sync, setup, deploy-current write, manifest/source write, WLS
  start, token read/export, live AppStore request, or browser automation is part
  of this hardening step.

## 2026-06-24 Workorder Authorization Consistency Gate

Scope:

- Added a read-only consistency gate for the final marketplace handoff.
- The gate solidifies the environment rule requested for deployment
  information: local development uses the local App Store at
  `https://app.weline.test:9523`, while deployed production tests must read and
  use `https://app.aiweline.com` from deployment metadata.
- The gate does not sync files, start WLS, write manifests, read token values,
  or call local/production AppStore APIs.

Validation:

```text
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-workorder-authorization-consistency.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-workorder.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-workorder-authorization-consistency.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-workorder-authorization-consistency.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-workorder.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-final-workorder-deferred-actions.php
```

Results:

- PHP lint passed for the new consistency gate and the touched final preflight,
  final workorder, and sync-manifest validator. The local PHP CLI still prints
  existing duplicate-extension warnings before normal output.
- The consistency gate self-test passed and rejects mismatched drift
  fingerprints, `www.aiweline.com` as a production marketplace root, missing
  authorization fingerprint checks, and bearer-value leaks.
- The real read-only consistency gate reported `passed=true` with
  `preflight_local_endpoint_locked=true`,
  `preflight_production_endpoint_locked=true`,
  `workorder_local_root_locked=true`,
  `workorder_production_root_locked=true`,
  `authorization_local_root_locked=true`,
  `authorization_production_root_locked=true`, and
  `drift_fingerprints_match=true`.
- The aggregate final preflight now mirrors the new self-test as
  `workorder_authorization_consistency_self_test_passed=true`.
- The final workorder now includes a read-only
  `review_workorder_authorization_consistency` operator step before any
  side-effectful local capture or production capture action, and its acceptance
  contract contains
  `workorder_authorization_must_match_preflight_and_authorization_pack_roots_and_fingerprint`.
- The local AppStore sync manifest now includes the new consistency gate in
  both allowed and required sync paths. The manifest validator reported
  `ok=true`, `allowed_path_count=47`, `include_path_count=47`, and
  `required_sync_path_count=11`.

## 2026-06-24 Live Capture Consistency Guard

Scope:

- Hardened `tools/wls-panel-live-e2e-capture.php` so any `--allow-live=1`
  capture run must pass `wls-panel-workorder-authorization-consistency.php`
  before it can invoke the local or production live gate.
- Default capture mode remains preflight-only; it does not run the consistency
  gate, call AppStore, read token values, start WLS, or write evidence.
- If consistency fails, capture stops with `live_executed=false` and
  `evidence_written=false`.

Validation:

```text
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-capture.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-capture.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-capture.php --environment=local
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-capture.php --environment=local --allow-live=1
```

Results:

- PHP lint passed for `wls-panel-live-e2e-capture.php`. The local PHP CLI still
  prints existing duplicate-extension warnings before normal output.
- Capture self-test returned `passed=true` and now includes
  `live_capture_requires_consistency_gate_pass` plus
  `live_capture_rejects_consistency_gate_drift_mismatch`.
- Preflight-only local capture returned `status=preflight_only`,
  `allow_live=false`, `live_executed=false`, and no consistency tool result,
  confirming the normal review path stays lightweight and write-free.
- Local `--allow-live=1` returned exit code `1`, `status=blocked_before_live`,
  `live_executed=false`, and `evidence_written=false`. The new consistency gate
  ran first and passed with `drift_fingerprints_match=true`; the later local
  live gate still blocked because the AppStore readiness prerequisites are not
  complete. No AppStore live request or evidence write occurred.

## 2026-06-22 WLS Panel Current Release-Candidate Browser Sweep

Task:
`dev/ai/codex/tasks/2026-06-22/2026-06-22-0703-wls-panel-current-release-candidate-browser-sweep`

Scope:

- Revalidated the current WLS Panel release candidate after the PHP extension
  guarded adapter slice.
- Covered the independent panel shell, Gateway Settings, Marketplace,
  Security, PHP Manager, Database Manager, File Manager, and Deploy.
- This packet changed only task/evidence files and the local CDP smoke harness;
  no production PHP, template, CSS, or JavaScript was changed.

Validation:

```text
php bin/w server:start ai-test-wls-panel-rc-10044 -p 10044 --no-ssl -c 2 --worker-memory-limit=512M --dispatcher-memory-limit=512M
route precheck for /server/backend/wls-panel, /weline_phpmanager/backend/wls-php-manager, and /deploy/backend/wls-deploy
node --check dev/ai/codex/tasks/2026-06-22/2026-06-22-0703-wls-panel-current-release-candidate-browser-sweep/artifacts/wls-panel-rc-cdp-smoke.mjs
node dev/ai/codex/tasks/2026-06-22/2026-06-22-0703-wls-panel-current-release-candidate-browser-sweep/artifacts/wls-panel-rc-cdp-smoke.mjs
php bin/w server:stop ai-test-wls-panel-rc-10044
php bin/w server:status ai-test-wls-panel-rc-10044
Get-NetTCPConnection -LocalPort 10044 -State Listen
```

Results:

- Route precheck reached backend auth redirects instead of 404/500 for the
  Dashboard, PHP Manager, and Deploy entries.
- The first CDP sweep had no UI/runtime failures, but the harness expected the
  internal phrases `RUN_PHP_EXTENSION_ACTION` and `CHECK_DB_HEALTH` to be
  visible on PHP/DB pages. The harness was corrected to assert user-visible
  capability copy instead: `PHP`, `运行态`, `扩展`, `Database`, `健康`, and
  `Profiles`.
- The final local Chrome CDP browser sweep returned `passed=true` with
  `consoleIssueCount=0`.
- Pages covered: Dashboard, Gateway Settings, Marketplace, Security,
  PhpManager, DbManager, FileManager, and Deploy.
- Viewports/themes covered: desktop `1440` light, desktop `1440` dark, phone
  `390` light, and phone `390` dark.
- Assertions passed on every page/viewport: standalone/plugin shell present,
  no login fallback, expected page text present, no fatal/error text, no
  horizontal overflow, and no actionable button/link overflow.
- Cleanup passed: `server:stop` drained the instance, `server:status` reported
  `全部停止 (0/0)`, and the Windows port check found no LISTEN socket on
  `10044`.

Artifacts:

```text
dev/ai/codex/tasks/2026-06-22/2026-06-22-0703-wls-panel-current-release-candidate-browser-sweep/artifacts/wls-panel-rc-cdp-smoke.mjs
dev/ai/codex/tasks/2026-06-22/2026-06-22-0703-wls-panel-current-release-candidate-browser-sweep/artifacts/wls-panel-rc-cdp-smoke-result.json
dev/ai/codex/tasks/2026-06-22/2026-06-22-0703-wls-panel-current-release-candidate-browser-sweep/artifacts/dashboard-desktop-light-1440.png
dev/ai/codex/tasks/2026-06-22/2026-06-22-0703-wls-panel-current-release-candidate-browser-sweep/artifacts/dashboard-phone-dark-390.png
dev/ai/codex/tasks/2026-06-22/2026-06-22-0703-wls-panel-current-release-candidate-browser-sweep/artifacts/marketplace-phone-dark-390.png
dev/ai/codex/tasks/2026-06-22/2026-06-22-0703-wls-panel-current-release-candidate-browser-sweep/artifacts/security-phone-dark-390.png
dev/ai/codex/tasks/2026-06-22/2026-06-22-0703-wls-panel-current-release-candidate-browser-sweep/artifacts/php-manager-phone-dark-390.png
dev/ai/codex/tasks/2026-06-22/2026-06-22-0703-wls-panel-current-release-candidate-browser-sweep/artifacts/db-manager-phone-dark-390.png
dev/ai/codex/tasks/2026-06-22/2026-06-22-0703-wls-panel-current-release-candidate-browser-sweep/artifacts/file-manager-phone-dark-390.png
dev/ai/codex/tasks/2026-06-22/2026-06-22-0703-wls-panel-current-release-candidate-browser-sweep/artifacts/deploy-phone-dark-390.png
```

## 2026-06-22 PhpManager Guarded Extension Adapter Slice

Scope:

- Turned the PHP Manager extension lifecycle dry-run into the first guarded
  executable adapter for supported hosts.
- The current adapter is intentionally narrow: Windows bundled PHP only,
  existing allowlisted `extend/server/php/ext/php_*.dll` files only, and only
  PHP Manager's own managed `extension=` block inside an allowed php.ini path.
- Execution requires checkbox confirmation plus `RUN_PHP_EXTENSION_ACTION`,
  rebuilds the plan server-side, creates a backup, writes sanitized audit
  events, and renders post-verification plus rollback guidance.
- Core-extension removal, unsupported hosts, package managers, PECL, downloads,
  arbitrary extension installation, and unmanaged php.ini lines stay blocked.

Validation:

```text
gitnexus impact WlsPhpManager -r dev-workspace -d upstream --depth 1
gitnexus impact WlsPhpExtensionPlanService -r dev-workspace -d upstream --depth 1
gitnexus impact WlsPhpExtensionExecutionService -r dev-workspace -d upstream --depth 1
gitnexus impact WindowsBundledPhpExtensionAdapter -r dev-workspace -d upstream --depth 1
php -l app/code/Weline/PhpManager/Service/Adapter/WindowsBundledPhpExtensionAdapter.php
php -l app/code/Weline/PhpManager/Service/WlsPhpExtensionPlanService.php
php -l app/code/Weline/PhpManager/Service/WlsPhpExtensionExecutionService.php
php -l app/code/Weline/PhpManager/Controller/Backend/WlsPhpManager.php
php -l app/code/Weline/PhpManager/view/templates/Backend/WlsPhpManager/index.phtml
php bin/w setup:upgrade --stage=route_update -m Weline_PhpManager --skip-env-check --skip-classmap --skip-reflection-compile --skip-composer-dump -s
service probes for bundled `bz2` install and core-extension remove blocking
sandbox php.ini apply/remove probe against var/tmp/wls-php-manager
logged-in Codex in-app Browser smoke on https://127.0.0.1:10043
php bin/w server:stop default
php bin/w server:status default
git diff --check -- app/code/Weline/PhpManager
```

Results:

- GitNexus impact returned LOW risk for `WlsPhpManager` with no upstream
  dependents and LOW risk for `WlsPhpExtensionPlanService` with one direct
  import from `WlsPhpManager.php`. The new execution service and adapter were
  not yet present in the current index and are recorded as index misses rather
  than hidden risk.
- PHP lint passed for the touched controller, services, adapter, and template.
  The local PHP CLI still prints existing duplicate-extension warnings before
  normal output.
- PhpManager i18n parity passed for `en_US.csv` and `zh_Hans_CN.csv`.
- Marketplace meta JSON parse passed with
  `capability:php-extension-guarded-adapter`.
- Route update generated
  `weline_phpmanager/backend/wls-php-manager/extension-execute::POST`.
- Service probes showed bundled `bz2` install as `ready_to_execute` with
  `can_execute=true`, while removing a PHP core extension stayed blocked.
- Sandbox php.ini apply/remove proof created backups, inserted only the
  WLS-managed extension block, verified the managed extension line, removed
  only that managed line, and removed the temporary sandbox file. No shell
  command, package manager, PECL command, or download path ran.
- Logged-in in-app Browser smoke passed on dedicated WLS port `10043`: desktop
  and phone layouts rendered the standalone PHP Manager extension adapter UI in
  light and dark themes, runnable guarded forms were enabled for the supported
  case, core removal remained disabled, no fatal text was visible, and long
  Windows paths no longer produced horizontal overflow after the field-card
  wrapping fix.
- Cleanup stopped the dedicated WLS instance through `server:stop default`;
  follow-up `server:status default` reported `全部停止 (0/0)` and port `10043`
  had no LISTEN socket.

Artifacts:

```text
dev/ai/codex/tasks/2026-06-22/2026-06-22-0558-wls-php-extension-guarded-adapter/php-extension-adapter-desktop.png
dev/ai/codex/tasks/2026-06-22/2026-06-22-0558-wls-php-extension-guarded-adapter/php-extension-adapter-desktop-wrapped.png
```

## 2026-06-22 DbManager Guarded Migration Execution Slice

Scope:

- Added the first guarded `migration_dry_run` execution branch for existing
  Database Manager MySQL/MariaDB `.sql` / `.sql.gz` backup artifacts.
- Execution requires `CHECK_DB_MIGRATION`, `RUN_DB_MIGRATION`, migration
  preflight replay, checksum stability, a fresh pre-migration backup, MySQL
  client import with shell bypass, `SELECT 1` verification, and sanitized
  audit records.
- PostgreSQL migration execution, project-code migrations, schema diff
  generators, arbitrary SQL, rollback automation, cleanup automation, and WLS
  reload remain intentionally disabled.
- Browser proof verifies the ready panel state and guard UI without submitting
  the destructive migration form against a production database.
- Disposable MariaDB proof verifies the real execution path, pre-migration
  backup, post-import readback, and sanitized audit chain.

Validation:

```text
gitnexus impact WlsDbManager -r dev-workspace -d upstream --depth 1
gitnexus impact WlsDatabaseBackupPlanService -r dev-workspace -d upstream --depth 1
gitnexus impact WlsDatabaseMigrationPreflightService -r dev-workspace -d upstream --depth 1
gitnexus impact WlsDatabaseProjectHealthService -r dev-workspace -d upstream --depth 1
php -l app/code/Weline/DbManager/Service/WlsDatabaseMigrationExecutionService.php
php -l app/code/Weline/DbManager/Service/WlsDatabaseBackupPlanService.php
php -l app/code/Weline/DbManager/Service/WlsDatabaseMigrationPreflightService.php
php -l app/code/Weline/DbManager/Service/WlsDatabaseProjectHealthService.php
php -l app/code/Weline/DbManager/Controller/Backend/WlsDbManager.php
php -l app/code/Weline/DbManager/view/templates/Backend/WlsDbManager/index.phtml
php dev/ai/codex/tasks/2026-06-22/2026-06-22-0504-wls-db-migration-execution-guarded-adapter/migration-execution-guard-probe.php
docker network create wls-migration-net
docker run -d --name wls-migration-mariadb --network wls-migration-net mariadb:11.4
docker run --rm --network wls-migration-net -v E:/WelineFramework/DEV-workspace:/work -w /work php:8.4-cli bash -lc "... migration-execution-mariadb-harness.php"
docker rm -f wls-migration-mariadb
docker network rm wls-migration-net
php bin/w server:start ai-test-wls-db-migration-10042 --port=10042 --no-ssl -c 2 --worker-memory-limit=512M
php dev/ai/codex/tasks/2026-06-22/2026-06-22-0504-wls-db-migration-execution-guarded-adapter/artifacts/migration-execution-browser-fixture.php prepare
Codex in-app Browser smoke against the prepared DbManager URL
php dev/ai/codex/tasks/2026-06-22/2026-06-22-0504-wls-db-migration-execution-guarded-adapter/artifacts/migration-execution-browser-fixture.php cleanup
php bin/w server:stop ai-test-wls-db-migration-10042
php bin/w server:status ai-test-wls-db-migration-10042
git diff --check -- <touched file set>
```

Results:

- GitNexus impact returned LOW risk for `WlsDbManager`,
  `WlsDatabaseBackupPlanService`, `WlsDatabaseMigrationPreflightService`, and
  `WlsDatabaseProjectHealthService`.
- PHP lint passed for the new execution service, touched plan/preflight/health
  services, controller, template, guard probe, browser fixture, and MariaDB
  harness. The local PHP CLI still prints duplicate-extension warnings before
  lint output.
- DbManager i18n CSV validation passed with `en_US.csv rows=781 bad=0` and
  `zh_Hans_CN.csv rows=781 bad=0`.
- Marketplace meta JSON validation passed with `tags=17`, `capabilities=12`,
  `capability:database-migration-execute`, and
  `database.migration_execute`.
- Migration guard probe returned `passed=true` with 20 assertions covering
  MySQL `ready_to_migration_execute`, dual confirmation phrases, safe artifact
  checks, and PostgreSQL execution remaining preflight-only.
- Disposable Docker MariaDB 11.4 execution proof passed with `passed=true`,
  source artifact `harness-migration-source-20260622-053113-ed0bb2.sql`,
  pre-migration backup
  `pre-migration-wls_migration-20260622-053113-c732dc14.sql`, restored rows
  `alpha,beta`, verification count 1, audit events
  `backup_executed`, `migration_preflight_passed`, `backup_executed`,
  `migration_executed`, and adapter `mysql_import`.
- In-app Browser smoke passed on dedicated instance
  `ai-test-wls-db-migration-10042`: desktop `1280x900` light/dark and phone
  `390x844` dark/light rendered the standalone DbManager shell with
  `ready_to_migration_execute`, runnable preflight and execution forms,
  placeholders `CHECK_DB_MIGRATION` and `RUN_DB_MIGRATION`, enabled
  `执行迁移导入` submit button, no login fallback, no visible fatal text, no
  console errors, and no horizontal overflow.
- Manual screenshot review covered desktop light and phone dark. The guarded
  cards, stacked mobile form, badges, and dark theme remained readable without
  overlap.
- Cleanup removed the temporary Project Profile and `codex-migration-ui-*`
  artifacts, stopped the WLS instance, final status reported
  `全部停止 (0/0)`, port `10042` was closed, and the disposable Docker
  container/network were removed.

Evidence:

```text
dev/ai/codex/tasks/2026-06-22/2026-06-22-0504-wls-db-migration-execution-guarded-adapter/migration-execution-guard-probe.php
dev/ai/codex/tasks/2026-06-22/2026-06-22-0504-wls-db-migration-execution-guarded-adapter/artifacts/migration-execution-mariadb-harness.php
dev/ai/codex/tasks/2026-06-22/2026-06-22-0504-wls-db-migration-execution-guarded-adapter/artifacts/migration-execution-browser-smoke-result.json
dev/ai/codex/tasks/2026-06-22/2026-06-22-0504-wls-db-migration-execution-guarded-adapter/artifacts/migration-desktop-1280-light.png
dev/ai/codex/tasks/2026-06-22/2026-06-22-0504-wls-db-migration-execution-guarded-adapter/artifacts/migration-desktop-1280-dark.png
dev/ai/codex/tasks/2026-06-22/2026-06-22-0504-wls-db-migration-execution-guarded-adapter/artifacts/migration-phone-390-dark.png
dev/ai/codex/tasks/2026-06-22/2026-06-22-0504-wls-db-migration-execution-guarded-adapter/artifacts/migration-phone-390-light.png
```

## 2026-06-22 DbManager Guarded SQL Apply Slice

Scope:

- Added a guarded `sql_apply` action to the standalone WLS Database Manager
  backup/restore/migration plan surface.
- SQL Apply reads only existing `.sql` / `.sql.gz` artifacts by safe filename
  from `var/backups/wls/db-manager/database`.
- Execution requires an enabled mysql/pgsql Project Profile, checkbox
  confirmation, exact `RUN_DB_SQL_APPLY`, additive DDL allowlist, fresh
  pre-apply backup, PDO execution, verification query, and sanitized audit.
- Browser proof verifies the ready panel state and guard UI without submitting
  SQL Apply against a production database.
- Disposable MariaDB proof verifies the real execution path, pre-apply backup,
  post-apply readback, and sanitized audit chain.

Validation:

```text
gitnexus impact WlsDbManager -r dev-workspace -d upstream --depth 1
gitnexus impact WlsDatabaseBackupPlanService -r dev-workspace -d upstream --depth 1
gitnexus impact WlsDatabaseBackupExecutionService -r dev-workspace -d upstream --depth 1
php -l app/code/Weline/DbManager/Service/WlsDatabaseSqlApplyExecutionService.php
php -l app/code/Weline/DbManager/Service/WlsDatabaseBackupPlanService.php
php -l app/code/Weline/DbManager/Controller/Backend/WlsDbManager.php
php -l app/code/Weline/DbManager/view/templates/Backend/WlsDbManager/index.phtml
php dev/ai/codex/tasks/2026-06-22/2026-06-22-0415-wls-db-sql-apply-guarded-adapter/sql-apply-guard-probe.php
php dev/ai/codex/tasks/2026-06-22/2026-06-22-0415-wls-db-sql-apply-guarded-adapter/static-validate.php
php bin/w server:start ai-test-wls-db-sql-apply-10041 -p 10041 -c 1 --no-ssl --supervisor false --worker-memory-limit=512M --dispatcher-memory-limit=512M
php dev/ai/codex/tasks/2026-06-22/2026-06-22-0415-wls-db-sql-apply-guarded-adapter/artifacts/sql-apply-browser-fixture.php prepare
node --check dev/ai/codex/tasks/2026-06-22/2026-06-22-0415-wls-db-sql-apply-guarded-adapter/artifacts/sql-apply-browser-smoke.mjs
node dev/ai/codex/tasks/2026-06-22/2026-06-22-0415-wls-db-sql-apply-guarded-adapter/artifacts/sql-apply-browser-smoke.mjs
php dev/ai/codex/tasks/2026-06-22/2026-06-22-0415-wls-db-sql-apply-guarded-adapter/artifacts/sql-apply-browser-fixture.php cleanup
php bin/w server:stop ai-test-wls-db-sql-apply-10041
php bin/w server:status ai-test-wls-db-sql-apply-10041
curl.exe -s -o NUL -w "%{http_code}" http://127.0.0.1:10041/
docker network create wls-sql-apply-net
docker run -d --name wls-sql-apply-mariadb --network wls-sql-apply-net mariadb:11.4
docker run --rm --network wls-sql-apply-net -v E:/WelineFramework/DEV-workspace:/work -w /work php:8.4-cli bash -lc "... sql-apply-mariadb-harness.php"
docker rm -f wls-sql-apply-mariadb
docker network rm wls-sql-apply-net
```

Results:

- GitNexus impact returned LOW risk for `WlsDbManager`,
  `WlsDatabaseBackupPlanService`, and `WlsDatabaseBackupExecutionService`.
- PHP lint passed for the new SQL Apply service, touched plan service,
  controller, template, fixture, guard probe, and static validator. The local
  PHP CLI still prints duplicate-extension warnings before the lint result.
- SQL Apply guard probe returned `passed=true` with `13` assertions: ready
  `sql_apply` plan, `RUN_DB_SQL_APPLY` phrase, missing/unsafe artifact blocks,
  Project Profile requirement, safe artifact checks, additive DDL allowlist,
  and destructive keyword blocks.
- Static validation passed with DbManager i18n rows
  `en_US=717` / `zh_Hans_CN=717`, marketplace `tags=16`,
  `capabilities=11`, `capability:database-sql-apply`, and
  `database.sql_apply`.
- Logged-in Chrome CDP browser smoke passed on dedicated instance
  `ai-test-wls-db-sql-apply-10041`: desktop `1280` and phone `390`,
  light/dark themes, standalone DbManager shell, `ready_to_sql_apply`,
  `data-wdb-sql-can-apply=1`, expected SQL artifact, `RUN_DB_SQL_APPLY`,
  additive allowlist copy, enabled submit button, no login fallback, no fatal
  text, no horizontal overflow, and fitting controls.
- Manual screenshot review covered desktop dark and phone light; the SQL Apply
  boundary was readable, aligned, and mobile-safe.
- Cleanup removed the temporary Project Profile and SQL artifact, stopped the
  WLS instance, final status reported `全部停止 (0/0)`, and port `10041`
  returned `000`.

- Disposable Docker MariaDB 11.4 execution proof passed with `passed=true`,
  `plan_state=ready_to_sql_apply`, 3 additive statements, generated pre-apply
  backup artifact `wls_sql_apply-pre-sql-apply-20260622-045025-e55dde.sql`,
  matching sidecar metadata, row readback `alpha`, audit events
  `backup_executed` and `sql_apply_executed`, plus container/network cleanup.

Evidence:

```text
dev/ai/codex/tasks/2026-06-22/2026-06-22-0415-wls-db-sql-apply-guarded-adapter/sql-apply-guard-probe.php
dev/ai/codex/tasks/2026-06-22/2026-06-22-0415-wls-db-sql-apply-guarded-adapter/static-validate.php
dev/ai/codex/tasks/2026-06-22/2026-06-22-0415-wls-db-sql-apply-guarded-adapter/artifacts/sql-apply-browser-smoke-result.json
dev/ai/codex/tasks/2026-06-22/2026-06-22-0415-wls-db-sql-apply-guarded-adapter/artifacts/sql-apply-desktop-1280-light.png
dev/ai/codex/tasks/2026-06-22/2026-06-22-0415-wls-db-sql-apply-guarded-adapter/artifacts/sql-apply-desktop-1280-dark.png
dev/ai/codex/tasks/2026-06-22/2026-06-22-0415-wls-db-sql-apply-guarded-adapter/artifacts/sql-apply-phone-390-light.png
dev/ai/codex/tasks/2026-06-22/2026-06-22-0415-wls-db-sql-apply-guarded-adapter/artifacts/sql-apply-phone-390-dark.png
dev/ai/codex/tasks/2026-06-22/2026-06-22-0415-wls-db-sql-apply-guarded-adapter/artifacts/sql-apply-mariadb-harness.php
```

## 2026-06-21 Worker Self-Heal Observability Follow-up

Scope:

- Added runtime context support to worker `status_report` and `exit_reason`
  IPC payloads without changing lifecycle policy.
- Master now sanitizes child runtime snapshots, stores `last_status_report`
  and `last_exit_snapshot`, and emits readable diagnostics for missing/stale
  worker slots.
- This follows `WLS-PANEL-SOAK-002` and `WLS-PANEL-MEM-003`: the previous
  unexplained worker replacements were already classified as
  `memory_pressure_drain`; this slice makes the next replacement easier to
  explain from Master logs.

Validation:

```text
gitnexus impact statusReport -r dev-workspace -d upstream --depth 2
gitnexus impact exitReason -r dev-workspace -d upstream --depth 2
gitnexus impact drainingComplete -r dev-workspace -d upstream --depth 2
php -l app\code\Weline\Server\IPC\ControlMessage.php
php -l app\code\Weline\Server\Service\ServiceOrchestrator.php
php -l app\code\Weline\Server\bin\worker.php
php bin\w server:start ai-test-wls-worker-observe-20260621-1959 -p 10072 --no-ssl -c 2 --worker-memory-limit=512M
php bin\w server:status ai-test-wls-worker-observe-20260621-1959
curl.exe -sS "http://127.0.0.1:10072/_wls/health?detail=1&memory=1"
curl.exe -I "http://127.0.0.1:10072/"
php bin\w server:stop ai-test-wls-worker-observe-20260621-1959
php bin\w server:status ai-test-wls-worker-observe-20260621-1959
curl.exe -I "http://127.0.0.1:10072/"
rg -n "ai-test-wls-worker-observe-20260621-1959.*child_exit_reason|child_exit_reason.*ai-test-wls-worker-observe-20260621-1959" var\log\wls-startup-trace.log
```

Results:

- GitNexus returned LOW risk for the indexed IPC factories:
  `statusReport` has one direct caller (`ControlClient::sendStatusReport`),
  `exitReason` has no indexed upstream callers, and `drainingComplete` has one
  direct caller (`ControlClient::sendDrainingComplete`). Private
  `ServiceOrchestrator` methods were not found in the current index.
- PHP lint passed for the three touched runtime files. The local PHP runtime
  still prints duplicate-extension warnings before the lint result.
- Dedicated instance `ai-test-wls-worker-observe-20260621-1959` started on
  port `10072` with Master PID `43624`, Dispatcher PID `42172`, Worker #1 PID
  `49968`, and Worker #2 PID `28896`.
- Health returned `status=healthy`; `/_wls/health?detail=1&memory=1` included
  memory diagnostics such as allocated/used/peak memory, static file cache,
  GC status, ObjectManager diagnostics, and StateManager stats.
- Root `curl -I` returned `HTTP/1.1 200 OK` with `X-Wls-Worker-Id`,
  `X-Wls-Worker-Port`, `X-Wls-Worker-Pid`, `X-Wls-Instance`, request count,
  memory, and uptime headers.
- Stop completed through IPC. Final status reported the instance stopped, and
  the final curl to port `10072` failed to connect as expected.
- `var/log/wls-startup-trace.log:98360-98362` recorded dispatcher and both
  worker `child_exit_reason` rows for the stopped instance.
- Direct IPC factory probes with Composer autoload proved that allowed context
  keys are retained and reserved/invalid/nested context keys are filtered:
  `exitReason` decoded to `{"type":"exit_reason","reason":"x","code":2,"memory_used":123,"event":"probe"}`;
  `statusReport` decoded to `{"type":"status_report","connections":1,"memory":2,"requests":3,"worker_id":7,"event":"probe"}`.

## 2026-06-21 DbManager PostgreSQL Plain SQL Restore Reset Adapter Slice

Scope:

- Enables PostgreSQL `.sql` / `.sql.gz` restore execution only behind a
  public-schema reset adapter.
- The restore plan must expose `restore_reset_required=true`,
  `restore_reset_mode=public_schema`, and
  `restore_reset_confirmation_phrase=RESET_PG_SCHEMA`.
- Destructive execution still requires `CHECK_DB_RESTORE`,
  `RUN_DB_RESTORE`, and `RESET_PG_SCHEMA`, plus a fresh custom-format
  pre-restore `.dump` backup.
- The adapter blocks unexpected user schemas, non-idle sessions, prepared
  transactions, and competing advisory locks before resetting `public` and
  streaming the artifact through `psql`.

Validation:

```text
gitnexus impact executeFromPanel -r dev-workspace -d upstream --depth 2
gitnexus impact buildPlan -r dev-workspace -d upstream --depth 2
php -l app\code\Weline\DbManager\Service\WlsDatabaseBackupPlanService.php
php -l app\code\Weline\DbManager\Service\WlsDatabaseRestoreExecutionService.php
php -l app\code\Weline\DbManager\Service\Adapter\WlsDatabasePostgreSqlPlainRestoreAdapter.php
php -l app\code\Weline\DbManager\view\templates\Backend\WlsDbManager\index.phtml
php -l dev\ai\codex\tasks\2026-06-21\2026-06-21-2017-wls-db-postgres-plain-restore-schema-reset\artifacts\harness-pg-plain-restore.php
php dev\ai\codex\tasks\2026-06-21\2026-06-21-2017-wls-db-postgres-plain-restore-schema-reset\artifacts\probe-pg-plain-restore-plan.php
docker run -d --name wls-pg-plain-restore-2017 -e POSTGRES_PASSWORD=WlsRestoreProbe!20260621 -e POSTGRES_DB=wls_plain_restore_probe -p 127.0.0.1:15439:5432 postgres:15-alpine
php dev\ai\codex\tasks\2026-06-21\2026-06-21-2017-wls-db-postgres-plain-restore-schema-reset\artifacts\harness-pg-plain-restore.php
docker rm -f wls-pg-plain-restore-2017
```

Results:

- GitNexus returned target-not-found for the new/changed restore symbols in
  the current index, so impact was recorded as an index miss and local
  call-chain review plus executable probes were used.
- PHP lint passed for the touched services, new adapter, template, and harness.
  The local PHP runtime still prints duplicate-extension warnings, but returned
  no syntax errors.
- The focused plan probe returned:

```json
{
  "passed": true,
  "checks": {
    "plain_state": "ready_to_restore_execute",
    "plain_can_restore_execute": true,
    "plain_reset_required": true,
    "plain_reset_phrase": "RESET_PG_SCHEMA",
    "custom_state": "ready_to_restore_execute",
    "custom_can_restore_execute": true,
    "custom_reset_required": false
  }
}
```

- The disposable PostgreSQL 15 harness returned:

```json
{
  "passed": true,
  "artifact": "harness-pg-plain-restore-20260621-203929-3d93bf.sql",
  "pre_restore_artifact": "pre-restore-wls_plain_restore_probe-20260621-203929-fcf86b5c.dump",
  "rows_after_restore": [
    "alpha",
    "beta"
  ],
  "audit_events": [
    "restore_preflight_passed",
    "backup_executed",
    "restore_executed"
  ]
}
```

- Cleanup removed the temporary container and generated Database Manager
  artifacts. Follow-up checks found no matching container or backup artifacts.
- The source plain SQL artifact in the successful harness was hand-authored but
  Database Manager-compatible. A local `pg_dump 18.1` plain SQL artifact against
  the available PG15 container emitted `SET transaction_timeout`, which PG15
  cannot replay. That is a residual compatibility risk for a later
  dump/server-version guard, not a failure of the reset adapter execution path.
- Focused browser visual smoke for the updated reset restore execution form is
  now covered by task
  `2026-06-21-2055-wls-db-postgres-reset-restore-visual-smoke`.

## 2026-06-21 DbManager PostgreSQL Reset Restore Form Browser Slice

Scope:

- Closed the visual/browser evidence gap for the PostgreSQL plain SQL
  public-schema reset restore execution form.
- This slice verifies the rendered standalone WLS Database Manager panel after
  backend login and an enabled Project Profile. It does not submit the
  destructive restore form.
- The temporary Project Profile was created only for browser form-state proof
  and saved back as disabled before cleanup.

Runtime:

```text
php bin\w server:start ai-test-wls-db-reset-vis-10034 -p 10034 --no-ssl -c 1 --supervisor false --worker-memory-limit=512M
```

Observed runtime:

- Instance: `ai-test-wls-db-reset-vis-10034`
- URL: `http://127.0.0.1:10034`
- Backend entry: `U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8`
- Project Profile key used for the smoke:
  `project:codex-reset-smoke-10034`

Browser validation:

- Logged in through the backend auth gate with the local development backend
  credentials.
- Saved a temporary enabled Project Profile through the DbManager panel so the
  restore plan could use the project-level PostgreSQL profile path.
- Opened:

```text
weline_dbmanager/backend/wls-db-manager?operation=backup-plan&project_id=codex-reset-smoke-10034&project_type=codex&backup_action=restore_database&backup_scope=schema_and_data&backup_artifact=codex-restore-reset.sql#backup-plan
```

Results:

- Restore execution form rendered with
  `data-wdb-restore-can-execute="1"`.
- Required confirmation boundaries were visible:
  `CHECK_DB_RESTORE`, `RUN_DB_RESTORE`, and `RESET_PG_SCHEMA`.
- PostgreSQL reset UI rendered
  `confirm_pg_schema_reset_phrase` with placeholder `RESET_PG_SCHEMA` and
  public-schema reset copy.
- Desktop and mobile `390px` checks found no `Fatal error`, `Warning:`,
  `Notice:`, or `Parse error` text and no horizontal overflow.
- Light and dark theme checks rendered correctly. Dark mode used body
  background `rgb(16, 25, 34)` and text `rgb(230, 238, 248)`.
- Focused mobile form screenshots were manually reviewed in light and dark
  states. Confirmation fields, checkboxes, reset mode copy, and guarded warning
  stayed readable without overlap.
- Temporary Profile cleanup passed: `project:codex-reset-smoke-10034` was saved
  back as disabled through the panel.
- Browser viewport override was reset after the smoke.
- Runtime cleanup passed: `server:stop ai-test-wls-db-reset-vis-10034` drained
  the Dispatcher and Worker through IPC; final `server:status` reported
  `全部停止 (0/0)`, and a final curl to port `10034` failed to connect as
  expected.

Evidence:

```text
dev/ai/codex/tasks/2026-06-21/2026-06-21-2055-wls-db-postgres-reset-restore-visual-smoke/artifacts/browser-smoke-result.json
dev/ai/codex/tasks/2026-06-21/2026-06-21-2055-wls-db-postgres-reset-restore-visual-smoke/artifacts/dbmanager-restore-reset-desktop-light.png
dev/ai/codex/tasks/2026-06-21/2026-06-21-2055-wls-db-postgres-reset-restore-visual-smoke/artifacts/dbmanager-restore-reset-desktop-dark.png
dev/ai/codex/tasks/2026-06-21/2026-06-21-2055-wls-db-postgres-reset-restore-visual-smoke/artifacts/dbmanager-restore-reset-mobile-light-form.png
dev/ai/codex/tasks/2026-06-21/2026-06-21-2055-wls-db-postgres-reset-restore-visual-smoke/artifacts/dbmanager-restore-reset-mobile-dark-form-visible.png
```

## 2026-06-21 DbManager Explicit Slave Create / Remove Slice

Scope:

- Added dedicated list-level `db.slaves` creation and removal flows to the WLS
  Database Manager plugin.
- Ordinary Env Apply remains scoped to the selected existing master/default/slave
  target; it does not create, delete, or reorder slave entries.
- Slave creation requires `confirm_slave_create=1`, exact `CREATE_DB_SLAVE`,
  a safe lowercase slave key, an enabled Project Database Profile, and an env
  backup before writing.
- Slave removal requires `confirm_slave_remove=1`, exact `REMOVE_DB_SLAVE`, an
  existing slave key, and an env backup before writing.
- Both flows write sanitized audit events and may optionally request WLS reload.

Validation:

```text
php -l app\code\Weline\DbManager\Service\WlsDatabaseEnvApplyService.php
php -l app\code\Weline\DbManager\Controller\Backend\WlsDbManager.php
php -l app\code\Weline\DbManager\view\templates\Backend\WlsDbManager\index.phtml
php dev\ai\codex\tasks\2026-06-21\2026-06-21-1826-wls-db-explicit-slave-create-remove\slave-create-remove-probe.php
php dev\ai\codex\tasks\2026-06-21\2026-06-21-1826-wls-db-explicit-slave-create-remove\validate-slave-assets.php
php bin/w setup:upgrade --stage=route_update -m Weline_DbManager --skip-env-check --skip-composer-dump --skip-classmap --skip-background-optimize
rg -n "slave-create|slave-remove|postSlave|post-slave" generated\routers
git diff --check
```

Results:

- PHP lint passed for the touched service, controller, and template. The local
  PHP runtime still prints duplicate-extension warnings, but returned no syntax
  errors.
- The service probe returned `ok=true`, created and removed a temporary slave,
  blocked duplicate create, created two env backups, recorded
  `env_slave_created` and `env_slave_removed`, and did not leak the probe
  password into audit output.
- DbManager i18n CSV validation passed with `en_US=560`, `zh_Hans_CN=560`, no
  bad rows, no missing required strings, no duplicate keys, and row-count parity.
- Route refresh generated:
  `weline_dbmanager/backend/wls-db-manager/slave-create::POST` and
  `weline_dbmanager/backend/wls-db-manager/slave-remove::POST` in
  `generated\routers\backend_pc.php`.
- Static scan found no `sleep()`, `die()`, `exit()`, browser `alert()`,
  `confirm()`, or `prompt()` calls in the touched DbManager files.
- `git diff --check` returned exit 0 with CRLF normalization warnings only.

Runtime route/auth-gate smoke:

```text
php bin/w server:start ai-test-wls-db-slave-ui-10023 -p 10023 --no-ssl -c 1 --supervisor false --worker-memory-limit=512M
php bin/w server:status ai-test-wls-db-slave-ui-10023
curl.exe -I http://127.0.0.1:10023/
curl.exe -I http://p11005ce4.weline.test:10023/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/weline_dbmanager/backend/wls-db-manager
curl.exe -i -X POST http://p11005ce4.weline.test:10023/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/weline_dbmanager/backend/wls-db-manager/slave-create
curl.exe -i -X POST http://p11005ce4.weline.test:10023/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/weline_dbmanager/backend/wls-db-manager/slave-remove
php bin/w server:stop ai-test-wls-db-slave-ui-10023
php bin/w server:status ai-test-wls-db-slave-ui-10023
```

Results:

- Dedicated instance `ai-test-wls-db-slave-ui-10023` reached all-running state
  with one Worker and one Dispatcher on port `10023`.
- Root `curl` returned `HTTP/1.1 200 OK`.
- DbManager GET returned `HTTP/1.1 302 Found` to backend login when no backend
  session was present.
- Both POST operation routes returned `HTTP/1.1 302 Found` to backend login
  when no backend session was present, proving they enter the authorization
  chain rather than 404/500.
- Final cleanup reported `全部停止 (0/0)`, and a final port probe timed out as
  expected.

Logged-in browser visual proof:

```text
node --check dev\ai\codex\tasks\2026-06-21\2026-06-21-1909-wls-db-slave-management-visual-smoke\dbmanager-slave-management-visual-smoke.mjs
php bin/w server:start ai-test-wls-db-slave-vis-10030 -p 10030 --no-ssl -c 1 --supervisor false --worker-memory-limit=512M
curl.exe -I http://127.0.0.1:10030/
curl.exe -I http://127.0.0.1:10030/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/weline_dbmanager/backend/wls-db-manager
node dev\ai\codex\tasks\2026-06-21\2026-06-21-1909-wls-db-slave-management-visual-smoke\dbmanager-slave-management-visual-smoke.mjs
php bin/w server:stop ai-test-wls-db-slave-vis-10030
php bin/w server:status ai-test-wls-db-slave-vis-10030
curl.exe -I --max-time 5 http://127.0.0.1:10030/
```

Results:

- Dedicated instance `ai-test-wls-db-slave-vis-10030` rendered the logged-in
  DbManager standalone plugin shell through local Chrome CDP.
- Browser smoke passed for `#slave-management` on desktop `1280` and phone
  `390`, in light and dark themes.
- Assertions passed: no backend-login fallback, `[data-wls-db-manager-shell]`
  present, `[data-wdb-slave-management]` present, shell theme matched expected
  light/dark state, no fatal text, no horizontal overflow, and buttons fit.
- The current real runtime had no enabled Project Database Profile and no
  `db.slaves` entries, so the correct UI state was guarded:
  `data-wdb-slave-can-create="0"`, `data-wdb-slave-count="0"`, no destructive
  create/remove form exposed, and visible Project Database Profile guidance.
- Screenshots and JSON evidence are stored in
  `dev/ai/codex/tasks/2026-06-21/2026-06-21-1909-wls-db-slave-management-visual-smoke/artifacts/`.
- Cleanup passed: final `server:status` reported `全部停止 (0/0)`, and the
  final curl to port `10030` failed to connect as expected.

## Scope

- Verify that the standalone WLS Panel can still be reached through backend
  authorization and then render outside the normal framework backend chrome.
- Verify Dashboard, Security, Marketplace, DbManager, PhpManager, FileManager,
  and Deploy WLS surfaces in the current dirty worktree.
- Verify light/dark theme switching, plugin theme synchronization, desktop and
  mobile responsive layout, and typed WLS marketplace/plugin links.
- Record runtime cleanup for the dedicated AI WLS instance.

## Runtime

Started a dedicated AI test instance:

```text
php bin/w server:start ai-test-wls-panel-refresh-10023 -p 10023 --no-ssl
```

Observed runtime:

- Instance: `ai-test-wls-panel-refresh-10023`
- URL: `http://127.0.0.1:10023`
- Master PID: `34844`
- Worker count: `8`
- Dispatcher: `10023`
- Worker ports: `26475-26482`
- Topology: Windows auto-selected Dispatcher passthrough mode.
- Shared Session Server and Memory Service were reused.

The startup command printed local diagnostic warnings that `chcp`,
`powershell`, `netstat`, and `tasklist` were not available in this shell PATH.
They did not block startup or page rendering, but they keep some Windows
diagnostic paths degraded for this shell.

## Auth Gate Precheck

Commands:

```text
curl.exe -I http://127.0.0.1:10023/
curl.exe -I http://127.0.0.1:10023/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/server/backend/wls-panel
curl.exe -I http://127.0.0.1:10023/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/server/backend/wls-panel/marketplace
```

Results:

- `/` returned `HTTP/1.1 200 OK`.
- WLS Panel Dashboard and Marketplace returned `HTTP/1.1 302 Found` to
  backend login when no backend session was present.
- `php bin/w http:request ... -b` also reported no valid backend session before
  browser login. This confirmed the route exists and remains authorization
  gated.

## Browser Login

The local browser opened the WLS Panel URL and was redirected to the backend
login page:

```text
http://127.0.0.1:10023/.../admin/login?no_access_reason=not_logged_in
```

The login page rendered without CAPTCHA. Login with the local development
credentials `admin/admin` succeeded and landed in the framework backend. The
browser then opened:

```text
http://127.0.0.1:10023/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/CNY/zh_Hans_CN/server/backend/wls-panel
```

## Standalone Panel Proof

Dashboard DOM proof:

- Page title: `Weline_Server`
- H1: `仪表盘`
- Standalone shell: `[data-wls-shell="standalone"]`
- Backend chrome absent: `.vertical-menu`, `.navbar-header`, `.app-menu`, and
  `.page-title-box` were not present.
- Navigation links: Dashboard, Projects, Gateway, Security, Marketplace, WLS
  Database Manager, WLS PHP Manager, WLS File Manager, WLS Deploy.
- Header actions included `项目后台` and `返回框架后台`.
- Marketplace link used:
  `appstore/backend?tag=module%3Awls&surface=backend&wls_panel_return=1`.
- Operation capability cards exposed typed tags such as `module:wls`,
  `custom:wls-php-manager`, `custom:wls-database-manager`,
  `custom:wls-file-manager`, and `custom:wls-deploy`.

## Responsive And Theme Proof

Screenshots were saved under:

```text
var/tmp/wls-panel-refresh/
```

Key screenshots:

```text
desktop-light.png
desktop-dark.png
mobile-top.png
mobile-dark.png
mobile-light.png
tablet-light.png
```

Desktop 1440x900:

- Light mode: `data-wls-theme="light"`, `colorScheme=light`.
- Dark mode after toggle: `data-wls-theme="dark"`, `colorScheme=dark`,
  `aria-pressed="true"`.
- Sidebar and main content did not overlap.
- Corrected horizontal overflow check: `innerWidth=1440`,
  `maxScrollWidth=1440`, `horizontalOverflow=false`.

Mobile 390x844:

- Top navigation collapsed above content.
- Header actions stacked as full-width controls: theme, project admin, return
  to framework backend.
- Sidebar rect: `x=0`, `y=0`, `width=390`, `height=555`.
- Main rect started at `y=555`, so sidebar and content did not overlap.
- Corrected horizontal overflow check: `innerWidth=390`,
  `maxScrollWidth=390`, `horizontalOverflow=false`.

## Route Sweep

The browser route sweep rendered each page after backend login:

| Surface | URL Path | Shell Proof | Result |
| --- | --- | --- | --- |
| Dashboard | `server/backend/wls-panel` | `[data-wls-shell="standalone"]` | Passed; no fatal text; no horizontal overflow. |
| Security | `server/backend/wls-panel/security` | `[data-wls-shell="standalone"]` | Passed; security rules and attack log sections present. |
| Marketplace | `server/backend/wls-panel/marketplace` | `[data-wls-shell="standalone"]` | Passed; online AppStore links keep `tag=module:wls`. |
| DbManager | `weline_dbmanager/backend/wls-db-manager` | `[data-wls-db-manager-shell]` | Passed; DB profile, lifecycle, and backup headings present. |
| PhpManager | `weline_phpmanager/backend/wls-php-manager` | `[data-wls-php-manager-shell]` | Passed; PHP runtime/profile/php.ini headings present. |
| FileManager | `weline_filemanager/backend/wls-file-manager` | `[data-wls-file-manager-shell]` | Passed; path policy, browse, and controlled write headings present. |
| Deploy | `deploy/backend/wls-deploy` | `[data-wls-deploy-shell]` | Passed; webhook/profile/preflight/manual release headings present. |

DbManager contains database credential fields that can resemble login inputs
when searched only by `input[name="username"]` or `input[name="password"]`; the
page title, H1, and DbManager shell confirmed it was not a backend-login
fallback.

## Plugin Theme Synchronization

Browser proof:

- DbManager had one `[data-wdb-theme-toggle]`.
- DbManager in dark mode showed:
  `data-theme="dark"`, root `data-wls-panel-theme="dark"`, body
  `data-wls-panel-theme="dark"`, and `aria-pressed="true"`.
- After opening PhpManager from that state, PhpManager inherited:
  `data-theme="dark"`, root `data-wls-panel-theme="dark"`, and body
  `data-wls-panel-theme="dark"`.

This proves `wls-panel-plugins.js` synchronizes theme state across standalone
plugin pages, not just inside the Dashboard.

## External Browser Note

The previously provided browser URL:

```text
http://pf9938bb3.weline.test:9608/puKoQDiP3210Lh2v6zKjWzN3bdxXM9B2/server/backend/panel-marketplace
```

could not be opened by the in-app browser during this pass because the browser
reported `net::ERR_BLOCKED_BY_CLIENT`. The dedicated local instance proof above
is therefore the acceptance evidence for this run.

## Cleanup

Commands:

```text
php bin/w server:stop ai-test-wls-panel-refresh-10023
php bin/w server:status ai-test-wls-panel-refresh-10023
```

Results:

- `server:stop` drained the Dispatcher and all eight Workers through IPC.
- Final status reported `全部停止 (0/0)`.

## QA Gate

Recommendation: PASS for the current WLS Panel integrated smoke scope.

Residual risks:

- Direct-listen shared-port verification still requires Linux/macOS with
  `SO_REUSEPORT`; this Windows shell correctly falls back to Dispatcher mode.
- This pass did not execute destructive plugin operations such as DB lifecycle
  apply, file writes, PHP ini apply, or Deploy release execution. It only
  verified the current integrated panel surfaces, navigation, themes, and
  rendering.

## 2026-06-21 DbManager Migration Preflight Slice

Scope:

- Added a read-only `migration_dry_run` preflight boundary to the WLS Database
  Manager plugin.
- The boundary is guarded by `CHECK_DB_MIGRATION`, enabled Project Profile
  state, safe migration target reference, and an existing Database Manager
  backup artifact with adjacent metadata.
- The service recalculates SHA-256, verifies byte size, driver, database, and
  artifact name, probes readability, classifies the target risk, and writes
  sanitized `migration_preflight_passed` / `migration_preflight_failed` audit
  events.
- Migration execution, schema diff execution, migration runner, SQL apply,
  restore, env write, rollback, cleanup automation, and WLS reload remain
  disabled.

Validation:

```text
php -l app\code\Weline\DbManager\Service\WlsDatabaseMigrationPreflightService.php
php -l app\code\Weline\DbManager\Service\WlsDatabaseBackupPlanService.php
php -l app\code\Weline\DbManager\Controller\Backend\WlsDbManager.php
php -l app\code\Weline\DbManager\view\templates\Backend\WlsDbManager\index.phtml
php -l dev\ai\codex\tasks\2026-06-21\2026-06-21-1524-wls-db-migration-preflight-boundary\migration-preflight-probe.php
php dev\ai\codex\tasks\2026-06-21\2026-06-21-1524-wls-db-migration-preflight-boundary\migration-preflight-probe.php
node --check dev\ai\codex\tasks\2026-06-21\2026-06-21-1553-wls-db-migration-preflight-visual-smoke\dbmanager-migration-preflight-visual-smoke.mjs
node dev\ai\codex\tasks\2026-06-21\2026-06-21-1553-wls-db-migration-preflight-visual-smoke\dbmanager-migration-preflight-visual-smoke.mjs
```

Results:

- PHP lint passed for all touched PHP/template/probe files. The local PHP
  runtime printed duplicate-extension warnings, but returned no syntax errors.
- The migration preflight probe returned:

```json
{
    "ok": true,
    "plan_state": "ready_to_migration_preflight",
    "risk": "release_reference",
    "audit_events": [
        "migration_preflight_passed",
        "migration_preflight_failed",
        "migration_preflight_failed",
        "migration_preflight_failed"
    ]
}
```

- DbManager i18n CSV parse passed with `en_US=522` and `zh_Hans_CN=522`.
- Marketplace meta JSON parse passed with `tags=13` and `capabilities=8`,
  including `capability:database-migration-preflight`.
- Static scans found no `sleep()`, `die()`, `exit()`, browser `alert()`,
  `confirm()`, or `prompt()` calls in the touched DbManager files.
- `git diff --check` reported CRLF normalization warnings only.
- A focused logged-in Chrome CDP browser smoke passed on dedicated instance
  `ai-test-wls-db-migration-vis-10024` / `http://127.0.0.1:10024`.
- The browser smoke covered `backup-plan` and `migration-preflight` sections
  across `desktop-1280` and `phone-390`, in both light and dark themes.
- Key browser assertions passed: no backend-login fallback,
  `[data-wls-db-manager-shell]` present, expected shell theme, migration
  preflight form present, `backup_action=migration_dry_run`,
  `migration_target=tag:2026.06.21`,
  `backup_artifact=codex-migration-preflight.sql`,
  `CHECK_DB_MIGRATION` phrase present, guarded copy present, no fatal text,
  no horizontal overflow, and buttons fit their boxes.
- Representative screenshots were manually reviewed for desktop dark and phone
  light states. The form hierarchy, guarded warning, checkbox, copy wrapping,
  and disabled action button remained readable without overlap.
- Evidence JSON:
  `dev/ai/codex/tasks/2026-06-21/2026-06-21-1553-wls-db-migration-preflight-visual-smoke/artifacts/dbmanager-migration-preflight-visual-smoke-result.json`.
- Cleanup for the focused visual-smoke instance completed:
  `php bin\w server:stop ai-test-wls-db-migration-vis-10024` drained the
  Dispatcher and two Workers, and final `server:status` reported
  `全部停止 (0/0)`.

## 2026-06-21 DbManager Restore Preflight Visual Slice

Scope:

- Closed the logged-in browser visual gap for the read-only
  `restore_database` preflight boundary in the WLS Database Manager plugin.
- The slice verifies the rendered standalone plugin shell, not destructive
  restore execution.
- Restore execution, SQL apply, migration execution, fresh pre-restore backup
  generation, verification queries, rollback automation, env writes, and WLS
  reload remain disabled.

Runtime:

```text
php bin\w server:start ai-test-wls-db-restore-vis-10026 -p 10026 --no-ssl -c 2 --worker-memory-limit=512M
```

Observed runtime:

- Instance: `ai-test-wls-db-restore-vis-10026`
- URL: `http://127.0.0.1:10026`
- Master PID: `35144`
- Worker count: `2`
- Dispatcher: `10026`
- Worker ports: `26478,26479`
- Topology: Windows auto-selected Dispatcher passthrough mode.

Validation:

```text
node --check dev\ai\codex\tasks\2026-06-21\2026-06-21-1607-wls-db-restore-preflight-visual-smoke\dbmanager-restore-preflight-visual-smoke.mjs
php bin\w server:status ai-test-wls-db-restore-vis-10026
node dev\ai\codex\tasks\2026-06-21\2026-06-21-1607-wls-db-restore-preflight-visual-smoke\dbmanager-restore-preflight-visual-smoke.mjs
```

Results:

- `node --check` passed.
- Dedicated instance status reported `全部运行中 (3/3)` before the browser run.
- Focused logged-in Chrome CDP browser smoke passed on the dedicated instance.
- The smoke covered `backup-plan` and `restore-preflight` sections across
  `desktop-1280` and `phone-390`, in both light and dark themes.
- Key browser assertions passed: no backend-login fallback,
  `[data-wls-db-manager-shell]` present, expected shell theme, restore
  preflight form present, `backup_action=restore_database`,
  `backup_artifact=codex-restore-preflight.sql`, `CHECK_DB_RESTORE` phrase
  present, guarded preflight copy present, restore execution disabled copy
  present, metadata/checksum/driver/database/readability copy present, no fatal
  text, no horizontal overflow, and buttons fit their boxes.
- Representative screenshots were manually reviewed for desktop dark and phone
  light states. The restore boundary hierarchy, guarded warning, checkbox,
  copy wrapping, and disabled action button remained readable without overlap.
- Evidence JSON:
  `dev/ai/codex/tasks/2026-06-21/2026-06-21-1607-wls-db-restore-preflight-visual-smoke/artifacts/dbmanager-restore-preflight-visual-smoke-result.json`.
- Cleanup for the focused visual-smoke instance completed:
  `php bin\w server:stop ai-test-wls-db-restore-vis-10026` drained the
  Dispatcher and two Workers, and final `server:status` reported
  `全部停止 (0/0)`.

## 2026-06-21 WLS Deploy Rollback Gate Browser Slice

Scope:

- Closed the remaining click-level browser gap for the standalone WLS Deploy
  rollback UI gate.
- This slice verifies the browser confirmation gate in the default
  server-rendered blocked context. It does not run a real Git rollback and does
  not create or mutate project Deploy Profiles.

Runtime:

```text
php bin\w server:start ai-test-wls-deploy-rollback-cdp-10028 -p 10028 --no-ssl -c 2 --worker-memory-limit=512M
```

Observed runtime:

- Instance: `ai-test-wls-deploy-rollback-cdp-10028`
- URL: `http://127.0.0.1:10028`
- Master PID: `32656`
- Worker count: `2`
- Dispatcher: `10028`
- Worker ports: `26480,26481`
- Topology: Windows auto-selected Dispatcher passthrough mode.

Validation:

```text
node --check dev\ai\codex\tasks\2026-06-21\2026-06-21-1619-wls-deploy-rollback-gate-browser-smoke\wls-deploy-rollback-gate-browser-smoke.mjs
php bin\w server:status ai-test-wls-deploy-rollback-cdp-10028
node dev\ai\codex\tasks\2026-06-21\2026-06-21-1619-wls-deploy-rollback-gate-browser-smoke\wls-deploy-rollback-gate-browser-smoke.mjs
```

Results:

- `node --check` passed.
- Dedicated instance status reported `全部运行中 (3/3)` before the browser run.
- Focused logged-in Chrome CDP browser smoke passed on the dedicated instance.
- The smoke covered desktop `1280` and phone `390`, in both light and dark
  themes.
- Key browser assertions passed: no backend-login fallback,
  `[data-wls-deploy-shell]` present, rollback section/form/checkbox/button
  present, expected shell theme, `data-ready=0`, `data-rollback-blocked=1`,
  before-click checkbox unchecked, before-click rollback button disabled with
  `aria-disabled=true`, after-click checkbox checked, and after-click rollback
  button still disabled with `aria-disabled=true`.
- Layout assertions passed: no fatal text, no horizontal overflow, and rollback
  controls fit their boxes.
- Representative screenshots were manually reviewed for desktop dark and phone
  light states. The rollback action area stayed readable, the confirmation
  checkbox state was visible, and the disabled action stayed visually contained
  without overlap.
- Evidence JSON:
  `dev/ai/codex/tasks/2026-06-21/2026-06-21-1619-wls-deploy-rollback-gate-browser-smoke/artifacts/wls-deploy-rollback-gate-browser-smoke-result.json`.
- Screenshot evidence:
  `wls-deploy-rollback-gate-desktop-1280-light.png`,
  `wls-deploy-rollback-gate-desktop-1280-dark.png`,
  `wls-deploy-rollback-gate-phone-390-light.png`, and
  `wls-deploy-rollback-gate-phone-390-dark.png`.
- Cleanup for the focused browser-smoke instance completed:
  `php bin\w server:stop ai-test-wls-deploy-rollback-cdp-10028` drained the
  Dispatcher and two Workers, and final `server:status` reported
  `全部停止 (0/0)`.
## 2026-06-22 DbManager Project Health Browser Slice

Scope:

- Added a read-only Project Health summary to the standalone WLS Database
  Manager panel.
- The summary reports readiness for selected profile, Project Profile, driver
  runtime, backup directory, env backup, backup/migration/restore plan, and
  slave profile coverage.
- This slice does not run live DB probes, migrations, SQL apply, restore,
  rollback automation, env writes, or WLS reload.

Runtime:

```text
php bin\w server:start ai-test-wls-db-health-10035 -p 10035 --no-ssl -c 1 --supervisor false --worker-memory-limit=512M
```

Observed runtime:

- Instance: `ai-test-wls-db-health-10035`
- URL: `http://127.0.0.1:10035`
- Backend entry: `U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8`

Validation:

```text
php -l app\code\Weline\DbManager\Service\WlsDatabaseProjectHealthService.php
php -l app\code\Weline\DbManager\Controller\Backend\WlsDbManager.php
php -l app\code\Weline\DbManager\Service\WlsDatabaseBackupPlanService.php
php -l app\code\Weline\DbManager\view\templates\Backend\WlsDbManager\index.phtml
node i18n-csv-parity-probe
browser smoke through the in-app browser runtime
php bin\w server:stop ai-test-wls-db-health-10035
php bin\w server:status ai-test-wls-db-health-10035
curl.exe -I --max-time 5 http://127.0.0.1:10035/
```

Results:

- PHP lint passed for the new Project Health service, touched controller,
  backup-plan service, and DbManager template. The local PHP runtime still
  prints duplicate-extension warnings before the lint result.
- DbManager i18n parity passed with `en_US=618` and `zh_Hans_CN=618`, no
  duplicate keys, and no malformed CSV rows.
- Browser smoke verified `data-wdb-project-health`, the sidebar `Health`
  entry, `data-wdb-health-state="attention"`, seven health checks, two
  suggested actions, and the read-only summary copy.
- Desktop and mobile `390px` checks found no `Fatal error`, `Warning:`,
  `Notice:`, or `Parse error` text and no horizontal overflow.
- Light and dark theme checks rendered correctly. Dark mode used body
  background `rgb(16, 25, 34)` and text `rgb(230, 238, 248)`.
- Representative desktop and mobile screenshots were manually reviewed. The
  Project Health cards, counters, and action list remained legible without
  layout overflow.
- Browser viewport override was reset after the smoke.
- Runtime cleanup passed: `server:stop ai-test-wls-db-health-10035` drained the
  Dispatcher and HTTP Worker through IPC; final `server:status` reported
  `全部停止 (0/0)`, and a final curl to port `10035` failed to connect as
  expected.

Evidence:

- `dev/ai/codex/tasks/2026-06-22/2026-06-22-0535-wls-db-project-health-summary/artifacts/browser-smoke-result.json`
- `dev/ai/codex/tasks/2026-06-22/2026-06-22-0535-wls-db-project-health-summary/artifacts/dbmanager-project-health-desktop-light.png`
- `dev/ai/codex/tasks/2026-06-22/2026-06-22-0535-wls-db-project-health-summary/artifacts/dbmanager-project-health-desktop-dark.png`
- `dev/ai/codex/tasks/2026-06-22/2026-06-22-0535-wls-db-project-health-summary/artifacts/dbmanager-project-health-mobile-light-visible.png`
- `dev/ai/codex/tasks/2026-06-22/2026-06-22-0535-wls-db-project-health-summary/artifacts/dbmanager-project-health-mobile-dark.png`

## 2026-06-22 DbManager Project Health Active Probe Slice

Scope:

- Added the first guarded active-read Project Health database connection
  probe to the standalone WLS Database Manager panel.
- The shared probe service is used by both the existing connection-test action
  and the Project Health probe action.
- The active probe requires checkbox confirmation plus the exact
  `CHECK_DB_HEALTH` phrase, opens a short PDO connection with a 3 second
  timeout, executes only `SELECT 1`, and records sanitized audit metadata.
- The slice explicitly does not run migrations, SQL apply, restore, rollback,
  env writes, or WLS reload.

Runtime:

```text
php bin\w server:start ai-test-wls-db-health-probe-10036 -p 10036 --no-ssl -c 1 --supervisor false --worker-memory-limit=512M
```

Observed runtime:

- Instance: `ai-test-wls-db-health-probe-10036`
- URL: `http://127.0.0.1:10036`
- Backend entry: `U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8`

Validation:

```text
php -l app\code\Weline\DbManager\Service\WlsDatabaseConnectionProbeService.php
php -l app\code\Weline\DbManager\Controller\Backend\WlsDbManager.php
php -l app\code\Weline\DbManager\view\templates\Backend\WlsDbManager\index.phtml
php -l dev\ai\codex\tasks\2026-06-21\2026-06-21-2152-wls-db-health-active-probe\artifacts\health-probe-sqlite-smoke.php
node i18n-and-marketplace-meta-probe
php bin\w setup:upgrade --route --module Weline_DbManager --skip-env-check --skip-composer-dump
php dev\ai\codex\tasks\2026-06-21\2026-06-21-2152-wls-db-health-active-probe\artifacts\health-probe-sqlite-smoke.php
browser smoke through the in-app browser runtime
php bin\w server:stop ai-test-wls-db-health-probe-10036
php bin\w server:status ai-test-wls-db-health-probe-10036
curl.exe -I --max-time 5 http://127.0.0.1:10036/
```

Results:

- PHP lint passed for the new probe service, touched controller, DbManager
  template, and SQLite smoke harness. The local PHP runtime still prints
  duplicate-extension warnings before the lint result.
- DbManager i18n and marketplace metadata parsed successfully with
  `en_US=628`, `zh_Hans_CN=628`, no duplicate keys, no malformed rows,
  `capability:database-health-probe`, and `database.health_probe`.
- Route refresh generated
  `weline_dbmanager/backend/wls-db-manager/health-probe::POST` mapped to
  `postHealthProbe`.
- Service smoke verified a successful SQLite `SELECT 1` probe and an
  incomplete MySQL profile guard error without credential leakage.
- Browser smoke verified `data-wdb-project-health`,
  `data-wdb-health-probe-form`, `CHECK_DB_HEALTH`, one selectable `Master`
  profile, the standalone Database Manager shell, and the unconfirmed submit
  returning the guard error instead of running the active probe.
- Desktop and mobile `390px` checks found no `Fatal error`, `Warning:`,
  `Notice:`, or `Parse error` text and no horizontal overflow.
- Light and dark theme checks rendered correctly. Dark mode used body
  background `rgb(16, 25, 34)` and text `rgb(230, 238, 248)`.
- Representative desktop and mobile screenshots were manually reviewed. The
  health cards, probe form, confirmation checkbox, phrase field, and submit
  button remained legible without overlap.
- Browser viewport override was reset after the smoke.
- Runtime cleanup passed: `server:stop ai-test-wls-db-health-probe-10036`
  drained the Dispatcher and HTTP Worker through IPC; final `server:status`
  reported `全部停止 (0/0)`, and a final curl to port `10036` failed to
  connect as expected.

Evidence:

- `dev/ai/codex/tasks/2026-06-21/2026-06-21-2152-wls-db-health-active-probe/artifacts/browser-smoke-result.json`
- `dev/ai/codex/tasks/2026-06-21/2026-06-21-2152-wls-db-health-active-probe/artifacts/health-probe-sqlite-smoke.php`
- `dev/ai/codex/tasks/2026-06-21/2026-06-21-2152-wls-db-health-active-probe/artifacts/dbmanager-health-probe-desktop-light.png`
- `dev/ai/codex/tasks/2026-06-21/2026-06-21-2152-wls-db-health-active-probe/artifacts/dbmanager-health-probe-desktop-dark.png`
- `dev/ai/codex/tasks/2026-06-21/2026-06-21-2152-wls-db-health-active-probe/artifacts/dbmanager-health-probe-mobile-current.png`
- `dev/ai/codex/tasks/2026-06-21/2026-06-21-2152-wls-db-health-active-probe/artifacts/dbmanager-health-probe-mobile-toggled.png`

## 2026-06-22 DbManager Project Health Browser Success Slice

Scope:

- Closed the browser-level success proof for the existing guarded Project
  Health active database probe.
- No production PHP changed in this slice.
- The browser path submits the existing `Master` profile through the standalone
  Database Manager panel with checkbox confirmation plus `CHECK_DB_HEALTH`.
- The probe remains an active-read diagnostic only: it executes `SELECT 1` and
  does not run migrations, SQL apply, restore, rollback, env writes, or WLS
  reload.

Runtime:

```text
php bin\w server:start ai-test-wls-db-health-success-10037 -p 10037 --no-ssl -c 1 --supervisor false --worker-memory-limit=512M
```

Observed runtime:

- Instance: `ai-test-wls-db-health-success-10037`
- URL: `http://127.0.0.1:10037`
- Backend entry: `U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8`

Validation:

```text
php bin\w server:status ai-test-wls-db-health-success-10037
curl.exe -I --max-time 10 http://127.0.0.1:10037/
curl.exe -I --max-time 10 http://127.0.0.1:10037/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/weline_dbmanager/backend/wls-db-manager
browser success smoke through the in-app browser runtime
parse var/log/wls/db-manager-audit.jsonl for the final health_probe event
php bin\w server:stop ai-test-wls-db-health-success-10037
php bin\w server:status ai-test-wls-db-health-success-10037
curl.exe -I --max-time 5 http://127.0.0.1:10037/
```

Results:

- The dedicated WLS instance reached `全部运行中 (2/2)` with one Dispatcher and
  one HTTP Worker.
- Root curl returned `HTTP/1.1 200 OK`.
- The direct DbManager route returned the expected backend auth redirect without
  a browser session, proving the route was reachable and guarded.
- In-app Browser rendered the standalone DbManager shell and the
  `data-wdb-health-probe-form` inside `#project-health`.
- Browser filled the confirmation checkbox and `CHECK_DB_HEALTH` phrase, then
  submitted the form.
- The page returned visible success copy `数据库健康探测通过。` and URL
  parameter `dbm_notice=health_probe_passed`.
- Desktop and mobile `390px` checks found no login fallback, no `Fatal error`,
  `Warning:`, `Notice:`, or `Parse error` text and no horizontal overflow.
- Light and dark theme checks rendered correctly. Dark mode used body
  background `rgb(16, 25, 34)` and text `rgb(230, 238, 248)`.
- The final `health_probe` audit event was `success=true`,
  `connection_key=master`, `driver=pgsql`, and `duration_ms=54`.
- Audit fields were sanitized: only `success`, `profile_key`,
  `connection_key`, `driver`, `duration_ms`, and `message` were present; no
  password, token, secret, or confirmation phrase fields were recorded.
- Representative desktop and mobile screenshots were manually reviewed.
- Browser viewport override was reset after the smoke.
- Runtime cleanup passed: `server:stop ai-test-wls-db-health-success-10037`
  drained the Dispatcher and HTTP Worker through IPC; final `server:status`
  reported `全部停止 (0/0)`, and a final curl to port `10037` failed to
  connect as expected.

Evidence:

- `dev/ai/codex/tasks/2026-06-21/2026-06-21-2228-wls-db-health-browser-success-probe/artifacts/browser-success-result.json`
- `dev/ai/codex/tasks/2026-06-21/2026-06-21-2228-wls-db-health-browser-success-probe/artifacts/audit-health-probe.json`
- `dev/ai/codex/tasks/2026-06-21/2026-06-21-2228-wls-db-health-browser-success-probe/artifacts/dbmanager-health-success-desktop-light.png`
- `dev/ai/codex/tasks/2026-06-21/2026-06-21-2228-wls-db-health-browser-success-probe/artifacts/dbmanager-health-success-desktop-dark.png`
- `dev/ai/codex/tasks/2026-06-21/2026-06-21-2228-wls-db-health-browser-success-probe/artifacts/dbmanager-health-success-mobile-current.png`
- `dev/ai/codex/tasks/2026-06-21/2026-06-21-2228-wls-db-health-browser-success-probe/artifacts/dbmanager-health-success-mobile-toggled.png`

## 2026-06-22 WLS Panel Security Policy Audit Filter Slice

Scope:

- Added native Security Policy Audit filters to the standalone WLS Panel
  Security page.
- The audit list now supports action, source, domain, changed section, keyword,
  and limit filters.
- Domain `*` means all domains. A scoped project domain still sees global
  common-policy audit entries because common security rules apply as project
  fallback.
- The slice only filters existing JSONL audit records; it does not change rule
  save semantics, attack detection, gateway routing, deploy behavior, or plugin
  installation.

Runtime:

```text
php bin\w server:start ai-test-wls-sec-audit-10038 -p 10038 --no-ssl -c 1 --supervisor false --worker-memory-limit=512M
```

Observed runtime:

- Instance: `ai-test-wls-sec-audit-10038`
- URL: `http://127.0.0.1:10038`
- Backend entry: `U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8`

Validation:

```text
php -l app\code\Weline\Server\Service\WlsPanelSecurityDataService.php
php -l app\code\Weline\Server\Controller\Backend\WlsPanel.php
php -l app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
php -l dev\ai\codex\tasks\2026-06-21\2026-06-21-2245-wls-panel-security-policy-audit-filters\artifacts\security-policy-audit-filter-probe.php
php -l dev\ai\codex\tasks\2026-06-21\2026-06-21-2245-wls-panel-security-policy-audit-filters\artifacts\validate-server-i18n-csv.php
php dev\ai\codex\tasks\2026-06-21\2026-06-21-2245-wls-panel-security-policy-audit-filters\artifacts\security-policy-audit-filter-probe.php
php dev\ai\codex\tasks\2026-06-21\2026-06-21-2245-wls-panel-security-policy-audit-filters\artifacts\validate-server-i18n-csv.php
rg -n "alert\(|confirm\(|prompt\(" app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
git diff --check -- app/code/Weline/Server/Service/WlsPanelSecurityDataService.php app/code/Weline/Server/Controller/Backend/WlsPanel.php app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml app/code/Weline/Server/i18n/en_US.csv app/code/Weline/Server/i18n/zh_Hans_CN.csv dev/ai/codex/tasks/2026-06-21/2026-06-21-2245-wls-panel-security-policy-audit-filters
php bin\w server:status ai-test-wls-sec-audit-10038
curl.exe -I --max-time 10 http://127.0.0.1:10038/
curl.exe -I --max-time 10 "http://127.0.0.1:10038/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/server/backend/wls-panel/security?policy_audit_action=save&policy_audit_source=panel&policy_audit_domain=*&policy_audit_section=rate_limit&policy_audit_keyword=domain&policy_audit_limit=5"
php bin\w server:stop ai-test-wls-sec-audit-10038
php bin\w server:status ai-test-wls-sec-audit-10038
curl.exe -I --max-time 5 http://127.0.0.1:10038/
```

Results:

- PHP lint passed for the touched service, controller, template, and two task
  probe scripts. The local PHP runtime still prints duplicate-extension
  warnings before normal command output.
- Service probe passed for exact action/source/domain/section/keyword matching,
  wrong-source rejection, wildcard-domain matching, and scoped-domain access to
  global common-policy audit records.
- Server i18n CSV validation passed with `en_US.csv=2501` and
  `zh_Hans_CN.csv=2501`.
- The WLS panel template still has no `alert()`, `confirm()`, or `prompt()`
  usage.
- `git diff --check` passed for the touched production files and task
  artifacts, with only repository line-ending warnings.
- Dedicated WLS instance `ai-test-wls-sec-audit-10038` reached
  `全部运行中 (2/2)` with one Dispatcher and one HTTP Worker.
- Root curl returned `HTTP/1.1 200 OK`.
- The protected Security URL with all audit filter query parameters returned
  `HTTP/1.1 302 Found` to the backend login page and preserved the filter query
  in `return_url`, proving the route and query chain are reachable and guarded.
- Runtime cleanup passed: `server:stop ai-test-wls-sec-audit-10038` drained the
  Dispatcher and HTTP Worker through IPC; final `server:status` reported
  `全部停止 (0/0)`, and a final curl to port `10038` failed to connect as
  expected.
- Initial real browser visual smoke was not available in that run because the
  current tool search did not expose a callable browser navigation/screenshot
  tool. A follow-up headless CDP visual smoke for the same audit filter form is
  recorded below and now passes.

Evidence:

- `dev/ai/codex/tasks/2026-06-21/2026-06-21-2245-wls-panel-security-policy-audit-filters/artifacts/security-policy-audit-filter-probe.json`
- `dev/ai/codex/tasks/2026-06-21/2026-06-21-2245-wls-panel-security-policy-audit-filters/artifacts/server-i18n-csv-validation.json`

## 2026-06-22 WLS Panel Security Policy Audit Filter Visual Smoke

Scope:

- Closed the visual/browser follow-up for `WLS-SEC-AUDIT-FILTER-001`.
- The browser smoke uses a one-time headless CDP script in the task artifacts
  directory rather than adding a long-lived E2E spec.
- The smoke logs into the backend, opens the standalone WLS Panel Security
  page with real audit filter query values, scrolls to
  `#security-policy-audit`, checks the filter form, and captures screenshots.

Runtime:

```text
php bin\w server:start ai-test-wls-sec-audit-visual-10039 -p 10039 --no-ssl -c 1 --supervisor false --worker-memory-limit=512M --dispatcher-memory-limit=512M
```

Observed runtime:

- Instance: `ai-test-wls-sec-audit-visual-10039`
- URL: `http://127.0.0.1:10039`
- Backend entry: `U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8`

Validation:

```text
node --check dev\ai\codex\tasks\2026-06-22\2026-06-22-0715-wls-security-audit-filter-visual-smoke\artifacts\wls-security-audit-filter-visual-smoke.mjs
php -l app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
curl.exe -I --max-time 10 http://127.0.0.1:10039/
node dev\ai\codex\tasks\2026-06-22\2026-06-22-0715-wls-security-audit-filter-visual-smoke\artifacts\wls-security-audit-filter-visual-smoke.mjs
php bin\w server:stop ai-test-wls-sec-audit-visual-10039
php bin\w server:status ai-test-wls-sec-audit-visual-10039
curl.exe -I --max-time 5 http://127.0.0.1:10039/
```

Results:

- The first visual smoke reached the standalone WLS shell and audit form in all
  four viewport/theme combinations, but it also exposed a real desktop layout
  issue: the `Audit Action` select was compressed to `clientWidth=107` while
  its content needed `scrollWidth=114`.
- The audit filter CSS was adjusted from six equal desktop columns to
  responsive `auto-fit` columns with a 170px minimum, and WLS filter controls
  now use border-box sizing.
- `php -l` passed for the updated WLS Panel template.
- A reload was accepted by `ai-test-wls-sec-audit-visual-10039`; the follow-up
  headless browser smoke then passed with `passed=true`.
- Browser assertions passed for `desktop-1280` light, `desktop-1280` dark,
  `phone-390` light, and `phone-390` dark:
  standalone shell present, `#security-policy-audit` present,
  `.wls-security-audit-filter` present, query values preserved, no login
  fallback, no fatal or warning text, no horizontal overflow, and all filter
  controls fit.
- Representative screenshots were manually reviewed: desktop light shows the
  filter controls across two balanced rows without compressed selects; phone
  dark shows a clean single-column form with readable labels and buttons.
- Runtime cleanup passed: `server:stop ai-test-wls-sec-audit-visual-10039`
  drained the Dispatcher and HTTP Worker through IPC; final `server:status`
  reported `全部停止 (0/0)`, and a final curl to port `10039` failed to
  connect as expected.

Evidence:

- `dev/ai/codex/tasks/2026-06-22/2026-06-22-0715-wls-security-audit-filter-visual-smoke/artifacts/security-audit-filter-visual-smoke-result.json`
- `dev/ai/codex/tasks/2026-06-22/2026-06-22-0715-wls-security-audit-filter-visual-smoke/artifacts/security-audit-filter-desktop-1280-light.png`
- `dev/ai/codex/tasks/2026-06-22/2026-06-22-0715-wls-security-audit-filter-visual-smoke/artifacts/security-audit-filter-desktop-1280-dark.png`
- `dev/ai/codex/tasks/2026-06-22/2026-06-22-0715-wls-security-audit-filter-visual-smoke/artifacts/security-audit-filter-phone-390-light.png`
- `dev/ai/codex/tasks/2026-06-22/2026-06-22-0715-wls-security-audit-filter-visual-smoke/artifacts/security-audit-filter-phone-390-dark.png`

## 2026-06-22 WLS Panel Project Config And Legacy Marketplace Entry Smoke

Scope:

- Revalidated the current standalone WLS Panel Dashboard, Marketplace, and
  Security pages after the Project Config Center slice.
- Added and validated a backward-compatible read-only legacy entry for the old
  framework-backend marketplace URL:
  `server/backend/panel-marketplace`.
- The legacy entry redirects into the standalone WLS Panel marketplace and
  allowlists only safe marketplace filter parameters; it intentionally strips
  `project_path`, `return_url`, and other unrecognized query parameters.

Changed file:

```text
app/code/Weline/Server/Controller/Backend/PanelMarketplace.php
```

Runtime:

```text
php bin\w server:start ai-test-wls-project-config-current-10040 -p 10040 --no-ssl -c 2 --supervisor false --worker-memory-limit=512M --dispatcher-memory-limit=512M
```

Observed runtime:

- Instance: `ai-test-wls-project-config-current-10040`
- URL: `http://127.0.0.1:10040`
- Backend entry: `U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8`
- Initial Master PID before route refresh: `34260`
- Restarted Master PID after route refresh: `48468`

Validation:

```text
php -l app\code\Weline\Server\Controller\Backend\PanelMarketplace.php
rg -n "\b(sleep|usleep|die|exit|alert|confirm|prompt)\s*\(" app\code\Weline\Server\Controller\Backend\PanelMarketplace.php
php bin\w setup:upgrade --route
php -r "require 'E:/WelineFramework/DEV-workspace/app/bootstrap.php'; \Weline\Framework\Manager\ObjectManager::make(\Weline\Framework\Router\Service\RouteUpdateService::class, ['moduleHandle' => \Weline\Framework\Manager\ObjectManager::make(\Weline\Framework\Module\Handle::class)])->updateRoutes(['Weline_Server']);"
rg -n "panel-marketplace|PanelMarketplace|wls-panel/marketplace" generated\routers app\code\Weline\Server\Controller\Backend\PanelMarketplace.php
curl.exe -I "http://127.0.0.1:10040/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/server/backend/panel-marketplace?tag=module:wls&project_path=C:/unsafe&return_url=https://evil.test"
php tests\e2e\framework\backend-session-bootstrap.php --mode=wls --username=admin --password=admin
curl.exe -I -H "Cookie: WELINE_SESSID=<one-time-session>" "http://127.0.0.1:10040/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/server/backend/panel-marketplace?tag=module:wls&project_path=C:/unsafe&return_url=https://evil.test&panel_auto_refresh=plugins"
curl.exe -L --max-redirs 3 -H "Cookie: WELINE_SESSID=<one-time-session>" "http://127.0.0.1:10040/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/server/backend/panel-marketplace?tag=module:wls&project_path=C:/unsafe&return_url=https://evil.test&panel_auto_refresh=plugins" -o NUL -w "effective=%{url_effective}\ncode=%{http_code}\n"
php bin\w server:stop ai-test-wls-project-config-current-10040
php bin\w server:status ai-test-wls-project-config-current-10040
curl.exe -I --max-time 5 http://127.0.0.1:10040/
```

Results:

- PHP lint passed for the new compatibility controller. The local PHP runtime
  still prints duplicate-extension warnings before normal command output.
- Static scan found no `sleep`, `usleep`, `die`, `exit`, `alert`, `confirm`,
  or `prompt` usage in the new controller.
- `php bin\w setup:upgrade --route` completed composer dump-autoload but
  exited `1` during the later environment auto-repair stage because the current
  Windows PATH did not expose `chcp` / nested `php` to the repair script. The
  route was therefore refreshed with the same internal
  `RouteUpdateService->updateRoutes(['Weline_Server'])` path used by module
  installation refreshes.
- Route registry proof passed: `generated\routers\backend_pc.php` contains
  `server/backend/panel-marketplace::GET` mapped to
  `Weline\Server\Controller\Backend\PanelMarketplace`, and the existing
  `server/backend/wls-panel/marketplace::GET` route remains present.
- Before WLS restart, the running instance still returned 404 for the old
  route, proving the running worker had the old route snapshot. After a clean
  `server:stop` and restart, the old route became active.
- Unauthenticated old marketplace URL returned backend auth `302 Found`
  instead of 404.
- Authenticated old marketplace URL returned:

```text
Location: http://127.0.0.1:10040/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/server/backend/wls-panel/marketplace?tag=module%3Awls&panel_auto_refresh=plugins
```

- The redirect stripped `project_path=C:/unsafe` and
  `return_url=https://evil.test` while preserving the safe `tag` and
  `panel_auto_refresh` filters.
- Following the redirect produced:

```text
effective=http://127.0.0.1:10040/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/server/backend/wls-panel/marketplace?tag=module%3Awls&panel_auto_refresh=plugins
code=200
```

- The earlier current-panel Chrome smoke in this same task produced screenshots
  for Dashboard, Marketplace, and Security across desktop 1440 and phone 390,
  light and dark themes, with standalone shell present, no login fallback, no
  fatal text, no horizontal overflow, Project Config Center operations present,
  Security rules/log sections present, Marketplace `module:wls` tag present,
  and no `project_path` in generated Project Config Center operation URLs.
- A fresh browser navigation tool was not exposed by tool discovery during this
  compatibility sub-slice, so the legacy-entry acceptance proof is HTTP-level
  redirect and final-page reachability; the panel visual evidence remains the
  generated Chrome screenshot set listed below.
- Runtime cleanup passed: final status reported `全部停止 (0/0)`, and a final
  curl to port `10040` failed to connect as expected.

Evidence:

- `dev/ai/codex/tasks/2026-06-21/2026-06-21-2325-wls-project-config-center-first-slice/artifacts/wls-panel-current-smoke-report.json`
- `dev/ai/codex/tasks/2026-06-21/2026-06-21-2325-wls-project-config-center-first-slice/artifacts/wls-panel-current-dashboard-1440-light.png`
- `dev/ai/codex/tasks/2026-06-21/2026-06-21-2325-wls-project-config-center-first-slice/artifacts/wls-panel-current-dashboard-390-dark.png`
- `dev/ai/codex/tasks/2026-06-21/2026-06-21-2325-wls-project-config-center-first-slice/artifacts/wls-panel-current-marketplace-1440-light.png`
- `dev/ai/codex/tasks/2026-06-21/2026-06-21-2325-wls-project-config-center-first-slice/artifacts/wls-panel-current-marketplace-390-dark.png`
- `dev/ai/codex/tasks/2026-06-21/2026-06-21-2325-wls-project-config-center-first-slice/artifacts/wls-panel-current-security-1440-light.png`
- `dev/ai/codex/tasks/2026-06-21/2026-06-21-2325-wls-project-config-center-first-slice/artifacts/wls-panel-current-security-390-dark.png`
- `dev/ai/codex/tasks/2026-06-21/2026-06-21-2325-wls-project-config-center-first-slice/artifacts/wls-panel-legacy-marketplace-redirect-report.json`

## 2026-06-22 WLS Panel Project Readiness Summary

Task:
`dev/ai/codex/tasks/2026-06-22/2026-06-22-0002-wls-panel-project-readiness-summary`

### Scope

- Added the cross-plugin readiness/health summary slice for the native Project
  Config Center.
- `WlsPanelProjectConfigCenterService` now emits:
  - global readiness summary state and ready project/operation-slot counts;
  - per-project readiness state, score label, core check counts, operation slot
    counts, missing operation labels, and four check items:
    `core-links`, `operation-slots`, `security-scope`, and `gateway-mode`.
- The WLS Panel dashboard renders a global readiness bar and a per-project
  readiness section before existing PHP, database, files, deploy, security, and
  gateway links.
- The slice is read-only. It does not change PHP, database, file, deploy,
  security, or gateway write behavior and does not pass raw `project_path` in
  links.

### Verification Commands

```powershell
php -l app\code\Weline\Server\Service\WlsPanelProjectConfigCenterService.php
php -l app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
rg -n "\b(sleep|usleep|die|exit)\b|alert\(|confirm\(|prompt\(" app/code/Weline/Server/Service/WlsPanelProjectConfigCenterService.php app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml
php dev\ai\codex\tasks\2026-06-21\2026-06-21-2245-wls-panel-security-policy-audit-filters\artifacts\validate-server-i18n-csv.php
node --check dev\ai\codex\tasks\2026-06-22\2026-06-22-0002-wls-panel-project-readiness-summary\artifacts\wls-panel-project-readiness-cdp-smoke.mjs
php bin\w server:start ai-test-wls-project-readiness-10041 -p 10041 --no-ssl -c 2 --supervisor false --worker-memory-limit=512M --dispatcher-memory-limit=512M
node dev\ai\codex\tasks\2026-06-22\2026-06-22-0002-wls-panel-project-readiness-summary\artifacts\wls-panel-project-readiness-cdp-smoke.mjs
php bin\w server:stop ai-test-wls-project-readiness-10041
php bin\w server:status ai-test-wls-project-readiness-10041
curl.exe -I --max-time 5 http://127.0.0.1:10041/
```

### Result

- PHP lint passed for the service and dashboard template.
- Forbidden-call scan returned no matches.
- Server i18n CSV validation passed with `en_US.csv=2526` and
  `zh_Hans_CN.csv=2526`.
- Browser/CDP smoke passed on the dedicated non-9501 WLS instance
  `ai-test-wls-project-readiness-10041`:
  - dashboard loaded the standalone shell;
  - Project Config Center and readiness summary were present;
  - one project card had one readiness section;
  - four readiness checks were present: `core-links`, `operation-slots`,
    `security-scope`, and `gateway-mode`;
  - desktop 1440 light and phone 390 dark both reported `overflowX=0`;
  - no visible control overflow was detected;
  - no fatal text was detected;
  - no `project_path` was present in Project Config Center links.
- Visual review of the element screenshots confirmed the desktop single-project
  layout now uses the full available width, while the mobile dark layout stacks
  the readiness checks and operation cards without horizontal overflow.
- Runtime cleanup passed: `server:stop` drained and stopped the instance,
  `server:status` reported stopped, and `curl.exe` to port `10041` failed to
  connect as expected.

### Artifacts

- `dev/ai/codex/tasks/2026-06-22/2026-06-22-0002-wls-panel-project-readiness-summary/artifacts/wls-panel-project-readiness-cdp-smoke-result.json`
- `dev/ai/codex/tasks/2026-06-22/2026-06-22-0002-wls-panel-project-readiness-summary/artifacts/wls-project-readiness-desktop-1440-light.png`
- `dev/ai/codex/tasks/2026-06-22/2026-06-22-0002-wls-panel-project-readiness-summary/artifacts/wls-project-readiness-phone-390-dark.png`
- `dev/ai/codex/tasks/2026-06-22/2026-06-22-0002-wls-panel-project-readiness-summary/artifacts/wls-project-readiness-section-desktop-1440-light.png`
- `dev/ai/codex/tasks/2026-06-22/2026-06-22-0002-wls-panel-project-readiness-summary/artifacts/wls-project-readiness-section-phone-390-dark.png`

## 2026-06-22 WLS Deploy Release Path Browser Smoke

Task:
`dev/ai/codex/tasks/2026-06-22/2026-06-22-0025-wls-deploy-browser-release-history-path`

### Scope

- Added a visible `Project Release Path` bridge to the standalone WLS Deploy
  panel.
- The bridge connects the selected project context, Profile key/state, Webhook
  Replay, Manual Plan, and scoped release history in one operator-visible
  chain.
- The page exposes stable DOM markers for the path and release-history scope:
  `data-wls-deploy-release-path`, per-step markers, and
  `data-wls-deploy-history-scope`.
- All WLS Deploy forms and path links preserve the same `project_id`, `domain`,
  and `project_type` that a parent WLS project card passes into the panel.
- Long single-line command input values now render with ellipsis rather than
  visually spilling from text fields.

### Verification Commands

```powershell
php -l app\code\Weline\Deploy\view\templates\Backend\WlsDeploy\index.phtml
php -d error_reporting=22527 -r "<Deploy CSV two-column parse; en_US + zh_Hans_CN>"
node --check dev\ai\codex\tasks\2026-06-22\2026-06-22-0025-wls-deploy-browser-release-history-path\wls-deploy-release-path-cdp-smoke.mjs
php bin\w server:start ai-test-wls-deploy-path-10042 -p 10042 --no-ssl -c 2 --supervisor false --worker-memory-limit=512M --dispatcher-memory-limit=512M
node dev\ai\codex\tasks\2026-06-22\2026-06-22-0025-wls-deploy-browser-release-history-path\wls-deploy-release-path-cdp-smoke.mjs
php bin\w server:stop ai-test-wls-deploy-path-10042
php bin\w server:status ai-test-wls-deploy-path-10042
curl.exe -I --max-time 5 http://127.0.0.1:10042/
```

### Result

- PHP lint passed for the Deploy WLS panel template.
- Deploy i18n CSV validation passed with `en_US=648` and
  `zh_Hans_CN=648`.
- Node syntax check passed for the CDP smoke harness.
- Browser/CDP smoke passed on the dedicated non-9501 WLS instance
  `ai-test-wls-deploy-path-10042`:
  - desktop 1440 light and phone 390 dark rendered the standalone Deploy shell;
  - `Project Release Path` was present and reported
    `profile_key=project:codex-deploy-path`;
  - release-path scope and release-history scope both reported `project`;
  - release-path count and history count matched;
  - all WLS Deploy forms preserved `project_id=codex-deploy-path`,
    `domain=codex-deploy-path.weline.test`, and `project_type=wls`;
  - path links to Profile, Webhook Replay, Manual Plan, and scoped Release
    History preserved the project context;
  - clicking the scoped history link landed on `#releases`;
  - there was no login fallback, fatal text, horizontal overflow, or actionable
    button/link text overflow.
- Runtime cleanup passed: `server:stop` drained the instance, final status
  reported `全部停止 (0/0)`, and `curl.exe` to port `10042` failed to connect
  as expected.

### Artifacts

- `dev/ai/codex/tasks/2026-06-22/2026-06-22-0025-wls-deploy-browser-release-history-path/artifacts/wls-deploy-release-path-cdp-smoke-result.json`
- `dev/ai/codex/tasks/2026-06-22/2026-06-22-0025-wls-deploy-browser-release-history-path/artifacts/wls-deploy-release-path-desktop-1440-light.png`
- `dev/ai/codex/tasks/2026-06-22/2026-06-22-0025-wls-deploy-browser-release-history-path/artifacts/wls-deploy-release-path-phone-390-dark.png`
- `dev/ai/codex/tasks/2026-06-22/2026-06-22-0025-wls-deploy-browser-release-history-path/artifacts/wls-deploy-release-path-section-desktop-1440-light.png`
- `dev/ai/codex/tasks/2026-06-22/2026-06-22-0025-wls-deploy-browser-release-history-path/artifacts/wls-deploy-release-path-section-phone-390-dark.png`
- `dev/ai/codex/tasks/2026-06-22/2026-06-22-0025-wls-deploy-browser-release-history-path/artifacts/wls-deploy-release-path-history-click-desktop-1440-light.png`

## 2026-06-22 Official Marketplace Typed Tag Source Contract

Task:
`dev/ai/codex/tasks/2026-06-22/2026-06-22-0112-official-marketplace-typed-tag-evidence`

### Scope

- Re-checked the online marketplace source used by WLS plugin discovery:
  `E:\WelineFramework\Framework-Official\App\weline\app\code\Weline\PlatformAppStore`.
- Confirmed `POST /api/v1/platform/module/list` forwards `tag`, `tags`, and
  `tag_match` filters into `ModuleCatalogService::listPublished()`.
- Confirmed `ModuleCatalogService` normalizes plain typed strings, JSON arrays,
  arrays, and structured `{type,value}` tag objects.
- Confirmed server-side matching is exact normalized token matching, so
  `module:wls-extra` does not satisfy a `module:wls` filter.
- No official repository code was changed; this was a source-contract and test
  evidence pass.

### Verification Commands

```powershell
php -l app\code\Weline\PlatformAppStore\Service\ModuleCatalogService.php
php -l app\code\Weline\PlatformAppStore\Controller\Api\V1\Platform\Module.php
php vendor\phpunit\phpunit\phpunit app\code\Weline\PlatformAppStore\test\Unit\Service\ModuleCatalogServiceTagFilterTest.php
```

### Result

- PHP lint passed for `ModuleCatalogService.php`.
- PHP lint passed for `Controller\Api\V1\Platform\Module.php`.
- PHPUnit passed the focused typed tag contract suite with 3 tests and 6
  assertions.
- PHPUnit reported only the existing coverage-mode warning; there were no
  assertion failures.
- Live token-authenticated marketplace API E2E remains a later environment
  gate because this pass did not use official-site API credentials.

## 2026-06-22 Current Environment Gate Refresh

Task:
`dev/ai/codex/tasks/2026-06-22/2026-06-22-0947-wls-panel-environment-gate-refresh`

Scope:

- Re-checked the local environment gates that currently prevent final WLS Panel
  completion from being claimed.
- No production code was changed.
- Two delegated read-only probes were attempted for direct-listen and official
  marketplace live E2E; one timed out and one stream disconnected before
  completion, so the final evidence below comes from main-thread commands.

Validation commands:

```powershell
php -r "echo PHP_OS_FAMILY, PHP_EOL; echo PHP_VERSION, PHP_EOL; echo defined('SO_REUSEPORT') ? 'SO_REUSEPORT=yes' : 'SO_REUSEPORT=no', PHP_EOL;"
docker version --format "Client={{.Client.Version}} Server={{.Server.Version}}"
wsl.exe -l -v
gitnexus status
```

Results:

- PHP reported `Windows`, `8.4.16`, and `SO_REUSEPORT=no`; the command also
  printed the existing duplicate-extension warnings from the local PHP config.
- Docker Desktop is reachable (`Client=29.4.1`, `Server=29.4.1`).
- WSL listed only `docker-desktop` as a running version-2 distro. That does
  not prove a provisioned Weline Linux runtime for shared-port direct-listen.
- Sanitized env-key scanning found no `.env` file in either
  `E:\WelineFramework\DEV-workspace` or
  `E:\WelineFramework\Framework-Official\App\weline`. DEV `app/etc/env.php`
  has AppStore/platform config keys; the official app env scan did not expose
  a token/bearer-style marketplace credential key.
- `gitnexus status` returned `Not a git repository.` even though earlier
  repository checks resolve `E:/WelineFramework/DEV-workspace` as the Git root.
  This keeps production symbol edits gated until the GitNexus path is restored
  or a packet explicitly accepts a documented exception.

Conclusion:

- This environment refresh was superseded for direct-listen by the supported
  Docker Linux proof recorded below. The Windows host remains negative-only,
  but `WLS-GW-PERF-001` no longer needs a separate external runner just to
  prove shared-port feasibility.
- `WLS-MARKETPLACE-E2E-001` still needs real official marketplace credentials
  before live API inclusion/exclusion assertions can be run.
- Current-session progress should stay on documentation, environment evidence,
  browser smoke, or non-symbol work until the GitNexus guardrail is usable
  again.

## 2026-06-22 WLS Direct-Listen Supported Runner Proof

Task:
`dev/ai/codex/tasks/2026-06-22/2026-06-22-0729-wls-direct-listen-supported-runner-proof`

Scope:

- Closed the `WLS-GW-PERF-001` positive-evidence gap without changing
  production code.
- Built a disposable Docker Linux PHP 8.4.22 runner with `curl`, `mbstring`,
  `mysqli`, `pcntl`, `pdo_mysql`, `pdo_pgsql`, and `sockets`.
- Verified the runner through both a task-local runtime probe and WLS
  `server:doctor`.
- Started WLS direct mode with two HTTP workers sharing public port `10045`.
- Started WLS dispatcher mode with public dispatcher port `10046` and workers
  `27608/27609` in the same container namespace.
- Used `/_wls/health?detail=1` to avoid project database dependency inside
  Docker while still exercising real WLS worker request handling.

Validation commands:

```powershell
docker build -t wls-direct-listen-proof:20260622 -f dev/ai/codex/tasks/2026-06-22/2026-06-22-0729-wls-direct-listen-supported-runner-proof/artifacts/Dockerfile.direct-listen-proof .
docker run --rm -v E:/WelineFramework/DEV-workspace:/work -w /work wls-direct-listen-proof:20260622 php dev/ai/codex/tasks/2026-06-22/2026-06-22-0729-wls-direct-listen-supported-runner-proof/artifacts/runtime-capability-probe.php
docker run --rm -v E:/WelineFramework/DEV-workspace:/work -w /work wls-direct-listen-proof:20260622 php bin/w server:doctor ai-test-wls-direct-docker-10045 --json
docker run -d --name wls-direct-proof-10045 -p 10045:10045 -v E:/WelineFramework/DEV-workspace:/work -w /work wls-direct-listen-proof:20260622 bash -lc "php bin/w server:start ai-test-wls-direct-docker-10045 -p 10045 --host 0.0.0.0 --no-ssl -c 2 --direct --worker-memory-limit=512M --dispatcher-memory-limit=512M; tail -f /dev/null"
docker exec wls-direct-proof-10045 php bin/w server:start ai-test-wls-dispatcher-docker-10046 -p 10046 --host 127.0.0.1 --no-ssl -c 2 --dispatcher --worker-memory-limit=512M --dispatcher-memory-limit=512M
docker exec wls-direct-proof-10045 php dev/ai/codex/tasks/2026-06-22/2026-06-22-0729-wls-direct-listen-supported-runner-proof/artifacts/health-distribution-probe.php "http://127.0.0.1:10045/_wls/health?detail=1" 3000 150
docker exec wls-direct-proof-10045 php dev/ai/codex/tasks/2026-06-22/2026-06-22-0729-wls-direct-listen-supported-runner-proof/artifacts/health-distribution-probe.php "http://127.0.0.1:10046/_wls/health?detail=1" 3000 150
docker exec wls-direct-proof-10045 php bin/w server:stop ai-test-wls-dispatcher-docker-10046
docker exec wls-direct-proof-10045 php bin/w server:stop ai-test-wls-direct-docker-10045
docker rm -f wls-direct-proof-10045
Get-NetTCPConnection -LocalPort 10045 -State Listen
Get-NetTCPConnection -LocalPort 10046 -State Listen
```

Results:

- Runtime probe reported Linux, PHP `8.4.22`, `SO_REUSEPORT=true`,
  `sockets=true`, `pcntl=true`, `posix=true`, `proc_open=true`, and
  `supports_reuse_port_by_wls_detector_rule=true`.
- `server:doctor` reported `supports_reuse_port=true` and
  `reuse_port_constant=true`.
- Direct status showed `ai-test-wls-direct-docker-10045` running with
  `Workers:10045,10045`, worker #1 PID `44`, worker #2 PID `46`, and both
  HTTP workers on port `10045`.
- Dispatcher status showed `ai-test-wls-dispatcher-docker-10046` running with
  `Dispatcher:10046`, workers `27608/27609`, and all `3/3` processes running.
- Initial direct probe passed `400/400` requests, `0` failures, `265.6 QPS`,
  and worker distribution `209/191` with balance ratio `1.0942`.
- Initial dispatcher probe passed `400/400` requests, `0` failures,
  `160.04 QPS`, and worker distribution `201/199` with balance ratio
  `1.0101`.
- Sequential 1200-request comparison passed with direct `395.57 QPS`
  (`598/602` workers) and dispatcher `368.47 QPS` (`600/600` workers).
- Reverse-order 1200-request comparison showed the two modes are close for
  the small health workload: dispatcher `421.37 QPS`, direct `413.18 QPS`.
- High-concurrency 3000-request comparison passed with direct `360.89 QPS`,
  average latency `403.964ms`, P95 `808.44ms`, and worker hits `1484/1516`;
  dispatcher passed `290.96 QPS`, average latency `504.573ms`, P95
  `1203.201ms`, and worker hits `1500/1500`.
- Cleanup passed: both instances stopped through `server:stop`; Docker
  container `wls-direct-proof-10045` was removed; Windows port checks found no
  LISTEN socket on `10045` or `10046`.

Notes:

- The proof image intentionally used `stream_select` because PHP `event` was
  not installed; the QPS values are suitability evidence, not production
  tuning numbers.
- The Windows host remains correctly capability-gated because local PHP still
  has no `SO_REUSEPORT` constant.

## 2026-06-22 Superseded Public Marketplace Probe

Task:
`dev/ai/codex/tasks/2026-06-22/2026-06-22-0755-official-marketplace-wls-typed-tag-live-e2e`

Scope:

- Rechecked what was then treated as the remaining `WLS-MARKETPLACE-E2E-001`
  hard gate after the direct-listen proof passed.
- Confirmed the official checkout source contract remains present and
  token-gated.
- Probed public official endpoint reachability from the current host.
- Superseded note: user later clarified that the current development
  marketplace is the local App Store project at
  `E:\WelineFramework\Framework-Official\App` / `https://app.weline.test:9523`,
  while the production application marketplace after launch is
  `https://app.aiweline.com`. `www.aiweline.com` is not the WLS Panel
  marketplace endpoint for this plan, so the reachability results below are
  retained as history only and no longer block local WLS Panel completion.

Results:

- Source route confirmed in
  `Weline\PlatformAppStore\Controller\Api\V1\Platform\Module::postList()`:
  `POST /api/v1/platform/module/list` reads `tag`, `tags`, and `tag_match`.
- Source auth confirmed: `postList()` calls
  `OfficialAccountService::resolveBearerToken(false)` and returns `401`
  `unauthorized` before `ModuleCatalogService::listPublished()` when no bearer
  token is present.
- DNS resolved `www.aiweline.com` to `47.92.25.188`.
- `Test-NetConnection www.aiweline.com -Port 443` reported TCP success.
- `curl.exe -4 -I https://www.aiweline.com/`,
  `curl.exe -4 -I http://www.aiweline.com/`, and
  `curl.exe -4 -v -k -I https://www.aiweline.com/` timed out before a usable
  HTTP response.
- `ssh weline "hostname && curl ..."` produced no output in about 60 seconds;
  the hung `ssh.exe` process was stopped.
- Added a credential-safe live E2E runner. The initial ignored task artifact was
  later promoted into the tracked WLS Panel Plan path
  `app/code/Weline/Server/doc/wls-panel-plan/tools/marketplace-typed-tag-e2e.php`.
  The runner rejects `--token=...`, reads credentials only from
  `WLS_MARKETPLACE_BEARER_TOKEN` or `--token-file`, redacts token handling in
  output, and checks both positive `module:wls` filters and the negative
  exact-match `module:wls-extra` filter.
- `php -l app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php` passed syntax
  validation; running the runner without `WLS_MARKETPLACE_BEARER_TOKEN`
  returned `reason=missing_token`, `blocked=true`, `token_redacted=true`, and
  exit code `2`.
- Running the runner with a non-secret env token `dummy-non-secret` exercised
  the live API path and all three cases timed out with `curl_errno=28` after
  about 8 seconds: `tag=module:wls`, structured
  `tags=["module:wls","custom:wls-panel-plugin"]`, and negative
  `tag=module:wls-extra`. The runner returned
  `reason=endpoint_unreachable` with exit code `2`, and the env token was
  removed after the run.
- The 16:25 and 16:33 refreshes showed no external-state change: DNS still
  resolves `www.aiweline.com` to `47.92.25.188`, TCP 443 still succeeds,
  HTTPS `curl` still times out during connection after about 5 seconds, and
  the current shell still has no `WLS_MARKETPLACE_BEARER_TOKEN`.
- The resumed 16:42 refresh showed the same state after the goal was picked up
  again: DNS still resolves to `47.92.25.188`, TCP 443 still succeeds, HTTPS
  root `curl` and tokenless API `POST /api/v1/platform/module/list` both time
  out during connection after about 5 seconds, the token-safe runner again
  returns `reason=missing_token` with exit code `2`, and the current shell
  still has no `WLS_MARKETPLACE_BEARER_TOKEN`.
- The second resumed 16:53 refresh again showed no external-state change: DNS
  still resolves to `47.92.25.188`, TCP 443 still succeeds, HTTPS root `curl`
  and tokenless API `POST /api/v1/platform/module/list` both time out during
  connection after about 5 seconds, the token-safe runner still returns
  `reason=missing_token` with exit code `2`, and the current shell still has no
  `WLS_MARKETPLACE_BEARER_TOKEN`.
- The third resumed 16:59 refresh again showed no external-state change: DNS
  still resolves to `47.92.25.188`, TCP 443 still succeeds, HTTPS root `curl`
  and tokenless API `POST /api/v1/platform/module/list` both time out during
  connection after about 5 seconds, the token-safe runner still returns
  `reason=missing_token` with exit code `2`, and the current shell still has no
  `WLS_MARKETPLACE_BEARER_TOKEN`. This satisfies the resumed blocked-audit
  threshold for the same external endpoint/token blocker.

Production resume command after launch, once `app.aiweline.com` and a token are
available:

```powershell
php app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php --deploy-current=var\deploy\current.json --resolve-endpoint-only=1
$env:WLS_MARKETPLACE_BEARER_TOKEN = '<set outside docs>'
php app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php --deploy-current=var\deploy\current.json --expected-tags=custom:wls-panel-plugin --negative-tag=module:wls-extra --min-items=1 --timeout=20
Remove-Item Env:\WLS_MARKETPLACE_BEARER_TOKEN
```

Conclusion:

- This public-endpoint gate is superseded for local development. It should not
  be used as the current WLS Panel completion blocker.
- Source-contract/unit proof remains valid, but local completion still needs a
  token-authenticated run against the local Official App generated API route.

## 2026-06-22 Marketplace Environment Scope Correction

Scope:

- Corrected the marketplace environment split after user clarification.
- Confirmed the current development marketplace project is
  `E:\WelineFramework\Framework-Official\App`.
- Confirmed the current local marketplace host is
  `https://app.weline.test:9523/`.
- Confirmed the separate official website project is
  `E:\WelineFramework\Framework-Official\Official` /
  `https://www.weline.test:9518/`; it is not the App Store marketplace target.
- Recorded that production application marketplace validation belongs to
  `https://app.aiweline.com` after launch.
- Solidified the Deploy-side environment contract so local development can use
  the local App Store while deployed tests and production release probes use the
  online App Store automatically.

Validation commands:

```powershell
Resolve-DnsName app.weline.test
Test-NetConnection app.weline.test -Port 9523 -InformationLevel Detailed
php bin/w server:status # from E:\WelineFramework\Framework-Official\App\weline
php bin/w server:status # from E:\WelineFramework\Framework-Official\Official\weline
php bin/w server:start wls --host app.weline.test --port 9523 --ssl-domain app.weline.test # from E:\WelineFramework\Framework-Official\App\weline
php bin/w setup:upgrade --route --skip-composer-dump # from E:\WelineFramework\Framework-Official\App\weline
php bin/w setup:upgrade --skip-composer-dump # from E:\WelineFramework\Framework-Official\App\weline
php bin/w setup:upgrade --model -m Weline_Server --skip-composer-dump # from E:\WelineFramework\Framework-Official\App\weline
rg -n "app\.weline\.test|9523|db\.sqlite|sandbox_db\.sqlite|'type'\s*=>\s*'sqlite'" app/etc/env.php # from E:\WelineFramework\Framework-Official\App\weline
rg -n "appstore|app\.weline\.test|app\.aiweline\.com" app/code/Weline/Deploy/Service/DeployOrchestratorService.php app/code/Weline/Deploy/Service/DeployWebhookReleaseService.php app/code/Weline/AppStore/doc/README.md app/code/Weline/Deploy/doc/backend-config.md
```

Results:

- `app.weline.test` resolves to `127.0.0.1`.
- TCP `9523` currently fails because the App project WLS is not running.
- `Framework-Official\App\weline\app\etc\env.php` now defines sqlite
  `db.master` and `sandbox_db.master` paths under the App checkout and still
  defines `wls.host=app.weline.test`, `wls.port=9523`, and
  `ssl_domain=app.weline.test`.
- `php bin/w server:status` in `Framework-Official\App\weline` reports no
  running server instance.
- `php bin/w setup:upgrade --route --skip-composer-dump` succeeds in the App
  checkout after the local sqlite switch, so generated route metadata can be
  refreshed without the unavailable local PostgreSQL role.
- Full App setup on sqlite was blocked by the inherited local-description
  schema shape: `m_eav_attribute_local_description` declared both
  `id integer PRIMARY KEY AUTOINCREMENT` and a composite
  `PRIMARY KEY ("local_code", "id")`, which SQLite rejected as
  `table ... has more than one primary key`.
- The DEV core fix is now in
  `Weline\Framework\Database\Schema\SchemaMigrationExecutor`: sqlite
  composite-primary-key table creation no longer forwards `AUTO_INCREMENT` to
  column definitions. A no-file PHP stdin probe created a temporary sqlite
  table with `PRIMARY KEY ("id", "local_code")`, confirmed no
  `AUTOINCREMENT` in `sqlite_master.sql`, and confirmed PRAGMA primary-key
  columns `id` and `local_code`.
- `php bin/w setup:upgrade --model -m Weline_Server --skip-composer-dump` is
  also blocked before model execution by the environment-dependency auto-repair
  path. The nested repair scripts lose the expected Windows PATH and report
  `chcp` / `php` as not recognized.
- Starting the App project WLS on `app.weline.test:9523` after the partial
  setup is currently blocked by the missing WLS Server table
  `m_weline_server_ssl_certificate`.
- `php bin/w server:status` in `Framework-Official\Official\weline` reports
  the separate website project running on `https://127.0.0.1:9518`.
- `DeployOrchestratorService` now writes `deploy_mode_source`,
  `appstore_environment`, `appstore_platform_url`, and
  `appstore_platform_url_source` into `var/deploy/current.json` for release and
  rollback payloads.
- `DeployWebhookReleaseService` copies those fields into webhook health output
  when they are present in `current.json`.
- Explicit `deploy=dev/local` in `app/etc/env.php` resolves the marketplace URL
  from `WELINE_APPSTORE_PLATFORM_URL`, then `appstore.platform_url`, then the
  local default `https://app.weline.test:9523`; non-local or missing explicit
  deploy mode resolves to `https://app.aiweline.com`.

Conclusion:

- Current WLS Panel marketplace validation must use the local App Store
  checkout and host, not `www.weline.test:9518` and not `www.aiweline.com`.
- `www.aiweline.com` is removed from the WLS marketplace gate.
- Local development should point WLS Panel marketplace calls to
  `https://app.weline.test:9523`.
- Deployed tests and production release probes should read the deployed
  `appstore_platform_url` from `var/deploy/current.json`; non-local or missing
  explicit deploy mode resolves to `https://app.aiweline.com` and records
  `deploy_mode_source`.
- The remaining local marketplace E2E blocker is now the App checkout startup
  path: synchronize the DEV core sqlite fix to
  `E:\WelineFramework\Framework-Official\App` through the approved "分项"
  workflow, rerun App setup with `--skip-env-check --skip-composer-dump`,
  start WLS on `9523`, and run the typed-tag API proof against the generated
  local App Store route.

Authorized sync command:

- Superseded by `92-local-appstore-sync-manifest.md`.
- The old broad resume command was intentionally removed from this evidence
  file so the next run uses the App-only `-Sites` scope, `-DryRun` first, and
  the current allowed include list.

Post-sync local App Store verification sequence:

```powershell
cd E:\WelineFramework\Framework-Official\App\weline
php bin/w setup:upgrade --skip-env-check --skip-composer-dump
php bin/w setup:upgrade --route --skip-env-check --skip-composer-dump
php bin/w server:start wls --host app.weline.test --port 9523 --ssl-domain app.weline.test
curl.exe -k -I --max-time 12 --noproxy * --resolve app.weline.test:9523:127.0.0.1 https://app.weline.test:9523/
```

Post-start typed-tag runner target:

```powershell
$env:WLS_MARKETPLACE_BEARER_TOKEN = '<set outside docs>'
php E:\WelineFramework\DEV-workspace\app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php --endpoint=https://app.weline.test:9523/api/v1/platform/module/list --expected-tags=custom:wls-panel-plugin --negative-tag=module:wls-extra --min-items=1 --timeout=20
Remove-Item Env:\WLS_MARKETPLACE_BEARER_TOKEN
```

## 2026-06-22 GitNexus CLI Recovery Probe

Task:
`dev/ai/codex/tasks/2026-06-22/2026-06-22-0201-wls-gitnexus-cli-recovery`

Scope:

- Recovered the GitNexus CLI path for the DEV workspace before any further
  production symbol edits.
- No production code was changed.
- This replaces the earlier `Not a git repository` diagnosis with a narrower
  root cause: the GitNexus Node child process could not resolve `git` through
  the inherited Windows PATH.

Validation commands:

```powershell
$env:Path='C:\Program Files\Git\cmd;C:\Windows\System32;C:\Windows;C:\nvm4w\nodejs;C:\Users\17142\AppData\Roaming\npm'
& 'C:\nvm4w\nodejs\node.exe' 'C:\Users\17142\AppData\Roaming\npm\node_modules\gitnexus\dist\cli\index.js' status
& 'C:\nvm4w\nodejs\node.exe' 'C:\Users\17142\AppData\Roaming\npm\node_modules\gitnexus\dist\cli\index.js' list
& 'C:\nvm4w\nodejs\node.exe' 'C:\Users\17142\AppData\Roaming\npm\node_modules\gitnexus\dist\cli\index.js' impact AttackDetector -r dev-workspace -d upstream --depth 1
```

Results:

- `status` resolved repository `E:\WelineFramework\DEV-workspace`.
- `status` reported indexed commit `1763632` and current commit `7eb6dd6`, so
  the index is usable but stale.
- `list` showed registered repository `dev-workspace` with `6461` files,
  `114976` symbols, `295379` edges, `5163` clusters, and `300` processes.
- The dry upstream impact query for indexed symbol `AttackDetector` returned
  LOW risk, three direct depth-1 hits, and affected module `Dispatcher`.
- A probe against newer symbol `WlsPanelProjectConfigCenterService` still
  returned target-not-found, confirming that newer production symbol packets
  should refresh the index before source edits.

Conclusion:

- The GitNexus CLI guardrail is available again through the minimal-PATH direct
  Node command form.
- The remaining guardrail caveat is index freshness, not repository discovery.
  Before editing newer function/class/method symbols, run a refresh analysis or
  limit impact evidence to symbols known to exist in the stale index.

Follow-up full index recovery:

```powershell
$env:Path='C:\Program Files\Git\cmd;C:\Windows\System32;C:\Windows;C:\nvm4w\nodejs;C:\Users\17142\AppData\Roaming\npm'
$env:NODE_OPTIONS='--max-old-space-size=16384'
& 'C:\nvm4w\nodejs\node.exe' 'C:\Users\17142\AppData\Roaming\npm\node_modules\gitnexus\dist\cli\index.js' analyze --index-only --worker-timeout 120 --name dev-workspace
& 'C:\nvm4w\nodejs\node.exe' 'C:\Users\17142\AppData\Roaming\npm\node_modules\gitnexus\dist\cli\index.js' status
& 'C:\nvm4w\nodejs\node.exe' 'C:\Users\17142\AppData\Roaming\npm\node_modules\gitnexus\dist\cli\index.js' impact WlsPanelProjectConfigCenterService -r dev-workspace -d upstream --depth 1
```

Results:

- The first plain incremental `analyze` attempt exited `1` and left
  `incrementalInProgress` in `.gitnexus/meta.json`; no refreshed metadata was
  committed.
- Pure index recovery with a 120-second worker timeout completed successfully:
  `Repository indexed successfully (1768.9s)`, `123632` nodes, `309694` edges,
  `5427` clusters, and `300` flows.
- `status` now reports repository `E:\WelineFramework\DEV-workspace`, indexed
  commit `7eb6dd6`, current commit `7eb6dd6`, and `Status: up-to-date`.
- `impact WlsPanelProjectConfigCenterService -r dev-workspace -d upstream
  --depth 1` returns LOW risk with one direct upstream import:
  `app/code/Weline/Server/Controller/Backend/WlsPanel.php`.
- `.gitnexus/meta.json` now has `incrementalInProgress` cleared and records
  `files=6230`, `nodes=123632`, `edges=309694`, `communities=5427`, and
  `processes=300`.

## 2026-06-22 Database Restore Rollback Automation

Task:
`dev/ai/codex/tasks/2026-06-22/2026-06-22-1128-wls-db-restore-rollback-automation`

Scope:

- Added explicit restore rollback automation to the standalone WLS Database
  Manager panel.
- Rollback is offered only when recent `restore_executed` audit records point
  to a validated `pre_restore_artifact`.
- The rollback path requires `ROLLBACK_DB_RESTORE`, reuses the normal guarded
  restore execution boundary internally, preserves the PostgreSQL
  `RESET_PG_SCHEMA` gate for plain SQL artifacts, and writes sanitized
  `restore_rollback_executed` / `restore_rollback_failed` audit events.

Validation commands:

```powershell
php -l app\code\Weline\DbManager\Service\WlsDatabaseRestoreExecutionService.php
php -l app\code\Weline\DbManager\Controller\Backend\WlsDbManager.php
php -l app\code\Weline\DbManager\view\templates\Backend\WlsDbManager\index.phtml
php -l dev\ai\codex\tasks\2026-06-22\2026-06-22-1128-wls-db-restore-rollback-automation\artifacts\restore-rollback-guard-probe.php
php dev\ai\codex\tasks\2026-06-22\2026-06-22-1128-wls-db-restore-rollback-automation\artifacts\restore-rollback-guard-probe.php
php dev\ai\codex\tasks\2026-06-22\2026-06-22-1128-wls-db-restore-rollback-automation\artifacts\restore-rollback-browser-fixture.php prepare
node --check dev\ai\codex\tasks\2026-06-22\2026-06-22-1128-wls-db-restore-rollback-automation\artifacts\restore-rollback-browser-smoke.mjs
$env:WLS_BASE_URL='http://127.0.0.1:10038'
$env:CDP_PORT='9368'
node dev\ai\codex\tasks\2026-06-22\2026-06-22-1128-wls-db-restore-rollback-automation\artifacts\restore-rollback-browser-smoke.mjs
php dev\ai\codex\tasks\2026-06-22\2026-06-22-1128-wls-db-restore-rollback-automation\artifacts\restore-rollback-browser-fixture.php cleanup
php bin\w server:stop ai-test-wls-db-restore-rollback-10038
php bin\w server:status ai-test-wls-db-restore-rollback-10038
curl.exe -s -o NUL -w "%{http_code}" --max-time 5 http://127.0.0.1:10038/
```

Results:

- DbManager i18n CSV parse passed with `en_US=659` and `zh_Hans_CN=659`.
- `restore-rollback-guard-probe.php` returned `passed=true` and verified:
  empty audit guarded state, ready MySQL rollback candidate, disabled Profile
  block, wrong `ROLLBACK_DB_RESTORE` phrase block, unrecorded artifact block,
  PostgreSQL plain SQL `RESET_PG_SCHEMA` requirement, missing reset block,
  restore-plan readiness, sanitized failure audit events, and fixture cleanup.
- Browser fixture prepared a temporary PostgreSQL Project Profile and a
  confined rollback artifact, then cleanup removed the Project Profile,
  artifact, sidecar metadata, and audit line.
- Logged-in Chrome CDP browser smoke passed on
  `ai-test-wls-db-restore-rollback-10038`: desktop `1280` and phone `390`,
  light/dark themes, no login fallback, `[data-wls-db-manager-shell]` present,
  `[data-wdb-restore-rollback-form]` present,
  `data-wdb-restore-can-rollback=1`, expected artifact match,
  `ROLLBACK_DB_RESTORE`, `RESET_PG_SCHEMA`, two confirmation checkboxes,
  enabled submit button, audit/destructive copy visible, no fatal text, no
  horizontal overflow, and fitting controls.
- Screenshot evidence:
  `restore-rollback-desktop-1280-light.png`,
  `restore-rollback-desktop-1280-dark.png`,
  `restore-rollback-phone-390-light.png`, and
  `restore-rollback-phone-390-dark.png`.
- Cleanup passed: `server:stop` drained Dispatcher and both Workers, final
  `server:status` reported `0/0`, port `10038` returned `000`, and the backup
  directory had zero remaining `codex-rollback-ui-*` artifacts.

Residual risk:

- The browser smoke deliberately did not submit the destructive rollback form.
  The rollback gate is covered by the focused service probe, while the actual
  restore mutation path remains covered by the existing disposable
  MySQL/MariaDB, PostgreSQL custom-format, and PostgreSQL plain SQL reset
  restore harnesses.

## 2026-06-22 Integrated Panel Regression After DB Rollback

Task:
`dev/ai/codex/tasks/2026-06-22/2026-06-22-1240-wls-panel-integrated-rollback-regression`

Scope:

- Revalidated the integrated standalone WLS Panel after
  `WLS-DB-ROLLBACK-001`.
- No production code changed in this packet.
- The regression covered the standalone shell plus installed PHP, DB, File,
  and Deploy plugin pages.

Validation commands:

```powershell
$env:WLS_PANEL_ENABLED='1'
php bin\w server:start ai-test-wls-panel-reg-rollback-10040 -p 10040 --no-ssl -c 2 --worker-memory-limit=512M

$env:WLS_BASE_URL='http://127.0.0.1:10040'
$env:WLS_BACKEND_ENTRY='U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8'
$env:CDP_PORT='9369'
$env:BROWSER_PATH='C:/Program Files/Google/Chrome/Application/chrome.exe'
$env:OUT_DIR='E:/WelineFramework/DEV-workspace/dev/ai/codex/tasks/2026-06-22/2026-06-22-1240-wls-panel-integrated-rollback-regression/artifacts'
node dev\ai\codex\tasks\2026-06-19\2026-06-19-1551-wls-panel-multi-page-visual-smoke\artifacts\wls-panel-multi-page-cdp-smoke.mjs

php bin\w server:stop ai-test-wls-panel-reg-rollback-10040
php bin\w server:status ai-test-wls-panel-reg-rollback-10040
curl.exe -s -o NUL -w "%{http_code}" http://127.0.0.1:10040/
```

Results:

- `multi-page-cdp-smoke-result.json` returned `passed=true`.
- Pages covered: Dashboard, Marketplace, Security, PHP Manager, Database
  Manager, File Manager, and Deploy.
- Viewports covered: desktop `1440` and phone `390`.
- Assertions passed: no login fallback, target shell present, no fatal text,
  no SQL/404/405 runtime text, no horizontal overflow, expected page text
  present, and buttons fit their containers.
- Screenshot evidence includes 14 PNG files under the task `artifacts/`
  directory.
- Manual screenshot review focused on `db-manager-desktop-dark-1440.png`,
  `db-manager-phone-dark-390.png`, and `dashboard-phone-dark-390.png`.
- Cleanup passed: `server:stop` drained Dispatcher and two Workers,
  `server:status` reported `全部停止 (0/0)`, and port `10040` returned `000`.

Residual risk:

- This is a current regression proof after DB rollback. It does not replace the
  final release-candidate sweep that must run after remaining enabled packets
  and external gates land.

## 2026-06-22 Plugin Default Entry UI Consumption

Scope:

- Tightened the standalone WLS Panel marketplace template so installed-plugin
  cards consume the service-normalized `panel_entry_url` before raw legacy URL
  fields. This completes the template side of the documented rule that the
  first valid `wls_panel.menu[]` item can act as the plugin default panel entry.
- Normalized the built-in marketplace candidate card output through the panel
  escape helper for plugin name, module, status, summary, tag, and install URL
  query text.

Validation command:

```powershell
php -l app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
```

Result:

- PHP reported no syntax errors for the WLS Panel template. The local PHP CLI
  still emits the existing duplicate-extension warnings before lint output.

Residual risk:

- This is a template-level contract consumption fix. It does not replace the
  final local App Store API E2E gate for `module:wls` online package discovery.

## 2026-06-22 AppStore Runtime Marketplace Endpoint Resolver

Scope:

- Added `Weline\AppStore\Service\AppStorePlatformUrlResolver` so AppStore
  runtime clients share the same marketplace endpoint policy as Deploy.
- Explicit local mode in `app/etc/env.php` (`deploy=dev/local`) keeps local
  `WELINE_APPSTORE_PLATFORM_URL` or `appstore.platform_url`, which is the
  current `https://app.weline.test:9523` development target.
- When local mode is not explicitly declared and
  `var/deploy/current.json` records `appstore_environment=production`, the
  resolver uses `appstore_platform_url` from the deploy artifact before
  env/config, so deployed WLS Panel marketplace checks automatically use
  `https://app.aiweline.com`.
- `AccountBindService` and `ModuleInstallerService` now call the resolver, so
  account binding, marketplace list/update checks, and module download/install
  share the same endpoint decision.

Validation commands:

```powershell
gitnexus impact --repo dev-workspace AccountBindService --direction upstream
gitnexus impact --repo dev-workspace ModuleInstallerService --direction upstream
php -l app\code\Weline\AppStore\Service\AppStorePlatformUrlResolver.php
php -l app\code\Weline\AppStore\Service\AccountBindService.php
php -l app\code\Weline\AppStore\Service\ModuleInstallerService.php
php vendor\phpunit\phpunit\phpunit --bootstrap app\code\Weline\AppStore\test\bootstrap.php app\code\Weline\AppStore\test\Unit\AccountBindServiceTest.php app\code\Weline\AppStore\test\Unit\ModuleInstallerServiceTest.php
```

Results:

- GitNexus impact was LOW risk for both edited service classes:
  `AccountBindService` has four direct importing backend controllers, and
  `ModuleInstallerService` has two direct importers.
- PHP reported no syntax errors for the new resolver and the two updated
  services. The local PHP CLI still emits the existing duplicate-extension
  warnings before lint output.
- AppStore focused PHPUnit passed: 30 tests, 69 assertions. The test run still
  prints the existing Windows `chcp` PATH warning from command-building checks.
- A temporary resolver probe verified two endpoint branches:
  `appstore_environment=production` in `var/deploy/current.json` returns
  `https://app.aiweline.com` with source `deploy:var/deploy/current.json`;
  explicit local `deploy=dev` in `app/etc/env.php` keeps
  `https://app.weline.test:9523` from `appstore.platform_url`.
- `git diff --check` passed for the changed AppStore services and WLS Panel
  plan docs, and `rg -n "[ \t]+$"` returned no trailing-whitespace hits.

Residual risk:

- This closes the runtime endpoint-selection gap. It still does not replace the
  local token-authenticated App Store API E2E against
  `https://app.weline.test:9523/api/v1/platform/module/list`, which requires the
  approved DEV-to-App sync and local App WLS startup.

## 2026-06-22 WLS/AppStore Marketplace Endpoint Observability

Scope:

- Made the resolved App Store marketplace endpoint visible in both the
  standalone WLS Panel marketplace and the AppStore backend marketplace page.
- Added machine-readable page attributes for the resolved `platform_url` and
  resolver source so browser checks can prove which marketplace is active.
- Kept the local/deployed policy explicit: `deploy=dev/local` uses
  `https://app.weline.test:9523`, while deployed production checks read
  `var/deploy/current.json` and default to `https://app.aiweline.com`.
- Wired the WLS Panel to consume the AppStore resolver dynamically, so the
  Server module can display the decision without a hard compile-time AppStore
  module dependency.

Validation commands:

```powershell
gitnexus impact --repo dev-workspace AccountBindService --direction upstream
gitnexus impact --repo dev-workspace WlsPanel --direction upstream
php -l app\code\Weline\AppStore\Service\AppStorePlatformUrlResolver.php
php -l app\code\Weline\AppStore\Service\AccountBindService.php
php -l app\code\Weline\AppStore\Controller\Backend\Index.php
php -l app\code\Weline\Server\Controller\Backend\WlsPanel.php
php -l app\code\Weline\AppStore\view\templates\Backend\Index\index.phtml
php -l app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
php vendor\phpunit\phpunit\phpunit --bootstrap app\code\Weline\AppStore\test\bootstrap.php app\code\Weline\AppStore\test\Unit\AccountBindServiceTest.php app\code\Weline\AppStore\test\Unit\ModuleInstallerServiceTest.php
php bin\w server:start ai-test-wls-appstore-endpoint-10052 --host 127.0.0.1 -p 10052 -c 2
$env:WLS_BASE_URL='http://127.0.0.1:10052'
$env:CDP_PORT='9352'
node dev\ai\codex\tasks\2026-06-22\2026-06-22-2056-wls-appstore-endpoint-observability\artifacts\wls-appstore-endpoint-cdp-smoke.mjs
php bin\w server:stop ai-test-wls-appstore-endpoint-10052
php bin\w server:status ai-test-wls-appstore-endpoint-10052
curl.exe -k -I --max-time 5 https://127.0.0.1:10052/
```

Results:

- GitNexus impact was LOW risk for `AccountBindService` and `WlsPanel`. The
  focused `Index` symbol impact lookup matched another class name and was
  discarded as ambiguous.
- PHP lint passed for the touched resolver, services, controllers, and
  templates. The local PHP CLI still emits the existing duplicate-extension
  warnings before lint output.
- AppStore focused PHPUnit passed: 30 tests, 69 assertions. The run still
  prints the existing Windows `chcp` warning from command-building checks.
- An isolated resolver probe returned the expected two branches:
  local `deploy=dev` resolves to `https://app.weline.test:9523` with source
  `config:appstore.platform_url`; production deploy artifact mode resolves to
  `https://app.aiweline.com` with source `deploy:var/deploy/current.json`.
- Codex in-app Browser was blocked by the local self-signed certificate
  (`ERR_CERT_AUTHORITY_INVALID`), so the browser proof used Chrome CDP with
  `--ignore-certificate-errors`.
- The final CDP smoke returned `passed=true`, `consoleIssueCount=0`, and
  covered WLS Panel marketplace plus AppStore backend marketplace at desktop
  `1440` and phone `390`.
- Both pages exposed the local development endpoint as
  `https://app.weline.test:9523` with source `config:appstore.platform_url`;
  there was no login fallback, fatal text, target-page console error, or
  horizontal overflow.
- Screenshot and JSON evidence were saved under
  `dev/ai/codex/tasks/2026-06-22/2026-06-22-2056-wls-appstore-endpoint-observability/artifacts/`.
- Cleanup passed: `server:stop` drained the test instance,
  `server:status` reported `全部停止 (0/0)`, and the final curl probe could not
  connect to port `10052`.

Residual risk:

- This closes endpoint observability and local/deployed endpoint selection for
  the UI/runtime client path. It still does not replace the local
  token-authenticated App Store API E2E against
  `https://app.weline.test:9523/api/v1/platform/module/list`, which remains
  blocked on the approved DEV-to-App sync, local App WLS startup, and local
  bearer token/account setup.

## 2026-06-22 AppStore Resolver Residual Local-Config Guard

Scope:

- Tightened `Weline\AppStore\Service\AppStorePlatformUrlResolver` so local
  env/config marketplace URLs are honored only when `app/etc/env.php`
  explicitly declares `deploy=dev/local`.
- Added a local default of `https://app.weline.test:9523` for explicit local
  mode, matching the Deploy-side local default.
- Ensured non-local resolver paths read production `var/deploy/current.json` or
  fall back to `https://app.aiweline.com` without reading leftover local
  `WELINE_APPSTORE_PLATFORM_URL` or `appstore.platform_url` values.

Validation commands:

```powershell
gitnexus impact --repo dev-workspace AppStorePlatformUrlResolver --direction upstream --depth 2
php -l app\code\Weline\AppStore\Service\AppStorePlatformUrlResolver.php
php -l app\code\Weline\AppStore\test\Unit\AppStorePlatformUrlResolverTest.php
php vendor\phpunit\phpunit\phpunit --bootstrap app\code\Weline\AppStore\test\bootstrap.php app\code\Weline\AppStore\test\Unit\AppStorePlatformUrlResolverTest.php
php vendor\phpunit\phpunit\phpunit --bootstrap app\code\Weline\AppStore\test\bootstrap.php app\code\Weline\AppStore\test\Unit\ModuleInstallerServiceTest.php
php vendor\phpunit\phpunit\phpunit --bootstrap app\code\Weline\AppStore\test\bootstrap.php app\code\Weline\AppStore\test\Unit\AccountBindServiceTest.php
php vendor\phpunit\phpunit\phpunit --bootstrap app\code\Weline\AppStore\test\bootstrap.php app\code\Weline\AppStore\test\Unit
```

Isolated resolver probe:

```text
local -> https://app.weline.test:9523, source env:WELINE_APPSTORE_PLATFORM_URL, environment local
prod-env-residue -> https://app.aiweline.com, source default:production, environment production
prod-current -> https://app.aiweline.com, source deploy:var/deploy/current.json, environment production
```

Results:

- GitNexus did not find the new `AppStorePlatformUrlResolver` symbol, so impact
  review used the direct caller search for `AccountBindService`,
  `ModuleInstallerService`, WLS Panel endpoint display, and Deploy docs.
- PHP lint passed. The local PHP CLI still emits duplicate-extension warnings
  before normal output.
- `AppStorePlatformUrlResolverTest` passed: 3 tests, 9 assertions.
- `ModuleInstallerServiceTest` passed: 26 tests, 63 assertions.
- `AccountBindServiceTest` passed: 4 tests, 6 assertions.
- The full AppStore unit folder passed after aligning the bootstrap with the
  framework minimum constants and the update test with the documented runtime
  version preference, then adding the endpoint resolver regression contract:
  41 tests, 125 assertions.
- The isolated resolver probe proved the exact risk boundary: explicit local
  mode can use the local AppStore URL, while production mode ignores a leftover
  local env value and either uses deployment metadata or the production
  default.

Residual risk:

- This closes runtime endpoint leakage from stale local config. It still does
  not replace the local token-authenticated App Store API E2E against
  `app.weline.test:9523`.

## 2026-06-22 Local App Store Gate Recheck

Scope:

- Rechecked the local App Store checkout without running the approved
  DEV-to-App sync and without editing `E:\WelineFramework\Framework-Official\App`.
- Confirmed whether the final WLS marketplace API E2E can proceed from the
  current external state.

Read-only commands:

```powershell
Test-NetConnection -ComputerName app.weline.test -Port 9523
curl.exe -k -I --max-time 8 --noproxy * --resolve app.weline.test:9523:127.0.0.1 https://app.weline.test:9523/
git -C E:\WelineFramework\Framework-Official\App\weline status --short
git -C E:\WelineFramework\Framework-Official\App\weline rev-parse --abbrev-ref HEAD
git -C E:\WelineFramework\Framework-Official\App\weline rev-parse --short HEAD
Select-String -Path E:\WelineFramework\Framework-Official\App\weline\app\etc\env.php -Pattern "appstore|deploy|db|sqlite|host|port" -Context 0,1
Select-String -Path E:\WelineFramework\Framework-Official\App\weline\app\code\Weline\Framework\Database\Schema\SchemaMigrationExecutor.php -Pattern "composite|AUTOINCREMENT|primary key|primaryKey|autoIncrement" -Context 2,3
Select-String -Path E:\WelineFramework\DEV-workspace\app\code\Weline\Framework\Database\Schema\SchemaMigrationExecutor.php -Pattern "composite|AUTOINCREMENT|primary key|primaryKey|autoIncrement" -Context 2,3
```

Results:

- `app.weline.test:9523` is still not listening. `Test-NetConnection` returned
  `TcpTestSucceeded=False`, and curl failed with connection error `7`.
- The actual App project repository is
  `E:\WelineFramework\Framework-Official\App\weline`, branch `dev`, commit
  `f130551de`.
- The App checkout has pre-existing unrelated local changes in
  `app/code/Weline/Admin/Service/BackendLoginReturnUrlService.php` and
  `app/code/Weline/Admin/Test/Unit/Service/BackendLoginReturnUrlServiceTest.php`.
  These must be preserved by the later sync workflow.
- App `env.php` is already aligned for this gate: `deploy=dev`, sqlite
  database paths under the App checkout, and WLS host/port
  `app.weline.test:9523`.
- The App checkout still lacks the DEV-side sqlite composite-primary-key guard:
  its `SchemaMigrationExecutor` adds `AUTO_INCREMENT` whenever
  `$col->autoIncrement` is true, while DEV now guards that with
  `!($isSqlite && $hasCompositePk)`.

Conclusion:

- The final local App Store API E2E must remain gated. The next authorized step
  is still to run the approved `分项` sync path so the App checkout receives the
  DEV sqlite composite-primary-key fix, then rerun App setup, start WLS on
  `app.weline.test:9523`, and execute the token-safe typed-tag API runner.

## 2026-06-22 Local AppStore Sync Manifest

Scope:

- Added `92-local-appstore-sync-manifest.md` to make the remaining local App
  Store gate executable after explicit `分项` authorization.
- The manifest does not run sync. It records the allowed include paths, the
  forbidden App checkout paths, App-only dry-run shape, post-sync setup/start
  commands, typed-tag runner command, and evidence requirements.

Read-only checks:

```powershell
Get-Content -Path dev\tools\fenxiang\fenxiang-update.ps1 | Select-Object -First 120
rg -n "param\(|IncludePaths|Dry|WhatIf|Path|Branch|fenxiang" dev\tools\fenxiang\fenxiang-update.ps1 dev\tools\fenxiang -g "*.ps1" -g "*.md"
Get-Content -Path app\code\Weline\Framework\Database\Schema\SchemaMigrationExecutor.php | Select-Object -Skip 315 -First 45
Get-Content -Path E:\WelineFramework\Framework-Official\App\weline\app\code\Weline\Framework\Database\Schema\SchemaMigrationExecutor.php | Select-Object -Skip 315 -First 45
git -C E:\WelineFramework\Framework-Official\App\weline status --short -- app/code/Weline/Framework/Database/Schema/SchemaMigrationExecutor.php app/etc/env.php app/code/Weline/Admin
```

Results:

- `fenxiang-update.ps1` supports `-Branch`, `-IncludePaths`, `-Sites`, and
  `-DryRun`, so the manifest uses an App-only dry-run before any real sync.
- The executable command example now lists WLS Panel Plan files and runner
  fixtures individually instead of passing the whole `wls-panel-plan`
  directory.
- The App checkout still has unrelated Admin return-url work and must preserve
  it.
- The App checkout still lacks DEV's sqlite composite-primary-key
  `AUTO_INCREMENT` guard, so the core schema file remains a required sync path.
- `00-INDEX.md`, `90-completion-audit-and-next-gates.md`, and
  `96-requirement-traceability.md` now point to the manifest as the source of
  truth for the authorized local AppStore sync sequence.

Residual risk:

- The manifest closes the operational ambiguity around the next authorized
  step, but it does not complete the local token-authenticated App Store API
  E2E. That remains gated by explicit `分项` authorization, App setup, App WLS
  startup on `app.weline.test:9523`, and a local bearer token/account.

## 2026-06-22 Local AppStore Manifest Guard

Scope:

- Added a non-mutating manifest checker for the passphrase-gated local AppStore
  sync.
- Rechecked the local/production AppStore endpoint split through the tracked
  deploy-current fixtures.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-local-development.json --resolve-endpoint-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --resolve-endpoint-only=1
```

Results:

- PHP lint passed for
  `tools/validate-local-appstore-sync-manifest.php`. The local PHP CLI still
  emits duplicate-extension warnings before normal output.
- The manifest checker returned `ok=true` with one site target:
  `E:\WelineFramework\Framework-Official\App`.
- The checker confirmed 30 allowed paths, 30 command include paths, 5 forbidden
  paths, no errors, and no warnings.
- The checker confirmed the manifest contains the local App checkout,
  `https://app.weline.test:9523`, production `https://app.aiweline.com`, and
  the two `www.*` hosts only as non-marketplace endpoints.
- The local deploy-current fixture resolved to
  `https://app.weline.test:9523/api/v1/platform/module/list` with
  `appstore_environment=local`.
- The production deploy-current fixture resolved to
  `https://app.aiweline.com/api/v1/platform/module/list` with
  `appstore_environment=production`.
- Both endpoint-only fixture checks passed without making a marketplace network
  request or writing credentials to the repository.

Residual risk:

- This strengthens the sync/deployment gate, but it still does not complete the
  authenticated local AppStore API E2E. That remains gated by explicit `分项`
  authorization, App checkout setup, App WLS startup on `app.weline.test:9523`,
  and a local bearer token/account.

## 2026-06-22 Machine-Readable Completion Audit Gate

Scope:

- Added `tools/wls-panel-completion-audit.php` so the final WLS Panel goal can
  be checked by a repeatable machine-readable gate instead of a manual skim of
  `90-completion-audit-and-next-gates.md`.
- The tool reads `90`, `96`, `95`, and `92`, verifies required plan files and
  endpoint-policy text, parses both the completion matrix and requirement
  traceability matrix, and reports non-`Proven` rows.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php --fail-on-incomplete=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-local-development.json --resolve-endpoint-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --resolve-endpoint-only=1
```

Results:

- PHP lint passed for `tools/wls-panel-completion-audit.php`. The local PHP CLI
  still emits duplicate-extension warnings before normal output.
- Default completion audit returned `ok=true`, `complete=false`,
  `completion_matrix_total=14`, `completion_proven_rows=13`, and one
  completion-matrix incomplete row:
  `WLS marketplace installs WLS-specific plugins through typed meta tags.`
- The same run returned `traceability_matrix_total=22`,
  `traceability_proven_rows=20`, and two traceability incomplete rows:
  `WLS Panel marketplace reads only WLS-compatible plugins from AppStore
  through typed module tags.` and `WLS Panel installs plugins from online
  marketplace, while local development uses local App Store.`
- The incomplete row status is
  `Partial / local App Store API E2E gate`, with the remaining gate pointing to
  App checkout startup, `app.weline.test:9523`, and exact `tag=module:wls`
  / `module:wls-extra` API proof.
- `--fail-on-incomplete=1` returned the expected non-zero completion gate,
  proving this tool will block final release while the marketplace API gate is
  still incomplete.
- The manifest guard still returned `ok=true`: one App site target, 30 allowed
  paths, 30 include paths, 5 forbidden paths, no errors, no warnings.
- The local deploy-current fixture resolved to
  `https://app.weline.test:9523/api/v1/platform/module/list`.
- The production deploy-current fixture resolved to
  `https://app.aiweline.com/api/v1/platform/module/list`.
- `00-INDEX.md`, `90`, `95`, and `96` now reference the completion audit tool
  as the final machine-readable gate.

Residual risk:

- This gate prevents accidental completion claims, but it does not replace the
  remaining authenticated local AppStore API E2E. The full WLS Panel goal must
  stay active until that API E2E passes and the completion audit exits
  successfully with `--fail-on-incomplete=1`.

## 2026-06-22 Local AppStore Readiness Probe

Scope:

- Added a read-only readiness probe for the local App Store checkout used by
  the WLS Panel marketplace typed-tag E2E.
- The probe records local App checkout readiness without running setup,
  starting WLS, syncing files, or printing credential values.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php
```

Results:

- PHP lint passed for `tools/local-appstore-readiness-probe.php`. The local PHP
  CLI still emits duplicate-extension warnings before normal output.
- The readiness probe returned `ok=true`, `ready=false`, and confirmed:
  `app_root_exists=true`, `app_env_readable=true`,
  `app_env_mentions_local_appstore=true`, `app_schema_readable=true`, and
  `dev_schema_has_sqlite_composite_pk_guard=true`.
- The current blockers are:
  `app_schema_has_sqlite_composite_pk_guard=false`,
  `app_port_listening=false`, and `bearer_token_env_present=false`.
- The probe resolved the expected local endpoint as
  `https://app.weline.test:9523` and reported the TCP listener as closed.
- The probe redacts token state as `<not set>` / `<redacted>` and does not read
  or print token values.

Residual risk:

- This makes the remaining local App Store gate explicit, but it does not run
  the authenticated API E2E. The next executable step is still the authorized
  App-only `分项` sync, App setup/startup on `app.weline.test:9523`, and the
  token-safe typed-tag runner.

## 2026-06-22 Readiness Manifest Refresh

Scope:

- Revalidated the scoped App-only sync manifest after adding the readiness
  probe to the allowed WLS Panel Plan tool set.
- Revalidated endpoint-only local and production marketplace resolution.
- Revalidated the machine-readable completion audit with the new required tool.

Commands:

```powershell
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php --fail-on-incomplete=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-local-development.json --resolve-endpoint-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --resolve-endpoint-only=1
git diff --check -- app/code/Weline/Server/doc/wls-panel-plan/00-INDEX.md app/code/Weline/Server/doc/wls-panel-plan/77-current-integrated-verification-evidence.md app/code/Weline/Server/doc/wls-panel-plan/90-completion-audit-and-next-gates.md app/code/Weline/Server/doc/wls-panel-plan/92-local-appstore-sync-manifest.md app/code/Weline/Server/doc/wls-panel-plan/95-final-acceptance-runbook.md app/code/Weline/Server/doc/wls-panel-plan/96-requirement-traceability.md app/code/Weline/Server/doc/wls-panel-plan/tools/local-appstore-readiness-probe.php app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-completion-audit.php
```

Results:

- The manifest guard returned `ok=true`: one App site target, 31 allowed paths,
  31 include paths, 5 forbidden paths, no errors, and no warnings.
- Default completion audit returned `ok=true`, `complete=false`,
  `completion_matrix_total=14`, `completion_proven_rows=13`,
  `traceability_matrix_total=22`, and `traceability_proven_rows=20`.
- `--fail-on-incomplete=1` returned the expected non-zero gate while the local
  App Store API E2E remains incomplete.
- The local deploy-current fixture resolved to
  `https://app.weline.test:9523/api/v1/platform/module/list`.
- The production deploy-current fixture resolved to
  `https://app.aiweline.com/api/v1/platform/module/list`.
- `git diff --check` passed for the touched WLS Panel Plan files; Git only
  reported the existing LF-to-CRLF warning for `00-INDEX.md`.

## 2026-06-22 Deploy AppStore Endpoint Policy Gate

Scope:

- Added a read-only deploy `current.json` policy checker so production WLS
  marketplace tests cannot silently resolve to the local App Store or official
  website hosts.
- The checker validates required deployment keys and resolves local/production
  fixtures without token or network access.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-local-development.json --expect=local
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --expect=production
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
```

Results:

- PHP lint passed for
  `tools/validate-deploy-appstore-endpoint-policy.php`. The local PHP CLI still
  emits duplicate-extension warnings before normal output.
- `--self-test=1` returned `passed=true` across six in-memory cases with no
  file, network, token, WLS, or write side effects. The positive cases keep
  local development on `https://app.weline.test:9523` and production on
  `https://app.aiweline.com`; the negative cases prove production artifacts
  fail if `appstore_platform_url` is empty, resolves to
  `https://app.weline.test:9523`, resolves to `https://www.aiweline.com`, or
  carries a local deploy mode.
- The local deploy-current fixture returned `passed=true`, resolved
  `environment=local`, `deploy_mode=dev`, and endpoint
  `https://app.weline.test:9523/api/v1/platform/module/list`.
- The production deploy-current fixture returned `passed=true`, resolved
  `environment=production`, `deploy_mode=prod`, and endpoint
  `https://app.aiweline.com/api/v1/platform/module/list` from an explicitly
  recorded `appstore_platform_url=https://app.aiweline.com`.
- The checker confirmed required deploy metadata keys, rejected `www.*`
  marketplace hosts, verifies production does not resolve to `app.weline.test`,
  and rejects empty production `appstore_platform_url` values.
- The local AppStore sync manifest now returns `ok=true` with 34 allowed paths
  and 34 command include paths.
- The completion audit still returns `complete=false` because the authenticated
  local App Store typed-tag API E2E is not yet complete; this is the intended
  gate behavior.

## 2026-06-22 Typed Tag Runner Offline Self-Test Gate

Scope:

- Added `--self-test=1` to the credential-safe marketplace typed-tag runner so
  WLS Panel can prove tag parsing and exact matching before the live local
  AppStore route, token, or network is available.
- Extended the runner normalization to read locale-grouped `tags_resolved`
  responses such as `zh_Hans_CN => [{code: module:wls}]`, matching the response
  shapes documented in `20-plugin-tag-logic.md`.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-local-development.json --resolve-endpoint-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --resolve-endpoint-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php
```

Results:

- PHP lint passed for `tools/marketplace-typed-tag-e2e.php`; the local PHP CLI
  still emits duplicate-extension warnings before normal output.
- `--self-test=1` returned `passed=true` across six in-memory cases with no file
  read, network, token, WLS startup, or write side effects.
- Positive cases covered comma/semicolon string tags, JSON-string tag arrays,
  structured `type/value` tags, `marketplace_meta.tags`, `system:false`, and
  locale-grouped `tags_resolved`.
- Negative cases proved `module:wls-extra` does not satisfy `module:wls` and
  ordinary backend/server-tool tags do not pass the WLS module filter.
- Endpoint-only preflights still resolve local deploy metadata to
  `https://app.weline.test:9523/api/v1/platform/module/list` and production
  metadata to `https://app.aiweline.com/api/v1/platform/module/list`.
- The read-only local AppStore readiness probe still returned `ready=false`.
  Current blockers are the App checkout missing the sqlite composite-primary-key
  guard, `app.weline.test:9523` not listening, and no
  `WLS_MARKETPLACE_BEARER_TOKEN` environment value.
- This does not close the live AppStore E2E gate. The remaining proof still
  requires the local App checkout sync/startup, a local bearer token, and the
  generated `POST /api/v1/platform/module/list` route.

## 2026-06-22 Typed Tag Negative Canary Conclusive Gate

Scope:

- Added the optional live-runner flag `--require-negative-conclusive=1`.
- The final local and production AppStore typed-tag API E2E commands now require
  the negative `module:wls-extra` query to return a real canary item. An empty
  negative response is no longer accepted as final exact-match proof.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php --self-test=1
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
```

Results:

- PHP lint passed for the typed-tag runner and completion audit tool.
- `marketplace-typed-tag-e2e.php --self-test=1` returned `passed=true` and now
  includes eight in-memory cases. The two new cases prove the strict negative
  conclusive gate accepts a real negative canary and rejects an empty
  negative-query pass.
- `wls-panel-completion-audit.php` returned `ok=true`, `complete=false`, and
  now reports `typed_tag_negative_conclusive_gate=true`.
- The remaining live gate is intentionally still incomplete: after authorized
  App checkout sync/startup and local token setup, the real API run must use
  `--require-negative-conclusive=1`.

## 2026-06-23 Local AppStore Official Manifest Readiness Gate

Scope:

- Extended the read-only local AppStore readiness probe so it checks the real
  official catalog source for WLS marketplace typed-tag E2E readiness.
- Confirmed the environment split remains fixed: local development uses
  `https://app.weline.test:9523`, while deployed/production tests use
  deployment metadata that resolves to `https://app.aiweline.com`.

Observed local App checkout state:

```text
E:\WelineFramework\Framework-Official\App\weline\official-apps\manifest.json
```

Result:

- Validation commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php --template=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --expect=production
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
```

- The current local App checkout does not have a readable
  `official-apps/manifest.json`.
- `tools/validate-official-appstore-manifest-contract.php --self-test=1`
  returned `passed=true`, proving the offline manifest contract catches missing
  canary entries, canaries that also carry `module:wls`, substring-only
  `module:wls-extra` positives, WLS positives without `custom:wls-*`,
  non-installable WLS positives, and installable canaries.
- `tools/validate-official-appstore-manifest-contract.php --template=1`
  returned `passed=true` and emitted a `manifest_template` with current DEV
  entries for `Weline_PhpManager`, `Weline_DbManager`, `Weline_FileManager`,
  `Weline_Deploy`, and the non-installable `Weline_WlsTagCanary`. The emitted
  tag lists contain exact typed codes such as `module:wls`,
  `custom:wls-file-manager`, and `module:wls-extra`; structured tag fields like
  `type` or `primary` are no longer emitted as standalone tags.
- Running the same validator against the real App checkout returned the
  expected non-zero gate with `manifest_unreadable_or_invalid_json`.
- The local AppStore sync manifest guard returned `ok=true` with one App site
  target, `34` allowed paths, `34` include paths, `5` forbidden paths, no
  errors, and no warnings.
- The readiness probe returned `ready=false` with blockers:
  `app_schema_has_sqlite_composite_pk_guard`, `app_port_listening`,
  `official_manifest_readable`, `official_manifest_has_wls_positive`,
  `official_manifest_has_negative_canary`,
  `official_manifest_negative_canary_exact`, and
  `bearer_token_env_present`.
- The production deploy fixture still passed the endpoint policy check and
  resolved to `https://app.aiweline.com/api/v1/platform/module/list` without
  token, network, WLS startup, or repository writes.
- The completion audit returned `ok=true`, `complete=false`; the remaining
  incomplete rows now name the official manifest preparation as part of the
  local App Store API E2E gate.
- Because the manifest is missing, the live typed-tag E2E still lacks a real
  `module:wls` positive marketplace source and a real `module:wls-extra`
  negative canary source.
- The negative canary remains an exact-match test fixture: it must be returned
  by `tag=module:wls-extra`, but must not be returned by `tag=module:wls`.
- No files under `E:\WelineFramework\Framework-Official\App\weline` were
  modified during this check.

Residual gate:

- Prepare the App Store official manifest through an authorized App checkout
  change, then rerun the readiness probe, start App WLS on
  `app.weline.test:9523`, and run the token-safe typed-tag API E2E with
  `--require-negative-conclusive=1`.

## 2026-06-23 Production AppStore Endpoint Solidification

Scope:

- Locked the production deployment fixture and deploy endpoint policy gate so
  deployed tests use `https://app.aiweline.com` from deployment metadata, not
  from a remembered URL or an empty `appstore_platform_url` fallback.
- Kept runtime fallback to `https://app.aiweline.com` only as a no-artifact
  safety path; production deployment proof now requires
  `appstore_platform_url=https://app.aiweline.com` in `current.json`.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-local-development.json --expect=local
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --expect=production
php app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-local-development.json --resolve-endpoint-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --resolve-endpoint-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php --fail-on-incomplete=1
git diff --check -- app/code/Weline/Server/doc/wls-panel-plan/00-INDEX.md app/code/Weline/Server/doc/wls-panel-plan/30-atomic-task-plan.md app/code/Weline/Server/doc/wls-panel-plan/77-current-integrated-verification-evidence.md app/code/Weline/Server/doc/wls-panel-plan/90-completion-audit-and-next-gates.md app/code/Weline/Server/doc/wls-panel-plan/92-local-appstore-sync-manifest.md app/code/Weline/Server/doc/wls-panel-plan/95-final-acceptance-runbook.md app/code/Weline/Server/doc/wls-panel-plan/96-requirement-traceability.md app/code/Weline/Server/doc/wls-panel-plan/tools/deploy-current-production-default.json app/code/Weline/Server/doc/wls-panel-plan/tools/validate-deploy-appstore-endpoint-policy.php
```

Results:

- PHP lint passed for the endpoint policy checker. The local PHP CLI still
  emits duplicate-extension and OPcache warnings before normal output.
- `--self-test=1` returned `passed=true` across six cases. The new negative
  case proves a production `current.json` with empty `appstore_platform_url`
  fails through `production_records_app_aiweline_url`.
- The production fixture now records
  `appstore_platform_url=https://app.aiweline.com` and passes with no warnings.
- Endpoint-only runner checks resolve local metadata to
  `https://app.weline.test:9523/api/v1/platform/module/list` and production
  metadata to `https://app.aiweline.com/api/v1/platform/module/list` without
  token, network, WLS startup, or repository writes.
- The local AppStore sync manifest guard still reports `ok=true`, one App site
  target, `34` allowed paths, `34` include paths, and no warnings.
- The completion audit remains `complete=false`, and
  `--fail-on-incomplete=1` exits non-zero as intended because the live local
  AppStore typed-tag API E2E is still blocked by the App checkout manifest,
  listener, sqlite guard sync, and bearer-token prerequisites.
- No files under `E:\WelineFramework\Framework-Official\App\weline` were
  modified during this endpoint solidification.

## 2026-06-23 Official Manifest Materialize Guard

Scope:

- Extended the official AppStore manifest validator so the existing
  `--template=1` mode can also prepare an exact target write plan for
  `official-apps/manifest.json`.
- The default path remains read-only. Write mode requires
  `--write=1 --confirm=WRITE_WLS_OFFICIAL_MANIFEST`, an absolute target ending
  in `manifest.json`, and `--create-dir=1` when the directory is missing.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php --template=1 --template-target=E:\WelineFramework\Framework-Official\App\weline\official-apps\manifest.json
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php --template=1 --template-target=E:\WelineFramework\Framework-Official\App\weline\official-apps\manifest.json --write=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php --self-test=1
```

Temporary write-path validation:

```powershell
$dir = Join-Path $env:TEMP ('wls-manifest-materialize-' + [guid]::NewGuid().ToString('N'))
$target = Join-Path $dir 'manifest.json'
try {
    php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php --template=1 --template-target=$target --write=1 --confirm=WRITE_WLS_OFFICIAL_MANIFEST --create-dir=1
    php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php --manifest=$target --strict-source=0
} finally {
    if (Test-Path -LiteralPath $dir) {
        Remove-Item -LiteralPath $dir -Recurse -Force
    }
}
```

Results:

- PHP lint passed for the manifest validator. The local PHP CLI still emits
  duplicate-extension and OPcache warnings before normal output.
- Dry-run target mode returned `passed=true`,
  `materialize.write_requested=false`, `materialize.wrote=false`, and
  `materialize.would_write=true` for the App checkout manifest path.
- Write mode without confirmation returned non-zero with
  `write_confirm_phrase_required` and did not write the App checkout.
- Temporary write mode wrote only the generated `manifest.json` into a unique
  `%TEMP%` directory, then the same validator passed against that file with
  `has_wls_positive=true`, `has_negative_canary=true`, and
  `negative_canary_exact=true`; the temp directory was removed afterward.
- The App checkout state was not modified by this check. Its real
  `official-apps/manifest.json` gate remains open until an authorized App-side
  catalog preparation writes the manifest and the local App WLS API E2E runs.

## 2026-06-23 Local AppStore Readiness Materialize Plan

Scope:

- Extended the read-only local AppStore readiness probe so it now reports the
  official manifest materialize plan next to the existing App checkout blockers.
- The probe still does not write the App checkout, start WLS, run setup, sync
  files, or print secrets.
- Added a completion-audit text check so the plan cannot drop the
  `official_manifest_materialize` dry-run/authorized command contract.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php --fail-on-incomplete=1
```

Results:

- PHP lint passed for the readiness probe and completion audit tool. The local
  PHP CLI still emits duplicate-extension and OPcache warnings before normal
  output.
- The readiness probe still returned the expected `ready=false`, with blockers:
  `app_schema_has_sqlite_composite_pk_guard`, `app_port_listening`,
  `official_manifest_readable`, `official_manifest_has_wls_positive`,
  `official_manifest_has_negative_canary`,
  `official_manifest_negative_canary_exact`, and
  `bearer_token_env_present`.
- The same probe now reports
  `official_manifest_validator_available=true`,
  `official_manifest_materialize_target=true`,
  `official_manifest_materialize_dry_run_available=true`, and
  `official_manifest_materialize.dry_run_available=true`.
- The emitted dry-run command targets only
  `E:\WelineFramework\Framework-Official\App\weline\official-apps\manifest.json`.
  The emitted write command adds
  `--write=1 --create-dir=1 --confirm=WRITE_WLS_OFFICIAL_MANIFEST`.
- The local AppStore sync manifest guard now returns `ok=true` with one App
  site target, `35` allowed paths, `35` include paths, five forbidden paths,
  and no warnings. The new allowed path is
  `tools/wls-panel-completion-audit.php`, keeping the local sync plan and audit
  gate in the same evidence set.
- The completion audit returns `ok=true`, `complete=false`, and
  `local_readiness_materialize_plan=true`; `--fail-on-incomplete=1` still exits
  non-zero as intended because the live local AppStore typed-tag API E2E remains
  open.

Residual gate:

- After explicit App checkout authorization, sync the scoped DEV fixes, run the
  authorized official manifest write, validate the real manifest, start
  `app.weline.test:9523`, set a local bearer token outside repository files,
  and run the live typed-tag API E2E with the strict `module:wls-extra`
  negative canary.

## 2026-06-23 Local AppStore Sync Drift Snapshot

Scope:

- Added `--with-drift=1` to the local AppStore sync manifest validator.
- The default manifest check remains unchanged. The new mode hashes only the
  allowed sync paths from `92-local-appstore-sync-manifest.md` under the DEV
  workspace and `E:\WelineFramework\Framework-Official\App\weline`.
- Drift is reported as operational evidence before authorization; it does not
  run `分项`, start WLS, execute setup, read credentials, or write either
  checkout.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php --with-drift=1
```

Results:

- PHP lint passed for `tools/validate-local-appstore-sync-manifest.php`. The
  local PHP CLI still emits duplicate-extension and OPcache warnings before
  normal output.
- The manifest guard returned `ok=true`, one App site target, `35` allowed
  paths, `35` include paths, five forbidden paths, no errors, and one expected
  warning: `app_checkout_drift_detected:35`.
- The drift report compared all 35 allowed paths and found `same=0`,
  `different=19`, `missing_app=16`, `missing_dev=0`, and `missing_both=0`.
- The App-side missing paths include the new resolver test, WLS Panel Plan
  evidence/runbook/traceability files, deploy endpoint fixtures, readiness
  probe, typed-tag runner, deploy endpoint policy checker, official manifest
  validator, and completion audit tool.
- The App-side different paths include the sqlite schema migration executor,
  AppStore endpoint resolver callers/templates/i18n, Deploy current.json
  metadata writers/templates/i18n, and WLS Panel endpoint-display controller
  and template.

Residual gate:

- This quantifies the later authorized `分项` scope. It is not authorization to
  sync. The live AppStore API E2E remains gated on an approved App checkout
  update, official manifest preparation, `app.weline.test:9523` startup, and a
  local bearer token outside repository files.

## 2026-06-23 Post-Sync Drift Failure Gate

Scope:

- Added `--fail-on-drift=1` to
  `tools/validate-local-appstore-sync-manifest.php`.
- The new mode is still read-only. It reuses the same allowed-path hash
  comparison as `--with-drift=1`, but converts any residual drift into a
  non-zero gate.
- This mode is for after an authorized scoped `分项` sync. It is expected to
  fail before sync because the App checkout has not received the DEV changes.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php --with-drift=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php --fail-on-drift=1
```

Results:

- PHP lint passed for the manifest guard. The local PHP CLI still emits
  duplicate-extension and OPcache warnings before normal output.
- Default mode returned `ok=true` with `35` allowed paths, `35` include paths,
  five forbidden paths, and no warnings.
- `--with-drift=1` remained report-only and returned `ok=true` plus
  `app_checkout_drift_detected:35`; the drift counts remained `same=0`,
  `different=19`, `missing_app=16`, `missing_dev=0`, and `missing_both=0`.
- `--fail-on-drift=1` returned the expected non-zero status before sync with
  `ok=false`, `fail_on_drift=true`, `gate_mode=fail-on-drift`, and
  `errors=["app_checkout_drift_detected:35"]`.

Residual gate:

- After the authorized scoped `分项` sync, rerun
  `validate-local-appstore-sync-manifest.php --fail-on-drift=1`. It must return
  `ok=true` before App setup, local App WLS startup, or the live typed-tag API
  E2E can be counted as final marketplace proof.

## 2026-06-23 Drift Summary Output Gate

Scope:

- Added compact drift output for the local AppStore sync manifest validator.
- `--drift-summary-only=1` is read-only and can be combined with either
  `--with-drift=1` or `--fail-on-drift=1`.
- The mode omits per-file rows, reports `rows_omitted`, and preserves the same
  report-only or fail-on-drift gate semantics.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php --with-drift=1 --drift-summary-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php --fail-on-drift=1 --drift-summary-only=1
```

Results:

- PHP lint passed for `tools/validate-local-appstore-sync-manifest.php`. The
  local PHP CLI still emits duplicate-extension and OPcache warnings before
  normal output.
- `--with-drift=1 --drift-summary-only=1` returned `ok=true`, kept the expected
  warning `app_checkout_drift_detected:35`, reported `summary_only=true`,
  `rows_omitted=35`, `drifted_count=35`, and `gate_mode=report-only`.
- The compact drift counts remained `same=0`, `different=19`,
  `missing_app=16`, `missing_dev=0`, and `missing_both=0`.
- `--fail-on-drift=1 --drift-summary-only=1` returned the expected non-zero
  status before sync with `ok=false`, `fail_on_drift=true`,
  `gate_mode=fail-on-drift`, and `errors=["app_checkout_drift_detected:35"]`.

Residual gate:

- Compact output is log hygiene only. After the authorized scoped sync, the
  fail-on-drift command must still return `ok=true` before App setup, local App
  WLS startup on `app.weline.test:9523`, or live typed-tag API E2E can count as
  final marketplace proof.

## 2026-06-23 Local AppStore Readiness Next Actions

Scope:

- Extended the read-only local AppStore readiness probe with a `next_actions`
  section.
- The new section maps current blockers to ordered action ids, phases,
  authorization requirements, `safe_to_run_now`, working directories, commands,
  and side-effect boundaries.
- The probe still does not run sync, setup, WLS start, token writes, live API
  requests, or repository writes.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php
```

Results:

- PHP lint passed for `tools/local-appstore-readiness-probe.php`. The local PHP
  CLI still emits duplicate-extension and OPcache warnings before normal
  output.
- The readiness probe still returned `ok=true`, `ready=false`, and the current
  blockers remain `app_schema_has_sqlite_composite_pk_guard`,
  `app_port_listening`, `official_manifest_readable`,
  `official_manifest_has_wls_positive`,
  `official_manifest_has_negative_canary`,
  `official_manifest_negative_canary_exact`, and
  `bearer_token_env_present`.
- `next_actions` now reports five ordered action records:
  `authorized_app_checkout_sync`, `prepare_official_manifest`,
  `start_local_app_wls`, `set_local_marketplace_bearer_token`, and
  `run_live_typed_tag_e2e`.
- The sync and official manifest actions are marked
  `requires_user_authorization=true` and `safe_to_run_now=false`.
- `start_local_app_wls` points to working directory
  `E:\WelineFramework\Framework-Official\App\weline` and remains
  `safe_to_run_now=false` until sync, setup, and manifest gates pass.
- The bearer-token action says to set `WLS_MARKETPLACE_BEARER_TOKEN` outside
  repository files; the token value remains redacted or not set.
- The final `run_live_typed_tag_e2e` command is present but
  `safe_to_run_now=false` until all readiness blockers clear.

Residual gate:

- This makes the remaining AppStore gate executable and auditable, but it does
  not authorize App checkout sync or manifest writes. The live typed-tag API E2E
  still waits for explicit App sync authorization, official manifest
  preparation, local App WLS startup on `app.weline.test:9523`, and a local
  bearer token outside repository files.

## 2026-06-23 Readiness Action Plan Compact Output

Scope:

- Added `--action-plan-only=1` to the read-only local AppStore readiness probe.
- The compact mode keeps the same readiness exit code but emits only the
  endpoint, blockers, `next_actions`, and redacted notes.
- It omits the full `checks`, `official_manifest`, and
  `official_manifest_materialize` payloads for repeated operator or CI review.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php --action-plan-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php
```

Results:

- PHP lint passed for `tools/local-appstore-readiness-probe.php`. The local PHP
  CLI still emits duplicate-extension and OPcache warnings before normal
  output.
- The compact mode returned the expected non-zero readiness exit before App
  sync, with `mode=action-plan-only`, `ready=false`, the same seven blockers,
  and the same five `next_actions`.
- Compact output included `run_live_typed_tag_e2e` but kept it
  `safe_to_run_now=false`.
- Compact output omitted full `checks`, `official_manifest`, and
  `official_manifest_materialize` sections.
- Full output mode still includes `checks`, `official_manifest_materialize`, and
  `next_actions`.

Residual gate:

- Compact output is an operator view only. It does not authorize App checkout
  sync, official manifest writes, local WLS startup, token storage, or live API
  calls.

## 2026-06-23 Final Marketplace Preflight Aggregate

Scope:

- Added `tools/wls-panel-final-preflight.php` as a read-only aggregate for the
  remaining marketplace gate.
- The aggregate runs existing read-only helpers: completion audit, local
  readiness action plan, App sync drift, deploy endpoint policy self-test,
  typed-tag self-test, official manifest self-test, official manifest
  template dry-run, and production endpoint-only resolution.
- It reports `ready_for_live_local_appstore_e2e` separately from
  `goal_complete`, so the final live API E2E is not confused with the whole
  WLS Panel goal completion audit.
- It does not sync files, run setup, start WLS, write manifests, read token
  values, or call the live AppStore API.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php --template=1 --template-target=E:\WelineFramework\Framework-Official\App\weline\official-apps\manifest.json
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php --fail-on-incomplete=1
```

Results:

- PHP lint passed for `tools/wls-panel-final-preflight.php`. The local PHP CLI
  still emits duplicate-extension and OPcache warnings before normal output.
- PHP lint passed for `tools/wls-panel-completion-audit.php`.
- The official manifest contract self-test returned `passed=true`.
- The official manifest template dry-run returned `passed=true`, target
  `E:\WelineFramework\Framework-Official\App\weline\official-apps\manifest.json`,
  `materialize.would_write=true`, and `materialize.wrote=false`.
- Default preflight returned the expected non-zero exit because
  `ready_for_live_local_appstore_e2e=false` and `goal_complete=false`.
- `--report-only=1` returned exit `0` while preserving
  `ready_for_live_local_appstore_e2e=false`.
- The aggregate parsed all nested tool outputs despite PHP startup warnings.
- Current summary: `completion_open_rows=3`, `drifted_count=36`,
  production endpoint `https://app.aiweline.com/api/v1/platform/module/list`,
  local readiness blockers unchanged, deploy endpoint policy passed, and
  typed-tag self-test passed.
- New aggregate manifest checks are green:
  `official_manifest_self_test_passed=true`,
  `official_manifest_template_dry_run_passed=true`, and
  `official_manifest_template_would_write=true`.
- Completion audit still reports `complete=false`; with
  `--fail-on-incomplete=1` it exits non-zero as expected while the local
  AppStore typed-tag API E2E gate is still open.

Residual gate:

- Final preflight remains red until the approved App checkout sync removes
  allowed-path drift, the official manifest exists and validates, local App WLS
  listens on `app.weline.test:9523`, and a local bearer token is provided
  outside repository files.

## 2026-06-23 Final Marketplace Preflight Endpoint And Source Catalog Lock

Scope:

- Extended the final marketplace preflight so it no longer relies only on the
  production default resolver.
- The aggregate now validates both deployment fixtures directly:
  `deploy-current-local-development.json` must pass the local deploy endpoint
  policy and resolve to `https://app.weline.test:9523/api/v1/platform/module/list`;
  `deploy-current-production-default.json` must pass the production deploy
  endpoint policy and resolve to
  `https://app.aiweline.com/api/v1/platform/module/list`.
- The aggregate also checks the official source catalog dry-run through
  `source_plan`, proving the future App checkout write would target only
  `official-apps/modules/*`.
- No App checkout writes, WLS startup, token reads, or live API calls happened.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php --template=1 --template-target=E:\WelineFramework\Framework-Official\App\weline\official-apps\manifest.json
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-local-development.json --expect=local
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --expect=production
php app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-local-development.json --resolve-endpoint-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --resolve-endpoint-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php --action-plan-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
```

Results:

- PHP lint passed for the final preflight and completion audit tools. The local
  PHP CLI still emits duplicate-extension and OPcache warnings before normal
  output.
- The official manifest template dry-run returned `passed=true`,
  `materialize.would_write=true`, `source_plan.ready=true`,
  `source_plan.would_write=true`, and `source_plan.wrote=false`.
- The source plan contains real DEV source mappings for `Weline_PhpManager`,
  `Weline_DbManager`, `Weline_FileManager`, and `Weline_Deploy`, plus generated
  canary source `Weline_WlsTagCanary`; all target paths are under
  `E:\WelineFramework\Framework-Official\App\weline\official-apps\modules`.
- The local deploy endpoint policy fixture returned `passed=true` and resolved
  to `https://app.weline.test:9523/api/v1/platform/module/list`.
- The production deploy endpoint policy fixture returned `passed=true` and
  resolved to `https://app.aiweline.com/api/v1/platform/module/list`.
- Endpoint-only runner checks returned `passed=true` for both fixtures without
  token, network, WLS startup, or repository writes.
- Final preflight `--report-only=1` returned exit `0` with
  `ready_for_live_local_appstore_e2e=false`, `goal_complete=false`,
  `official_manifest_source_plan_ready=true`,
  `official_manifest_source_plan_would_write=true`,
  `local_deploy_endpoint_policy_passed=true`,
  `production_deploy_endpoint_policy_passed=true`,
  `local_endpoint_locked=true`, and `production_endpoint_locked=true`.
- The remaining readiness blockers are unchanged:
  `app_schema_has_sqlite_composite_pk_guard`, `app_port_listening`,
  `official_manifest_readable`, `official_manifest_has_wls_positive`,
  `official_manifest_has_negative_canary`,
  `official_manifest_negative_canary_exact`, and
  `bearer_token_env_present`.
- Completion audit returned `ok=true`, `complete=false`, with only the local
  AppStore typed-tag API gate still incomplete.

## 2026-06-23 AppStore Endpoint Source Contract Lock

Scope:

- Added `tools/validate-appstore-endpoint-source-contract.php` as a read-only
  static source gate for the marketplace endpoint policy.
- The gate verifies that `DeployOrchestratorService` writes
  `deploy_mode_source`, `appstore_environment`, `appstore_platform_url`, and
  `appstore_platform_url_source` into release and rollback
  `var/deploy/current.json` payloads.
- The gate verifies that `AppStorePlatformUrlResolver` uses explicit
  `deploy=dev/local` for local App Store, reads production
  `var/deploy/current.json` for deployed checks, and falls back to
  `https://app.aiweline.com` only when no production deployment artifact is
  available.
- The gate verifies that `AccountBindService` and WLS Panel consume the shared
  resolver, and that the checked source files do not contain `www.aiweline.com`
  or `www.weline.test:9518` as marketplace hosts.
- The final marketplace preflight now includes
  `endpoint_source_contract_passed`.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-endpoint-source-contract.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-endpoint-source-contract.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php --fail-on-incomplete=1
```

Results:

- PHP lint passed for the source contract checker, final preflight, and
  completion audit tools. The local PHP CLI still emits duplicate-extension and
  OPcache warnings before normal output.
- The source contract checker returned `passed=true` with no errors.
- Green checks included `deploy_has_local_appstore_constant`,
  `deploy_has_production_appstore_constant`,
  `deploy_writes_release_current_metadata`,
  `resolver_reads_deployed_production_current`,
  `account_bind_uses_resolver`, `panel_exposes_resolver_state`, and
  `source_has_no_www_marketplace_host`.
- The locked contract remains local development
  `https://app.weline.test:9523`, deployed production
  `https://app.aiweline.com`, and deployment artifact
  `var/deploy/current.json`.
- Final preflight `--report-only=1` returned exit `0` and reported
  `endpoint_source_contract_passed=true`, `local_endpoint_locked=true`,
  `production_endpoint_locked=true`, local endpoint
  `https://app.weline.test:9523/api/v1/platform/module/list`, and production
  endpoint `https://app.aiweline.com/api/v1/platform/module/list`.
- Completion audit returned `ok=true`, `complete=false`, and
  `endpoint_source_contract_gate=true`; `--fail-on-incomplete=1` still exits
  non-zero as expected while the local AppStore live typed-tag API gate is open.

Residual gate:

- This closes the source-contract drift risk for the endpoint policy. It does
  not replace the remaining App checkout sync, App WLS startup on
  `app.weline.test:9523`, official catalog preparation, token readiness, or
  live typed-tag API E2E gates.

## 2026-06-23 Local AppStore Typed-Tag Live Gate Wrapper

Scope:

- Added `tools/local-appstore-typed-tag-live-gate.php` as the guarded entry
  point for the final local AppStore live typed-tag API proof.
- Default and report modes are preflight-only. They run readiness, source
  contract, local deploy policy, and local endpoint-only checks, then stop
  without calling the AppStore API.
- Live execution requires `--allow-live=1` and `ready_for_live=true`; if any
  readiness blocker remains, the wrapper reports `status=blocked` and keeps
  `live_executed=false`.
- Final preflight now includes `local_live_gate_guard_passed` and
  `local_live_gate_no_live_call` so the aggregate gate proves the local live
  runner cannot be accidentally executed from a half-prepared App checkout.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-typed-tag-live-gate.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-typed-tag-live-gate.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-typed-tag-live-gate.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php --fail-on-incomplete=1
```

Results:

- PHP lint passed for the new live-gate wrapper, final preflight, and
  completion audit tools. The local PHP CLI still emits duplicate-extension and
  OPcache warnings before normal output.
- `local-appstore-typed-tag-live-gate.php --report-only=1` returned exit `0`
  with `status=blocked`, `guard_passed=true`, `ready_for_live=false`,
  `live_executed=false`, local endpoint
  `https://app.weline.test:9523/api/v1/platform/module/list`, and no source or
  local deploy policy errors.
- Running the same wrapper without `--report-only=1` returned the expected
  non-zero exit while preserving `live_executed=false`; it did not call the
  AppStore API.
- Final preflight `--report-only=1` returned exit `0` with
  `local_live_gate_guard_passed=true`, `local_live_gate_no_live_call=true`,
  `local_live_gate_status=blocked`, and
  `local_live_gate_ready_for_live=false`.
- Completion audit returned `ok=true`, `complete=false`, and
  `local_live_gate_wrapper=true`; `--fail-on-incomplete=1` still exits non-zero
  as expected while the local AppStore API gate remains open.

Residual gate:

- The wrapper is now the preferred live proof entry point, but it correctly
  blocks until the approved App checkout sync, official catalog preparation,
  App WLS startup on `app.weline.test:9523`, and bearer-token readiness are all
  complete.

## 2026-06-23 Production AppStore Typed-Tag Live Gate Wrapper

Scope:

- Added `tools/production-appstore-typed-tag-live-gate.php` as the guarded
  entry point for deployed/production AppStore typed-tag API proof.
- Default and report modes are preflight-only. They run the AppStore endpoint
  source contract, production deploy endpoint policy, and production
  endpoint-only resolver, then stop without calling the AppStore API.
- Production endpoint selection must come from `var/deploy/current.json`; the
  wrapper rejects manual `--endpoint` input and insecure mode.
- Live execution requires `--allow-live=1`, deployed
  `appstore_platform_url=https://app.aiweline.com`, and token readiness through
  `WLS_MARKETPLACE_BEARER_TOKEN` or `--token-file`. Token values are never
  printed.
- Final preflight now includes `production_live_gate_guard_passed` and
  `production_live_gate_no_live_call` so deployed checks cannot accidentally
  hit the production AppStore before the deployment artifact and token are
  ready.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\production-appstore-typed-tag-live-gate.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\production-appstore-typed-tag-live-gate.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\production-appstore-typed-tag-live-gate.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json
php app\code\Weline\Server\doc\wls-panel-plan\tools\production-appstore-typed-tag-live-gate.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --endpoint=https://app.aiweline.com/api/v1/platform/module/list --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\production-appstore-typed-tag-live-gate.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --insecure=1 --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php --fail-on-incomplete=1
```

Results:

- PHP lint passed for the production live-gate wrapper, final preflight, and
  completion audit tools. The local PHP CLI still emits duplicate-extension
  and OPcache warnings before normal output.
- `production-appstore-typed-tag-live-gate.php --report-only=1` returned exit
  `0` with `status=blocked`, `guard_passed=true`, `ready_for_live=false`,
  `live_executed=false`, production endpoint
  `https://app.aiweline.com/api/v1/platform/module/list`,
  `token_ready=false`, and no source or production deploy policy errors.
- Running the same wrapper without `--report-only=1` returned the expected
  non-zero exit while preserving `live_executed=false`; it did not call the
  AppStore API.
- The manual endpoint guard returned `guard_passed=false` with
  `manual_endpoint_rejected=false`, proving production launch evidence cannot
  be based on a hand-entered URL.
- The insecure guard returned `guard_passed=false` with
  `production_insecure_disabled=false`, proving production launch evidence must
  keep HTTPS verification enabled.
- Final preflight `--report-only=1` returned exit `0` with
  `production_live_gate_guard_passed=true`,
  `production_live_gate_no_live_call=true`,
  `production_live_gate_status=blocked`,
  `production_live_gate_ready_for_live=false`,
  `production_endpoint_locked=true`, and production endpoint
  `https://app.aiweline.com/api/v1/platform/module/list`.
- Completion audit returned `ok=true`, `complete=false`, and
  `production_live_gate_wrapper=true`; `--fail-on-incomplete=1` still exits
  non-zero as expected while the local AppStore API gate remains open.

Residual gate:

- Production launch proof still requires the real deployed
  `var/deploy/current.json`, external production token/account, and
  `app.aiweline.com` live API readiness. Until then the production wrapper
  intentionally blocks with no network call, no token value output, no WLS
  startup, and no repository writes.

## 2026-06-23 Local AppStore Readiness Action Uses Live-Gate Wrapper

Scope:

- Updated `tools/local-appstore-readiness-probe.php` so the
  `run_live_typed_tag_e2e` next action points to the guarded
  `tools/local-appstore-typed-tag-live-gate.php --allow-live=1` wrapper.
- Updated `92-local-appstore-sync-manifest.md` so the copied local live API
  command also uses the wrapper instead of directly invoking the underlying
  typed-tag runner with a manual endpoint.
- Tightened `tools/wls-panel-completion-audit.php` so the long-term documents
  must mention the wrapper command in the readiness action path.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php --action-plan-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php --fail-on-incomplete=1
```

Results:

- PHP lint passed for the readiness probe and completion audit tools. The
  local PHP CLI still emits duplicate-extension and OPcache warnings before
  normal output.
- `local-appstore-readiness-probe.php --action-plan-only=1` still returned the
  expected non-zero readiness exit while blockers remain, but
  `run_live_typed_tag_e2e.command` now equals
  `php app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-typed-tag-live-gate.php --allow-live=1`.
- The same next action remains `safe_to_run_now=false` while App checkout sync,
  App WLS startup, official manifest/source catalog, and token readiness
  blockers remain.
- Final preflight `--report-only=1` returned exit `0` and carried the wrapper
  command through `summary.readiness_next_actions`.
- Completion audit returned `ok=true`, `complete=false`, and
  `local_readiness_next_actions=true`; `--fail-on-incomplete=1` still exits
  non-zero as expected while the local AppStore live API proof is open.

Residual gate:

- This closes the operator-copy/paste bypass risk in the local readiness
  action plan. It does not run sync, setup, WLS startup, token reads, or live
  API calls.

## 2026-06-23 Premature Allow-Live Guard In Final Preflight

Scope:

- Updated `tools/wls-panel-final-preflight.php` so the aggregate preflight now
  probes both local and production live-gate wrappers with
  `--allow-live=1 --report-only=1` while readiness is still false.
- Added two required checks:
  `local_live_gate_premature_allow_blocked_no_live_call` and
  `production_live_gate_premature_allow_blocked_no_live_call`.
- Updated `tools/wls-panel-completion-audit.php`,
  `90-completion-audit-and-next-gates.md`,
  `95-final-acceptance-runbook.md`, and
  `96-requirement-traceability.md` so the new guard is part of the documented
  acceptance contract.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
```

Results:

- PHP lint passed for final preflight and completion audit. The local PHP CLI
  still emits duplicate-extension and OPcache warnings before normal output.
- Final preflight `--report-only=1` returned exit `0` with
  `local_live_gate_premature_allow_blocked_no_live_call=true` and
  `production_live_gate_premature_allow_blocked_no_live_call=true`.
- The premature local probe reported `status=blocked`, `live_allowed=true`,
  `ready_for_live=false`, and `live_executed=false`.
- The premature production probe reported `status=blocked`, `live_allowed=true`,
  `ready_for_live=false`, and `live_executed=false`.
- Completion audit returned `ok=true`, `complete=false`, and
  `final_preflight_gate=true`, proving the new guard text is present in the
  long-term plan.

Residual gate:

- This proves the wrappers still refuse live API execution when `--allow-live=1`
  is supplied too early. It does not replace the remaining App checkout sync,
  manifest/source catalog preparation, App WLS startup, token readiness, or
  final live API proof.

## 2026-06-23 App Setup Action In Local Readiness Plan

Scope:

- Updated `tools/local-appstore-readiness-probe.php` so the local AppStore
  readiness plan now includes `run_local_app_setup_after_sync` after the
  authorized scoped App checkout sync and before App WLS startup.
- The setup action uses the local App checkout working directory
  `E:\WelineFramework\Framework-Official\App\weline` and command
  `php bin/w setup:upgrade --route --skip-env-check --skip-composer-dump`.
- Updated `tools/wls-panel-completion-audit.php`,
  `90-completion-audit-and-next-gates.md`,
  `95-final-acceptance-runbook.md`, and
  `96-requirement-traceability.md` so this setup action is required by the
  documented readiness and completion gates.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php --action-plan-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
```

Results:

- PHP lint passed for the readiness probe and completion audit tools. The
  local PHP CLI still emits duplicate-extension and OPcache warnings before
  normal output.
- `local-appstore-readiness-probe.php --action-plan-only=1` still returned the
  expected non-zero readiness exit while blockers remain, but now includes
  `run_local_app_setup_after_sync`.
- The setup action is `safe_to_run_now=false`, runs from
  `E:\WelineFramework\Framework-Official\App\weline`, and is explicitly gated
  by the precondition `run only after the scoped App checkout sync and
  post-sync drift gate both pass`.
- Final preflight `--report-only=1` returned exit `0` and carried
  `run_local_app_setup_after_sync` through `summary.readiness_next_actions`.
- Completion audit returned `ok=true`, `complete=false`, and
  `local_readiness_next_actions=true`.

Residual gate:

- This closes the missing post-sync setup step in the local AppStore readiness
  plan. It does not run App checkout sync, App setup, WLS startup, token reads,
  or live API calls.

## 2026-06-23 Exact Platform Root Deploy Endpoint Policy

Scope:

- Tightened `tools/validate-deploy-appstore-endpoint-policy.php` so
  `appstore_platform_url` is validated as the raw platform-root field, not just
  a normalizable endpoint source.
- Added `raw_platform_url` to policy output and required
  `production_records_exact_app_aiweline_platform_url` for production
  `current.json`.
- Added negative self-test cases for production and local deployment artifacts
  that incorrectly store `/api/v1/platform/module/list` inside
  `appstore_platform_url`.
- Updated `90-completion-audit-and-next-gates.md`,
  `92-local-appstore-sync-manifest.md`,
  `95-final-acceptance-runbook.md`, and
  `96-requirement-traceability.md` so the runbook and traceability contract
  require the exact platform-root value.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --expect=production
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-local-development.json --expect=local
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php --fail-on-incomplete=1
```

Results:

- PHP lint passed for the deploy endpoint policy checker and completion audit
  tool. The local PHP CLI still emits duplicate-extension and OPcache warnings
  before normal output.
- `validate-deploy-appstore-endpoint-policy.php --self-test=1` returned
  `passed=true`.
- The new `production_rejects_api_endpoint_in_platform_url` self-test returned
  `actual_passed=false`, with
  `check_failed:production_records_exact_app_aiweline_platform_url` and
  `platform_url_contains_api_path`.
- The new `local_rejects_api_endpoint_in_platform_url` self-test returned
  `actual_passed=false`, with
  `check_failed:local_records_exact_app_weline_platform_url` and
  `platform_url_contains_api_path`.
- The production fixture still passed with
  `raw_platform_url=https://app.aiweline.com`,
  `production_records_exact_app_aiweline_platform_url=true`, and resolved
  endpoint `https://app.aiweline.com/api/v1/platform/module/list`.
- The local fixture still passed with
  `raw_platform_url=https://app.weline.test:9523`,
  `local_records_exact_app_weline_platform_url=true`, and resolved endpoint
  `https://app.weline.test:9523/api/v1/platform/module/list`.
- Final preflight `--report-only=1` returned exit `0` with
  `deploy_endpoint_policy_passed=true`,
  `local_deploy_endpoint_policy_passed=true`, and
  `production_deploy_endpoint_policy_passed=true`; the live E2E gate remains
  not ready.
- Completion audit returned `ok=true`, `complete=false`, and
  `deploy_endpoint_policy_gate=true`; `--fail-on-incomplete=1` still exits
  non-zero because the local AppStore live API proof is open.

Residual gate:

- This closes the deployment-info shape risk for AppStore endpoint selection.
  It does not run App checkout sync, App setup, WLS startup, token reads, or
  live API calls.

## 2026-06-23 Exact Root Checks In Final Preflight

Scope:

- Promoted the deploy endpoint policy exact-root checks into
  `tools/wls-panel-final-preflight.php`.
- Added `local_deploy_endpoint_policy_exact_root` and
  `production_deploy_endpoint_policy_exact_root` to the aggregate readiness
  decision before the local AppStore typed-tag live E2E can become runnable.
- Added `raw_platform_url` and `exact_root` fields to the local and production
  deploy endpoint policy summaries in the final preflight payload.
- Updated `tools/wls-panel-completion-audit.php`,
  `90-completion-audit-and-next-gates.md`,
  `95-final-acceptance-runbook.md`, and
  `96-requirement-traceability.md` so the exact-root checks remain part of the
  documented final gate.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
```

Results:

- PHP lint passed for final preflight and completion audit. The local PHP CLI
  still emits duplicate-extension and OPcache warnings before normal output.
- Final preflight `--report-only=1` returned exit `0` with
  `local_deploy_endpoint_policy_exact_root=true` and
  `production_deploy_endpoint_policy_exact_root=true`.
- Final preflight now reports
  `local_deploy_platform_url=https://app.weline.test:9523` and
  `production_deploy_platform_url=https://app.aiweline.com` in its summary.
- The local deploy endpoint policy result reports
  `raw_platform_url=https://app.weline.test:9523`, `exact_root=true`, and
  resolved endpoint `https://app.weline.test:9523/api/v1/platform/module/list`.
- The production deploy endpoint policy result reports
  `raw_platform_url=https://app.aiweline.com`, `exact_root=true`, and resolved
  endpoint `https://app.aiweline.com/api/v1/platform/module/list`.
- Completion audit returned `ok=true`, `complete=false`,
  `deploy_endpoint_policy_gate=true`, and `final_preflight_gate=true`.

Residual gate:

- This makes the aggregate final preflight enforce the same deploy metadata
  shape as the dedicated policy checker. It does not run App checkout sync, App
  setup, WLS startup, token reads, or live API calls.

## 2026-06-23 Exact Root Checks In Live Gate Wrappers

Scope:

- Added wrapper-level deploy endpoint exact-root guards to
  `tools/local-appstore-typed-tag-live-gate.php` and
  `tools/production-appstore-typed-tag-live-gate.php`.
- The local wrapper now requires `local_deploy_policy_exact_root=true` before a
  local AppStore typed-tag live E2E can run.
- The production wrapper now requires `production_deploy_policy_exact_root=true`
  before a production AppStore typed-tag live E2E can run.
- Both wrappers now expose the deployment-selected marketplace root in
  `summary.*_deploy_platform_url` and in `tool_results.*_deploy_policy` as
  `raw_platform_url` plus `exact_root`.
- Updated `tools/wls-panel-completion-audit.php`,
  `90-completion-audit-and-next-gates.md`,
  `95-final-acceptance-runbook.md`, and
  `96-requirement-traceability.md` so the wrapper-level guards remain part of
  the completion evidence.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-typed-tag-live-gate.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\production-appstore-typed-tag-live-gate.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-typed-tag-live-gate.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\production-appstore-typed-tag-live-gate.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php --fail-on-incomplete=1
```

Results:

- PHP lint passed for the two live-gate wrappers and the completion audit. The
  local PHP CLI still emits duplicate-extension and OPcache warnings before
  normal output.
- Local wrapper `--report-only=1` returned exit `0` with `status=blocked`,
  `ready_for_live=false`, `live_executed=false`, `guard_passed=true`, and
  `local_deploy_policy_exact_root=true`.
- Local wrapper summary reported
  `local_deploy_platform_url=https://app.weline.test:9523`; its deploy policy
  result reported `raw_platform_url=https://app.weline.test:9523` and
  `exact_root=true`.
- Production wrapper `--report-only=1` against
  `deploy-current-production-default.json` returned exit `0` with
  `status=blocked`, `ready_for_live=false`, `live_executed=false`,
  `guard_passed=true`, and `production_deploy_policy_exact_root=true`.
- Production wrapper summary reported
  `production_deploy_platform_url=https://app.aiweline.com`; its deploy policy
  result reported `raw_platform_url=https://app.aiweline.com` and
  `exact_root=true`.
- Final preflight `--report-only=1` still returned exit `0` with
  `ready_for_live_local_appstore_e2e=false` and `goal_complete=false`.
- Completion audit returned `ok=true`, `complete=false`, and all wrapper text
  checks passed. The `--fail-on-incomplete=1` mode still exits `1` as expected
  because the local AppStore live E2E proof remains open.

Residual gate:

- This locks the wrapper behavior to deployment metadata: local development
  resolves to `https://app.weline.test:9523`, and production deployment resolves
  to `https://app.aiweline.com`. It does not run App checkout sync, App setup,
  WLS startup, token reads, or live API calls.

## 2026-06-23 Local Readiness Uses Deploy Current Endpoint

Scope:

- Updated `tools/local-appstore-readiness-probe.php` so its default local
  AppStore host and port are derived from
  `tools/deploy-current-local-development.json`.
- Added readiness checks for `local_deploy_current_readable`,
  `local_deploy_current_environment_local`,
  `local_deploy_current_exact_appstore_root`,
  `local_deploy_current_resolves_expected_endpoint`,
  `local_deploy_current_not_www_host`, and
  `local_deploy_current_matches_probe_endpoint`.
- Added `fix_local_deploy_current_marketplace_metadata` to the readiness
  action plan when the selected probe endpoint and local deploy-current
  metadata disagree.
- Promoted this into `tools/local-appstore-typed-tag-live-gate.php` as
  `readiness_deploy_current_locked=true`, and into
  `tools/wls-panel-final-preflight.php` as
  `local_readiness_deploy_current_locked=true`.
- Updated `90-completion-audit-and-next-gates.md`,
  `92-local-appstore-sync-manifest.md`,
  `95-final-acceptance-runbook.md`, and
  `96-requirement-traceability.md` so the local marketplace proof depends on
  deployment metadata rather than a hard-coded default.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-typed-tag-live-gate.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php --action-plan-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php --action-plan-only=1 --host=127.0.0.1 --port=9518
php app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-typed-tag-live-gate.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
```

Results:

- PHP lint passed for the readiness probe, local live-gate wrapper, final
  preflight, and completion audit. The local PHP CLI still emits duplicate
  extension and OPcache warnings before normal output.
- The normal readiness action plan returned exit `1` as expected because the
  App checkout still has real blockers, but it now reported endpoint
  `https://app.weline.test:9523`, `source=deploy-current`,
  `local_deploy_current.raw_platform_url=https://app.weline.test:9523`, and
  `local_deploy_current_matches_probe_endpoint=true`.
- The negative readiness action plan with `--host=127.0.0.1 --port=9518`
  returned exit `1`, reported
  `local_deploy_current_matches_probe_endpoint=false`, added
  `fix_local_deploy_current_marketplace_metadata`, and included that mismatch
  in the blocked live typed-tag action. This proves an unrelated listening port
  cannot satisfy the local marketplace gate.
- The local live-gate wrapper `--report-only=1` returned exit `0` with
  `status=blocked`, `ready_for_live=false`, `live_executed=false`,
  `guard_passed=true`, and `readiness_deploy_current_locked=true`.
- Final preflight `--report-only=1` returned exit `0` with
  `local_readiness_deploy_current_locked=true`,
  `readiness_deploy_current_url=https://app.weline.test:9523`, and
  `ready_for_live_local_appstore_e2e=false`.
- Completion audit returned `ok=true`, `complete=false`, and all required text
  checks passed.

Residual gate:

- This closes the local endpoint-source weakness for the remaining marketplace
  gate. It still does not sync the App checkout, run App setup, start WLS, read
  tokens, write the official App catalog, or call the live AppStore API.

## 2026-06-23 Local Readiness Requires Explicit Local Deploy Mode

Scope:

- Updated `tools/local-appstore-readiness-probe.php` so the local App checkout
  must declare an explicit local development deploy mode before any local
  AppStore proof can proceed.
- Added `app_env_deploy_mode_local=true` to the readiness gate. Accepted values
  are `dev` and `local`; production-style values are blockers for the local
  marketplace proof.
- Added the `app_env` payload to action-plan output with the env path,
  detected `deploy_mode`, and `is_local` result.
- Merged env drift and deploy-current endpoint drift under the same corrective
  action: `fix_local_deploy_current_marketplace_metadata`.
- Promoted the check into `tools/local-appstore-typed-tag-live-gate.php` as
  `readiness_app_env_deploy_mode_local=true`, and into
  `tools/wls-panel-final-preflight.php` as
  `local_readiness_app_env_deploy_mode_local=true`.
- Updated `90-completion-audit-and-next-gates.md`,
  `92-local-appstore-sync-manifest.md`,
  `95-final-acceptance-runbook.md`, and
  `96-requirement-traceability.md` so the local AppStore gate requires both
  deploy-current endpoint lock and explicit local App env mode.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-typed-tag-live-gate.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php --action-plan-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php --action-plan-only=1 --host=127.0.0.1 --port=9518
php app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-typed-tag-live-gate.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php --fail-on-incomplete=1
```

Results:

- PHP lint passed for the readiness probe, local live-gate wrapper, final
  preflight, and completion audit. The local PHP CLI still emits duplicate
  extension and OPcache warnings before normal output.
- The App checkout env probe confirmed `app/etc/env.php` currently declares
  `deploy=dev`.
- The normal readiness action plan returned exit `1` as expected because the
  App checkout still has real blockers, but it now reported
  `app_env_deploy_mode_local=true`, `app_env.deploy_mode=dev`,
  `app_env.is_local=true`, endpoint `https://app.weline.test:9523`, and
  `source=deploy-current`.
- The readiness action plan with `--host=127.0.0.1 --port=9518` returned exit
  `1`, kept `app_env_deploy_mode_local=true`, reported
  `local_deploy_current_matches_probe_endpoint=false`, and emitted
  `fix_local_deploy_current_marketplace_metadata`.
- The local live-gate wrapper `--report-only=1` returned exit `0` with
  `status=blocked`, `ready_for_live=false`, `live_executed=false`,
  `guard_passed=true`, `readiness_deploy_current_locked=true`, and
  `readiness_app_env_deploy_mode_local=true`.
- Final preflight `--report-only=1` returned exit `0` with
  `local_readiness_deploy_current_locked=true`,
  `local_readiness_app_env_deploy_mode_local=true`, and
  `ready_for_live_local_appstore_e2e=false`.
- Completion audit returned `ok=true`, `complete=false`, and all required text
  checks passed. The `--fail-on-incomplete=1` mode still exits `1` as expected
  because live local AppStore proof remains open.

Residual gate:

- This hardens the rule that local development uses the local App marketplace
  from deployment metadata and a local App env mode. It still does not sync the
  App checkout, run App setup, start WLS, read tokens, write the official App
  catalog, or call the live AppStore API.

## 2026-06-23 Local AppStore Live E2E Authorization Packet

Scope:

- Added `tools/wls-panel-live-e2e-authorization-pack.php` as a read-only
  authorization packet for the final local AppStore typed-tag live E2E.
- The packet aggregates the readiness action plan, final preflight, sync
  manifest drift report, local and production deploy endpoint policies, exact
  local/production marketplace roots, deferred execution order, no-live-call
  checks, and no-secret checks.
- Updated `00-INDEX.md`, `90-completion-audit-and-next-gates.md`,
  `92-local-appstore-sync-manifest.md`, `95-final-acceptance-runbook.md`, and
  `96-requirement-traceability.md` so this packet is part of the remaining
  marketplace gate before any App checkout sync, manifest/source write, WLS
  startup, token export, or live API call.
- Updated `tools/wls-panel-completion-audit.php` so the packet and its key
  fields are required completion-audit inputs.
- Added `--self-test=1` to prove in memory that the packet rejects bearer
  values, cookie values, private key markers, and non-live runnable steps.
- Updated `tools/wls-panel-final-preflight.php` so the aggregate preflight
  reports `authorization_pack_self_test_passed=true`.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php --fail-if-unsafe=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
```

Results:

- PHP lint passed for the new authorization packet tool. The local PHP CLI
  still emits duplicate extension and OPcache warnings before normal output.
- The authorization packet self-test returned exit `0` with `passed=true`,
  covering safe placeholder output plus rejected bearer, cookie, private key,
  and non-live runnable-step cases.
- The packet returned exit `0` with `ok=true`,
  `authorization_pack_ready_for_review=true`,
  `ready_for_live_local_appstore_e2e=false`, and
  `current_state=blocked_before_live_run`.
- The packet proved `local_endpoint_exact_root=true`,
  `production_endpoint_exact_root=true`,
  `local_env_is_explicit_dev_or_local=true`, `sync_manifest_ok=true`,
  `preflight_kept_no_live_call=true`, `premature_allow_is_blocked=true`,
  `execution_order_present=true`, `all_side_effect_steps_deferred=true`, and
  `only_live_step_runnable_when_ready=true`, and `no_secret_values=true`.
- The execution order lists the deferred steps
  `authorized_app_checkout_sync`, `run_local_app_setup_after_sync`,
  `prepare_official_manifest`, `start_local_app_wls`,
  `set_local_marketplace_bearer_token`, and `run_live_typed_tag_e2e`.
- The blocked checks remain
  `app_schema_has_sqlite_composite_pk_guard`, `app_port_listening`,
  `official_manifest_readable`, `official_manifest_has_wls_positive`,
  `official_manifest_has_negative_canary`,
  `official_manifest_negative_canary_exact`, and
  `bearer_token_env_present`.
- Final preflight `--report-only=1` returned exit `0` with
  `authorization_pack_self_test_passed=true`,
  `ready_for_live_local_appstore_e2e=false`, and `goal_complete=false`.

Residual gate:

- This proves the next live-E2E execution can be reviewed safely before
  authorization. It still does not sync the App checkout, run App setup, start
  WLS, export or read token values, write the official App catalog, or call the
  live AppStore API.

## 2026-06-23 AppStore Deployment Endpoint And Live Evidence Validator

Scope:

- Added `tools/validate-appstore-live-e2e-evidence.php` as a read-only
  validator for captured local and production AppStore typed-tag live E2E JSON.
- Updated the guarded local and production live-gate wrappers so an actual
  `--allow-live=1` run emits sanitized `live_evidence` with endpoint, endpoint
  source, AppStore environment, redaction marker, conclusive-negative flag, and
  typed-tag cases.
- Updated `tools/wls-panel-final-preflight.php` so it runs the validator
  self-test and reports `live_e2e_evidence_validator_self_test_passed=true`.
- Updated `00-INDEX.md`, `90-completion-audit-and-next-gates.md`,
  `92-local-appstore-sync-manifest.md`, `95-final-acceptance-runbook.md`, and
  `96-requirement-traceability.md` so local development proof must validate
  captured evidence with `--expect=local`, while deployed/production proof must
  validate captured evidence with `--expect=production`.
- The deployment information rule is now explicit in both runbook and
  validators: local uses `https://app.weline.test:9523`, production/deployed
  tests read `var/deploy/current.json` and must resolve to
  `https://app.aiweline.com`; `www.aiweline.com` is not a WLS marketplace
  endpoint.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-live-e2e-evidence.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-typed-tag-live-gate.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\production-appstore-typed-tag-live-gate.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-live-e2e-evidence.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php --fail-on-incomplete=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-typed-tag-live-gate.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\production-appstore-typed-tag-live-gate.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --report-only=1
```

Results:

- PHP lint passed for the new validator, both live-gate wrappers, final
  preflight, and completion audit. The local PHP CLI still emits duplicate
  extension and OPcache warnings before normal output.
- The live evidence validator self-test returned exit `0` with `passed=true`.
  It accepts local runner evidence and production wrapper `live_evidence`, and
  rejects production evidence that uses the local endpoint, missing conclusive
  negative canary evidence, and bearer/cookie/private-key style secret output.
- Final preflight `--report-only=1` returned exit `0` with
  `live_e2e_evidence_validator_self_test_passed=true`,
  `local_endpoint=https://app.weline.test:9523/api/v1/platform/module/list`,
  `production_endpoint=https://app.aiweline.com/api/v1/platform/module/list`,
  `ready_for_live_local_appstore_e2e=false`, and `goal_complete=false`.
- Completion audit returned `ok=true`, `complete=false`, and
  `live_e2e_evidence_validator=true`. The
  `--fail-on-incomplete=1` mode still exits `1` as expected because live local
  AppStore proof remains open.
- Local live-gate report mode returned `status=blocked`,
  `ready_for_live=false`, `live_executed=false`, `live_evidence=null`,
  `guard_passed=true`, and endpoint
  `https://app.weline.test:9523/api/v1/platform/module/list`.
- Production live-gate report mode returned `status=blocked`,
  `ready_for_live=false`, `live_executed=false`, `live_evidence=null`,
  `guard_passed=true`, `production_deploy_platform_url=https://app.aiweline.com`,
  and endpoint `https://app.aiweline.com/api/v1/platform/module/list`.

Residual gate:

- This solidifies that local development uses the local AppStore and deployed
  tests use the production AppStore from deployment information. It still does
  not sync the App checkout, run App setup, start WLS, export or read token
  values, write the official App catalog, or call the live AppStore API.

## 2026-06-23 AppStore Live E2E Capture Wrapper

Scope:

- Added `tools/wls-panel-live-e2e-capture.php` as the final capture-and-validate
  wrapper for local and production AppStore typed-tag live E2E evidence.
- Default mode is preflight-only. It calls the appropriate live-gate wrapper
  without `--allow-live=1`, parses its JSON, writes nothing, and prints no
  token values.
- With `--allow-live=1`, it still relies on the underlying live gate readiness
  checks. It writes sanitized evidence only after `live_executed=true`, and only
  under `var\wls-panel-plan\local-appstore-live-e2e.json` or
  `var\wls-panel-plan\production-appstore-live-e2e.json`.
- After a live write, it immediately calls
  `tools/validate-appstore-live-e2e-evidence.php --evidence=...`; final proof
  must report `captured_valid`, `evidence_written=true`, and a passing
  validator result.
- Updated `00-INDEX.md`, `90-completion-audit-and-next-gates.md`,
  `92-local-appstore-sync-manifest.md`, `95-final-acceptance-runbook.md`, and
  `96-requirement-traceability.md` so the capture wrapper is the preferred
  local and production final proof path.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-capture.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-capture.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-capture.php --environment=local
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-capture.php --environment=production --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-capture.php --environment=local --allow-live=1
Test-Path var\wls-panel-plan\local-appstore-live-e2e.json
Test-Path var\wls-panel-plan\production-appstore-live-e2e.json
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
```

Results:

- PHP lint passed for the capture wrapper. The local PHP CLI still emits
  duplicate extension and OPcache warnings before normal output.
- The capture wrapper self-test returned exit `0` with `passed=true`, covering
  default local and production evidence paths, custom in-var evidence path,
  outside-var rejection, production deploy-current argument construction, and
  local gate argument shape.
- Local capture preflight returned exit `0` with `status=preflight_only`,
  `allow_live=false`, `live_executed=false`, `evidence_written=false`, and the
  underlying endpoint
  `https://app.weline.test:9523/api/v1/platform/module/list`.
- Production capture preflight using the production fixture returned exit `0`
  with `status=preflight_only`, `allow_live=false`, `live_executed=false`,
  `evidence_written=false`, and the underlying endpoint
  `https://app.aiweline.com/api/v1/platform/module/list`.
- A guarded local `--allow-live=1` run while readiness is still false returned
  exit `1` with `status=blocked_before_live`, `live_executed=false`, and
  `evidence_written=false`.
- `Test-Path` confirmed both
  `var\wls-panel-plan\local-appstore-live-e2e.json` and
  `var\wls-panel-plan\production-appstore-live-e2e.json` are absent after the
  blocked run.
- Final preflight `--report-only=1` reports
  `live_e2e_capture_self_test_passed=true`.
- Completion audit returned `ok=true`, `complete=false`, and
  `live_e2e_capture_wrapper=true`; the completion gate remains open only for
  the real AppStore live evidence path.

Residual gate:

- This adds a safer final evidence capture path, but still does not authorize or
  perform the App checkout sync, App setup, WLS startup, token export, official
  App catalog write, or live AppStore API call.

## 2026-06-23 Authorization Packet Capture Guard Integration

Scope:

- Extended `tools/wls-panel-live-e2e-authorization-pack.php` so the pre-live
  authorization packet also runs `tools/wls-panel-live-e2e-capture.php
  --self-test=1`.
- The packet now reports `capture_self_test_passed=true` and
  `capture_path_traversal_guarded=true` before any App checkout sync, manifest
  write, WLS startup, token export, or live AppStore API call is authorized.
- The authorization packet self-test now covers capture-guard case detection in
  memory and rejects packets that omit `path_traversal_outside_var_rejected`.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php --fail-if-unsafe=1
```

Results:

- PHP lint passed for the authorization packet. The local PHP CLI still emits
  duplicate extension and OPcache warnings before normal output.
- `--self-test=1` returned exit `0` with `passed=true`,
  `capture_guard_requires_path_traversal_case=true`, and
  `capture_guard_rejects_missing_path_traversal_case=true`.
- The read-only authorization packet returned exit `0` and reported
  `capture_self_test_passed=true`,
  `capture_path_traversal_guarded=true`,
  `authorization_pack_ready_for_review=true`, and
  `current_state=blocked_before_live_run` while the App checkout blockers
  remain.
- The `--fail-if-unsafe=1` packet command returned exit `0` with
  `ok=true`, `authorization_pack_ready_for_review=true`,
  `capture_path_traversal_guarded=true`, and `no_secret_values=true`. This is
  the CI-safe form for failing unsafe authorization packets before any live
  AppStore action is approved.

## 2026-06-23 Capture Wrapper Evidence Path Traversal Guard

Scope:

- Hardened `tools/wls-panel-live-e2e-capture.php` so custom
  `--evidence-output` values are path-segment-normalized before the
  `var\wls-panel-plan` allowed-root check.
- Added `path_traversal_outside_var_rejected` to the capture wrapper
  `--self-test=1` suite. The regression case uses
  `var\wls-panel-plan\..\leak.json` and requires
  `evidence_output_inside_var=false`.
- This is still a no-live, no-token, no-WLS-start guard. Blocked/preflight
  runs do not create local or production evidence JSON.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-capture.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-capture.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-capture.php --environment=local --evidence-output=var\wls-panel-plan\..\leak.json
```

Results:

- PHP lint passed for the capture wrapper. The local PHP CLI still emits
  duplicate extension and OPcache warnings before normal output.
- Capture self-test returned `passed=true` and included
  `path_traversal_outside_var_rejected`.
- The explicit traversal probe returned exit `1` with `ok=false`,
  `status=blocked`, `inside_var=false`,
  `evidence_output_inside_var=false`, and
  `errors=["check_failed:evidence_output_inside_var"]`.

## 2026-06-23 Deployment AppStore Endpoint Contract Recheck

Scope:

- Rechecked the user correction that local development must use the local App
  Store, while deployed and production tests must automatically use the online
  App Store.
- The locked development root is `https://app.weline.test:9523`.
- The locked deployed production root is `https://app.aiweline.com`.
- Production tests are required to resolve the endpoint from
  `var/deploy/current.json`, not from a manually supplied URL and not from a
  stale local configuration value.
- The deployment artifact must record `appstore_environment`,
  `appstore_platform_url`, `appstore_platform_url_source`, and
  `deploy_mode_source`. In production, `appstore_platform_url` must be the
  platform root `https://app.aiweline.com`, not an API path.

Commands:

```powershell
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-endpoint-source-contract.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --expect=production
php app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --resolve-endpoint-only=1
```

Results:

- Source contract returned `passed=true` and verified that
  `DeployOrchestratorService` contains the local and production App Store
  constants, writes the release `current.json` marketplace fields, resolves
  deploy mode and AppStore information, and rejects `www.aiweline.com` /
  `www.weline.test:9518` as marketplace hosts.
- Deploy endpoint policy self-test returned `passed=true`. It proves local
  `dev/local` deployment resolves to
  `https://app.weline.test:9523/api/v1/platform/module/list`, production
  deployment resolves to
  `https://app.aiweline.com/api/v1/platform/module/list`, and negative cases
  reject empty production URLs, local URLs in production, `www.aiweline.com`,
  API paths stored in `appstore_platform_url`, and production mode in local
  metadata.
- Production fixture policy returned `passed=true` with
  `appstore_platform_url=https://app.aiweline.com`,
  `appstore_environment=production`, and resolved endpoint
  `https://app.aiweline.com/api/v1/platform/module/list`.
- The typed-tag runner in `--resolve-endpoint-only=1` mode returned
  `passed=true`, `blocked=false`, `endpoint_source=deploy-current:...`, and
  resolved to `https://app.aiweline.com/api/v1/platform/module/list` without
  making a network call.
- The local PHP CLI still emits duplicate extension and OPcache warnings before
  normal JSON output. These warnings are environment noise and did not affect
  the gate results.

## 2026-06-23 Blocked Preflight Evidence Absence Gate

Scope:

- Hardened `tools/wls-panel-final-preflight.php` so the aggregate pre-live gate
  now checks for stale or accidental live evidence files after blocked/preflight
  local and production live-gate probes.
- The new machine-readable check is
  `blocked_preflight_no_evidence_files=true`.
- The checked paths are:
  `var\wls-panel-plan\local-appstore-live-e2e.json`,
  `var\wls-panel-plan\production-appstore-live-e2e.json`, and `var\leak.json`.
- This preserves the rule that a blocked `--allow-live=1 --report-only=1`
  probe must not leave a JSON artifact that could later be mistaken for final
  live AppStore evidence.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
```

Results:

- PHP lint passed for both modified tools. The local PHP CLI still emits
  duplicate extension and OPcache warnings before normal output.
- Final preflight returned exit `0` in report-only mode with
  `ready_for_live_local_appstore_e2e=false`, `goal_complete=false`, and
  `blocked_preflight_no_evidence_files=true`.
- `summary.blocked_preflight_evidence_files` reported all three paths with
  `exists=false`: local live evidence, production live evidence, and the
  traversal leak target.
- Completion audit returned `ok=true`, `complete=false`, and
  `final_preflight_gate=true`. The only remaining incomplete rows are still the
  intended local AppStore typed-tag API E2E rows.

## 2026-06-23 Authorization Packet Stale Evidence Guard

Scope:

- Extended `tools/wls-panel-live-e2e-authorization-pack.php` so the pre-live
  authorization packet now requires the aggregate final preflight check
  `blocked_preflight_no_evidence_files=true`.
- The packet also exposes the final-preflight file status under
  `tool_results.final_preflight.blocked_preflight_evidence_files` so the
  operator can see whether stale local evidence, production evidence, or the
  traversal leak target exists before any live action is approved.
- This keeps the authorization packet aligned with the final preflight: no App
  checkout sync, manifest/source write, WLS start, token export, or live
  AppStore API call can be reviewed as safe while stale evidence files exist.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php --fail-if-unsafe=1
```

Results:

- PHP lint passed for both modified tools. The local PHP CLI still emits
  duplicate extension and OPcache warnings before normal output.
- Authorization packet self-test returned `passed=true`.
- The CI-safe authorization packet returned exit `0` with
  `authorization_pack_ready_for_review=true`,
  `current_state=blocked_before_live_run`,
  `blocked_preflight_no_evidence_files=true`,
  `capture_path_traversal_guarded=true`, and `no_secret_values=true`.
- `tool_results.final_preflight.blocked_preflight_evidence_files` showed
  `exists=false` for local live evidence, production live evidence, and the
  path-traversal leak target.

## 2026-06-23 Marketplace Endpoint Deployment Metadata Recheck

Scope:

- Rechecked the corrected App Store endpoint rule after confirming the local
  marketplace checkout is `E:\WelineFramework\Framework-Official\App\weline`
  and the local marketplace URL is `https://app.weline.test:9523`.
- Local development remains locked to `https://app.weline.test:9523`.
- Deployed and production checks must resolve from deployment metadata and must
  record `appstore_platform_url=https://app.aiweline.com`.
- `www.weline.test:9518` and `www.aiweline.com` remain official-site hosts, not
  WLS marketplace endpoints.

Commands:

```powershell
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-local-development.json --expect=local
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --expect=production
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-endpoint-source-contract.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\production-appstore-typed-tag-live-gate.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-typed-tag-live-gate.php --report-only=1
```

Results:

- Deploy endpoint policy self-test returned `passed=true`, including negative
  cases for empty production URL, production using local App Store, `www.*`
  hosts, API paths stored inside `appstore_platform_url`, and local production
  mode drift.
- Local deployment metadata fixture returned `passed=true` with
  `raw_platform_url=https://app.weline.test:9523` and resolved endpoint
  `https://app.weline.test:9523/api/v1/platform/module/list`.
- Production deployment metadata fixture returned `passed=true` with
  `raw_platform_url=https://app.aiweline.com` and resolved endpoint
  `https://app.aiweline.com/api/v1/platform/module/list`.
- Source contract returned `passed=true`, proving `DeployOrchestratorService`
  writes `deploy_mode_source`, `appstore_environment`,
  `appstore_platform_url`, and `appstore_platform_url_source` into deployment
  metadata; `AppStorePlatformUrlResolver` reads production
  `var/deploy/current.json`; `AccountBindService` and WLS Panel consume the
  resolver; and no marketplace source points at a `www.*` host.
- Production live gate report-only mode returned `guard_passed=true`,
  `production_deploy_platform_url=https://app.aiweline.com`, and endpoint
  `https://app.aiweline.com/api/v1/platform/module/list`; it stayed blocked
  because no production bearer token was provided, so no live API call ran.
- Local live gate report-only mode returned `guard_passed=true` and endpoint
  `https://app.weline.test:9523/api/v1/platform/module/list`; it stayed blocked
  because the local App Store checkout still needs the authorized sync/setup,
  official manifest, WLS startup, and token readiness before live E2E.
- No App checkout sync, manifest/source write, WLS start, token read, or live
  AppStore network request was performed.

## 2026-06-23 Authorization Packet Drift Review Gate

Scope:

- Added a read-only authorization packet mode for reviewing the exact local App
  checkout drift before any scoped `分项` sync is authorized.
- The default authorization packet remains compact for routine CI/status
  checks; `--include-drift-rows=1` exposes bounded per-file drift rows for
  human review.
- This gate keeps the local/deployed App Store endpoint rule explicit:
  development uses `https://app.weline.test:9523`, while deployed production
  verification must read `https://app.aiweline.com` from deployment metadata.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php --fail-if-unsafe=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php --include-drift-rows=1 --fail-if-unsafe=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
```

Results:

- PHP lint passed for the authorization packet tool. The local PHP CLI still
  emits duplicate extension and OPcache warnings before normal output.
- Authorization packet self-test returned `passed=true`.
- Default authorization packet returned
  `authorization_pack_ready_for_review=true`,
  `ready_for_live_local_appstore_e2e=false`,
  `current_state=blocked_before_live_run`,
  `local_endpoint_exact_root=true`,
  `production_endpoint_exact_root=true`,
  `all_side_effect_steps_deferred=true`, and `no_secret_values=true`.
- Drift-review authorization packet returned
  `drift_rows_bounded_when_requested=true`,
  `drift_summary_only=false`, and `drift_rows_count=36`; the drift summary was
  `different=19` and `missing_app=17`.
- Final preflight returned `ok=true`, `ready_for_live_local_appstore_e2e=false`,
  `goal_complete=false`, `local_deploy_platform_url=https://app.weline.test:9523`,
  `production_deploy_platform_url=https://app.aiweline.com`,
  `local_endpoint=https://app.weline.test:9523/api/v1/platform/module/list`,
  `production_endpoint=https://app.aiweline.com/api/v1/platform/module/list`,
  and `drifted_count=36`.
- Completion audit returned `ok=true`, `complete=false`, with 13 of 14
  completion rows proven and the remaining marketplace live E2E row still
  blocked by the local App checkout sync/setup, manifest/source catalog, App
  WLS startup, and token readiness gates.
- No App checkout sync, manifest/source write, WLS start, token read, or live
  AppStore network request was performed.

## 2026-06-23 Authorization Packet Rollback Review Gate

Scope:

- Added a read-only App checkout rollback/scope review to the local AppStore
  sync manifest checker.
- The checker now supports `--rollback-review=1`, which reads the App checkout
  `git status --short --untracked-files=all`, marks rows inside/outside the
  allowed WLS Panel sync list, and emits an `out_of_scope_fingerprint` for
  before/after scoped `分项` comparison.
- The authorization packet now supports `--include-rollback-review=1` so the
  same review can be bundled with drift rows immediately before the user
  authorizes any sync.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php --with-drift=1 --drift-summary-only=1 --rollback-review=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php --include-drift-rows=1 --include-rollback-review=1 --fail-if-unsafe=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php --fail-if-unsafe=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
```

Results:

- PHP lint passed for both modified tools. The local PHP CLI still emits
  duplicate extension and OPcache warnings before normal output.
- Sync-manifest rollback review returned `ok=true`, `drifted_count=36`,
  App checkout git `exit_code=0`, `status_total=2`, `allowed_status=0`,
  `out_of_scope_status=2`, and
  `out_of_scope_fingerprint=0863c8ebd5abef29`.
- Authorization packet with `--include-drift-rows=1 --include-rollback-review=1`
  returned `authorization_pack_ready_for_review=true`,
  `current_state=blocked_before_live_run`,
  `rollback_review_parsed_when_requested=true`,
  `drift_rows_bounded_when_requested=true`, `drift_rows_count=36`,
  App checkout git `exit_code=0`, `status_total=2`, `allowed_status=0`,
  `out_of_scope_status=2`, the same
  `out_of_scope_fingerprint=0863c8ebd5abef29`, and `no_secret_values=true`.
- Default authorization packet remains compact: `rollback_review_default=false`
  and `drift_rows_default=false`, while the packet still returns
  `ready_for_review=true` and `current_state=blocked_before_live_run`.
- Final preflight returned `ok=true`, `ready_for_live_local_appstore_e2e=false`,
  `goal_complete=false`, local URL `https://app.weline.test:9523`, production
  URL `https://app.aiweline.com`, and `drifted_count=36`.
- Completion audit returned `ok=true`, `complete=false`, with 13 of 14
  completion rows proven and one marketplace live E2E row still incomplete.
- No App checkout sync, manifest/source write, WLS start, token read, or live
  AppStore network request was performed.

## 2026-06-23 Final Workorder Deferred Action Plan

Scope:

- Extended `tools/wls-panel-final-workorder.php` so the final operator handoff
  includes a machine-readable `deferred_action_plan` derived from aggregate
  preflight `summary.readiness_next_actions`.
- The plan preserves the corrected endpoint split: local development uses
  `https://app.weline.test:9523`, while deployed production capture uses
  `https://app.aiweline.com` from `var/deploy/current.json`.
- The blocked plan keeps the App checkout sync, App setup, official manifest
  materialization, token setup, local WLS startup, and live typed-tag E2E as
  explicit deferred actions instead of runnable commands.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-workorder.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-workorder.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-workorder.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
```

Results:

- PHP lint passed for `wls-panel-final-workorder.php` and
  `wls-panel-completion-audit.php`. The local PHP CLI still emits duplicate
  extension and OPcache warnings before normal output.
- Final workorder self-test returned `passed=true` with seven cases, including
  `blocked_state_exports_deferred_action_plan`,
  `blocked_state_keeps_deferred_actions_not_runnable`, and
  `deferred_action_plan_keeps_secret_as_placeholder`.
- Final workorder returned `ok=true`, `workorder_ready=true`,
  `current_state=blocked_before_local_live_capture`,
  `ready_for_local_live_capture=false`, `goal_complete=false`,
  `readiness_action_count=6`, and
  `preflight_checks.deferred_action_plan_all_blocked=true`.
- The deferred action plan contained `authorized_app_checkout_sync`,
  `run_local_app_setup_after_sync`, `prepare_official_manifest`,
  `start_local_app_wls`, `set_local_marketplace_bearer_token`, and
  `run_live_typed_tag_e2e`; every action reported `safe_to_run_now=false`.
- Completion audit returned `ok=true`, `complete=false`,
  `final_workorder_gate=true`, with 13 of 14 completion rows proven and 20 of
  22 traceability rows proven.
- Final preflight returned `ok=true`,
  `ready_for_live_local_appstore_e2e=false`, `goal_complete=false`,
  `endpoint_source_contract_passed=true`,
  `local_deploy_endpoint_policy_exact_root=true`,
  `production_deploy_endpoint_policy_exact_root=true`,
  local endpoint `https://app.weline.test:9523/api/v1/platform/module/list`,
  and production endpoint
  `https://app.aiweline.com/api/v1/platform/module/list`.
- No App checkout sync, manifest/source write, WLS start, token read, or live
  AppStore network request was performed.

## 2026-06-23 Deferred Action Validator Gate

Scope:

- Added `tools/validate-final-workorder-deferred-actions.php` as a read-only
  validator for the final workorder `deferred_action_plan`.
- The validator proves the post-authorization handoff remains ordered and safe:
  App checkout sync, App setup, official manifest/source materialization, App
  WLS startup, token setup, and live typed-tag E2E remain explicit actions with
  the correct authorization, secret, and endpoint boundaries.
- The aggregate final preflight now mirrors the validator self-test as
  `deferred_actions_validator_self_test_passed`.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-final-workorder-deferred-actions.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-final-workorder-deferred-actions.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-final-workorder-deferred-actions.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
```

Results:

- PHP lint passed for `validate-final-workorder-deferred-actions.php`,
  `wls-panel-final-preflight.php`, and `wls-panel-completion-audit.php`. The
  local PHP CLI still emits duplicate extension and OPcache warnings before
  normal output.
- Deferred-action validator self-test returned `passed=true` and covered five
  cases: accepts blocked contract, accepts ready contract with only live E2E
  runnable, rejects missing required action, rejects secret leak, and rejects
  `www.aiweline.com` as the production marketplace root.
- Live validation of the current workorder returned `passed=true`,
  `current_state=blocked_before_local_live_capture`, `action_count=6`,
  `required_actions_ordered=true`, `blocked_state_all_actions_not_runnable=true`,
  `sync_requires_user_authorization=true`,
  `manifest_requires_confirmed_writes=true`,
  `token_requires_secret_placeholder=true`,
  `start_targets_app_weline_9523=true`, and
  `live_uses_guarded_local_gate=true`.
- Final preflight returned `ok=true`,
  `ready_for_live_local_appstore_e2e=false`, `goal_complete=false`, and
  `deferred_actions_validator_self_test_passed=true`.
- Completion audit returned `ok=true`, `complete=false`,
  `deferred_actions_validator_gate=true`, with 13 of 14 completion rows proven
  and 20 of 22 traceability rows proven.
- No App checkout sync, manifest/source write, WLS start, token read, or live
  AppStore network request was performed.

## 2026-06-23 Deferred Action Validator Production Operator Gate

Scope:

- Strengthened `tools/validate-final-workorder-deferred-actions.php` so the
  final workorder handoff validates both `deferred_action_plan` and
  `operator_sequence`.
- The validator now proves local capture/final-gate steps are present and that
  the production capture step remains blocked before launch, reads
  `var\deploy\current.json`, requires deployed
  `appstore_platform_url=https://app.aiweline.com`, writes
  `var\wls-panel-plan\production-appstore-live-e2e.json`, and hands off to the
  production final evidence gate.
- This records the user rule: local development uses the local App Store
  `https://app.weline.test:9523`; deployed production tests must automatically
  use `https://app.aiweline.com` from deployment metadata, not a remembered
  `www.*` or local URL.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-final-workorder-deferred-actions.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-final-workorder-deferred-actions.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-final-workorder-deferred-actions.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-goal-completion-gate.php
git diff --check -- app/code/Weline/Server/doc/wls-panel-plan
```

Results:

- PHP lint passed for `validate-final-workorder-deferred-actions.php`. The
  local PHP CLI still emits duplicate extension and OPcache warnings before
  normal output.
- Deferred-action validator self-test returned `passed=true` and now covers
  seven cases, adding
  `rejects_missing_production_capture_operator_step` and
  `rejects_production_capture_without_deploy_current`.
- Live validation of the current workorder returned `passed=true` with
  `operator_sequence_present=true`,
  `local_capture_operator_step_present=true`,
  `local_final_gate_operator_step_present=true`,
  `production_capture_uses_deploy_current=true`,
  `production_capture_requires_deployed_app_aiweline=true`, and
  `production_final_gate_operator_step_present=true`.
- Final preflight returned `ok=true`, `goal_complete=false`,
  `local_endpoint=https://app.weline.test:9523/api/v1/platform/module/list`,
  and
  `production_endpoint=https://app.aiweline.com/api/v1/platform/module/list`.
- Strict goal completion gate still exits non-zero with `complete=false`
  because `var\wls-panel-plan\local-appstore-live-e2e.json` and
  `var\wls-panel-plan\production-appstore-live-e2e.json` do not exist yet.
- `git diff --check -- app/code/Weline/Server/doc/wls-panel-plan` passed with
  only existing CRLF-normalization warnings.
- No App checkout sync, manifest/source write, WLS start, token read, or live
  AppStore network request was performed.

## 2026-06-23 Deferred Action Validator Local Operator Prerequisite Gate

Scope:

- Strengthened `tools/validate-final-workorder-deferred-actions.php` so the
  local capture operator step is checked beyond command shape.
- The validator now requires the local capture handoff to list the reviewed
  App checkout sync/setup path, official manifest/source catalog readiness,
  `app.weline.test:9523` listener readiness, and
  `WLS_MARKETPLACE_BEARER_TOKEN` setup outside repository files before
  `var\wls-panel-plan\local-appstore-live-e2e.json` can become accepted final
  proof.
- This keeps the local App Store live E2E path aligned with the production
  `var\deploy\current.json` handoff style: both are explicit operator
  sequences, both write captured evidence, and neither can be replaced by a raw
  runner call or a remembered URL.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-final-workorder-deferred-actions.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-final-workorder-deferred-actions.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-final-workorder-deferred-actions.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
git diff --check -- app/code/Weline/Server/doc/wls-panel-plan
```

Results:

- PHP lint passed for `validate-final-workorder-deferred-actions.php`. The
  local PHP CLI still emits duplicate extension and OPcache warnings before
  normal output.
- Deferred-action validator self-test returned `passed=true` and now covers
  eight cases, adding
  `rejects_local_capture_without_reviewed_prerequisites`.
- Live validation of the current workorder returned `passed=true` with
  `local_capture_operator_step_present=true`,
  `local_capture_requires_reviewed_appstore_prerequisites=true`,
  `local_final_gate_operator_step_present=true`,
  `production_capture_uses_deploy_current=true`, and
  `production_capture_requires_deployed_app_aiweline=true`.
- Final preflight returned `ok=true`, `ready_for_live_local_appstore_e2e=false`,
  and `goal_complete=false`; the remaining blockers are still the real local
  App checkout sync/setup, official manifest/source catalog, listener, token,
  and captured evidence gates.
- `git diff --check -- app/code/Weline/Server/doc/wls-panel-plan` passed with
  only existing CRLF-normalization warnings.
- No App checkout sync, manifest/source write, WLS start, token read, or live
  AppStore network request was performed.

## 2026-06-23 Deferred Action Validator Acceptance Contract Gate

Scope:

- Strengthened `tools/validate-final-workorder-deferred-actions.php` so the
  final workorder `acceptance_contract` is machine-checked instead of only
  documented.
- The validator now requires final live-evidence invariants for
  `captured_valid=true`, final evidence gate readiness, production
  deploy-current endpoint source, conclusive `module:wls-extra` negative canary
  behavior, and no-secret evidence.
- This prevents a future workorder from keeping the right operator commands
  while silently dropping the proof conditions that make the AppStore typed-tag
  result acceptable.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-final-workorder-deferred-actions.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-final-workorder-deferred-actions.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-final-workorder-deferred-actions.php
```

Results:

- PHP lint passed for `validate-final-workorder-deferred-actions.php`. The
  local PHP CLI still emits duplicate extension and OPcache warnings before
  normal output.
- Deferred-action validator self-test returned `passed=true` and now covers
  nine cases, adding `rejects_missing_acceptance_contract_invariant`.
- Live validation of the current workorder returned `passed=true` with
  `acceptance_contract_has_required_invariants=true`, alongside the existing
  local/production operator-sequence and no-secret checks.
- No App checkout sync, manifest/source write, WLS start, token read, or live
  AppStore network request was performed.

## 2026-06-23 Strict Goal Completion Gate

Scope:

- Added `tools/wls-panel-goal-completion-gate.php` as the final read-only gate
  before the WLS Panel goal can be marked complete.
- The gate requires completion audit, final preflight, deferred-action
  validation, local captured evidence final gate, and production captured
  evidence final gate to agree.
- It intentionally remains stricter than the status audit: missing captured
  local or production evidence keeps `complete=false`.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-goal-completion-gate.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-goal-completion-gate.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-goal-completion-gate.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
```

Results:

- PHP lint passed for `wls-panel-goal-completion-gate.php`,
  `wls-panel-final-preflight.php`, and `wls-panel-completion-audit.php`. The
  local PHP CLI still emits duplicate extension and OPcache warnings before
  normal output.
- Goal completion gate self-test returned `passed=true` with five cases:
  accepts complete goal evidence, rejects incomplete completion audit, rejects
  missing local evidence, rejects missing production evidence, and rejects
  endpoint-policy drift.
- Running the real goal completion gate returned exit code `1`,
  `ok=true`, `complete=false`, with failed checks
  `completion_audit_complete`, `completion_matrix_all_proven`,
  `traceability_matrix_all_proven`, `final_preflight_goal_complete`,
  `local_final_gate_ready`, and `production_final_gate_ready`.
- The same real gate reported blockers `completion_audit_incomplete`,
  `local_live_evidence_not_accepted`, and
  `production_live_evidence_not_accepted`, with missing evidence paths
  `var\wls-panel-plan\local-appstore-live-e2e.json` and
  `var\wls-panel-plan\production-appstore-live-e2e.json`.
- Final preflight returned `ok=true`,
  `ready_for_live_local_appstore_e2e=false`, `goal_complete=false`, and
  `goal_completion_gate_self_test_passed=true`.
- Completion audit returned `ok=true`, `complete=false`,
  `goal_completion_gate=true`, with 13 of 14 completion rows proven and 20 of
  22 traceability rows proven.
- No App checkout sync, manifest/source write, WLS start, token read, or live
  AppStore network request was performed.

## 2026-06-23 WLS Panel AppStore Fallback Source Contract

Scope:

- Hardened `WlsPanel::resolveAppStorePlatformResolution()` so it still follows
  the locked marketplace endpoint policy when
  `Weline\AppStore\Service\AppStorePlatformUrlResolver` is unavailable or
  throws during an AppStore upgrade.
- The normal path still reuses AppStore's resolver. The WLS-only fallback now
  uses explicit `deploy=dev/local` to allow
  `WELINE_APPSTORE_PLATFORM_URL`, `appstore.platform_url`, or the local default
  `https://app.weline.test:9523`; non-local paths read production
  `var/deploy/current.json` with `appstore_environment=production` and
  `appstore_platform_url`, then fall back to `https://app.aiweline.com` only for
  runtime panel availability.
- Extended the read-only source contract gate with
  `panel_has_locked_fallback_defaults`,
  `panel_fallback_local_mode_is_explicit_only`,
  `panel_fallback_allows_local_sources`,
  `panel_fallback_reads_deployed_production_current`, and
  `panel_fallback_ignores_non_production_current`.

Commands:

```powershell
php -l app\code\Weline\Server\Controller\Backend\WlsPanel.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-endpoint-source-contract.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-endpoint-source-contract.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
git diff --check -- app/code/Weline/Server/Controller/Backend/WlsPanel.php app/code/Weline/Server/doc/wls-panel-plan app/code/Weline/Server/i18n
```

Results:

- PHP lint passed for `WlsPanel.php`,
  `validate-appstore-endpoint-source-contract.php`, and
  `wls-panel-completion-audit.php`.
- Endpoint source contract returned `passed=true`, with all new WLS Panel
  fallback checks true and no `www.*` marketplace host.
- Completion audit returned `ok=true`, `complete=false`, `13/14` completion
  rows proven, `20/22` traceability rows proven, and the same remaining live
  AppStore typed-tag E2E gate.
- Final preflight returned `ok=true`,
  `ready_for_live_local_appstore_e2e=false`, `goal_complete=false`,
  `endpoint_source_contract_passed=true`, local endpoint
  `https://app.weline.test:9523/api/v1/platform/module/list`, and production
  endpoint `https://app.aiweline.com/api/v1/platform/module/list`.
- `git diff --check` exited zero. The local PHP CLI still emits duplicate
  extension and OPcache warnings before normal output.
- No App checkout sync, manifest/source write, WLS start, token read, or live
  AppStore network request was performed.

## 2026-06-23 Deploy Metadata Endpoint Hardening

Scope:

- Hardened the typed-tag E2E endpoint resolver so production deployment tests
  must read an explicit `appstore_platform_url=https://app.aiweline.com` from
  `var/deploy/current.json`.
- Kept local development locked to the local App Store fixture
  `https://app.weline.test:9523`.
- Removed the acceptance-path behavior where a production deploy-current record
  with an empty `appstore_platform_url` could still resolve to the default
  production endpoint.
- Updated the deploy endpoint policy checker so empty local or production
  `appstore_platform_url` values keep an empty resolved endpoint and fail the
  exact-root checks.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --resolve-endpoint-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-local-development.json --resolve-endpoint-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
```

Results:

- Typed-tag runner self-test now includes explicit deploy-current endpoint
  cases. Production with missing `appstore_platform_url` returns
  `deploy_current_missing_appstore_platform_url`; explicit production resolves
  to `https://app.aiweline.com/api/v1/platform/module/list`; explicit local
  resolves to `https://app.weline.test:9523/api/v1/platform/module/list`.
- Deploy endpoint policy self-test still proves local and production positive
  fixtures and rejects empty production URL, local URL in production,
  `www.aiweline.com`, API endpoint paths stored in `appstore_platform_url`, and
  production mode in local metadata.
- Production endpoint-only resolution from
  `tools/deploy-current-production-default.json` returned `passed=true` and
  `endpoint_source=deploy-current:...`.
- Local endpoint-only resolution from
  `tools/deploy-current-local-development.json` returned `passed=true` and
  `endpoint_source=deploy-current:...`.
- Final preflight remained read-only with `ok=true`,
  `ready_for_live_local_appstore_e2e=false`, and `goal_complete=false`.
- Completion audit remained read-only with `ok=true`, `complete=false`; the
  remaining incomplete row is still the authorized live App Store E2E.
- No App checkout sync, manifest/source write, WLS start, token read, or live
  AppStore network request was performed.

## 2026-06-23 Live Evidence Endpoint Source Gate

Scope:

- Hardened the final live E2E evidence chain so a correct marketplace URL is
  not enough by itself. Capture-wrapper evidence must now prove the endpoint
  came from deployment metadata via `endpoint_source=deploy-current:*`.
- `tools/wls-panel-live-e2e-capture.php` now copies the live-gate
  `endpoint_source` into `capture_metadata.endpoint_source`.
- `tools/validate-appstore-live-e2e-evidence.php` now requires
  `evidence_endpoint_source_deploy_current=true` and
  `capture_metadata_endpoint_source_deploy_current=true` for wrapper evidence,
  and its self-test rejects `default:production`.
- `tools/wls-panel-live-evidence-final-gate.php` now requires the validator's
  endpoint-source checks and rejects non-`deploy-current:*` sources in its
  self-test.
- `tools/wls-panel-completion-audit.php` now checks that those endpoint-source
  guard names remain documented and present in the final gate chain.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-live-e2e-evidence.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-evidence-final-gate.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-capture.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-live-e2e-evidence.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-capture.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-evidence-final-gate.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
```

Results:

- PHP lint passed for the three changed live-evidence tools.
- Evidence validator self-test returned `passed=true`; the new
  `rejects_wrapper_non_deploy_current_endpoint_source` case returned invalid
  with `check_failed:evidence_endpoint_source_deploy_current` and
  `check_failed:capture_metadata_endpoint_source_deploy_current`.
- Capture wrapper self-test returned `passed=true`; `captured_payload_has_metadata`
  now covers `capture_metadata.endpoint_source`.
- Final evidence gate self-test returned `passed=true`; the new
  `rejects_non_deploy_current_endpoint_source` case returned false as expected.
- Final preflight remained read-only with `ok=true`,
  `ready_for_live_local_appstore_e2e=false`, and `goal_complete=false`.
- Completion audit remained read-only with `ok=true`, `complete=false`; the
  remaining incomplete row is still the authorized live App Store E2E.
- No App checkout sync, manifest/source write, WLS start, token read, or live
  AppStore network request was performed.

## 2026-06-23 Live Capture Built-In Final Gate

Scope:

- The live E2E capture wrapper now runs the local/production live evidence
  validator first, then runs `wls-panel-live-evidence-final-gate.php` against
  the evidence file that was just written.
- `captured_valid` now means both the schema/content validator and the final
  evidence gate accepted the capture.
- Capture output exposes `final_gate_passed_when_written` and
  `tool_results.final_evidence_gate`, so a deployment run can prove whether the
  local environment used `https://app.weline.test:9523` and the production
  environment used `https://app.aiweline.com`.
- The capture self-test now includes local and production final-gate argument
  cases. The self-test is in-memory only.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-capture.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-capture.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-capture.php --environment=local
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-evidence-final-gate.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-evidence-final-gate.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-evidence-final-gate.php --environment=both --report-only=1
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
git diff --check -- app/code/Weline/Server/doc/wls-panel-plan
```

Results:

- PHP lint passed for `wls-panel-live-e2e-capture.php`,
  `wls-panel-final-preflight.php`, `wls-panel-live-evidence-final-gate.php`,
  and `wls-panel-completion-audit.php`. The local PHP CLI still emits duplicate
  extension and OPcache warnings before normal output.
- Capture self-test returned `passed=true` with ten cases, including
  `local_final_gate_uses_local_evidence_arg` and
  `production_final_gate_uses_production_evidence_arg`.
- Final evidence gate self-test returned `passed=true` with four cases.
- Final evidence gate report-only mode returned `ok=true`, `ready=false`, and
  required both `local` and `production`; it performed no network request, no
  token read, no WLS start, and no write. The only errors were the expected
  missing evidence files before a live capture run.
- Local capture without live authorization returned `status=preflight_only`,
  `live_executed=false`, `evidence_written=false`, local endpoint
  `https://app.weline.test:9523/api/v1/platform/module/list`, and no
  `tool_results.final_evidence_gate` payload because no evidence file was
  written in preflight-only mode.
- Final preflight returned `ok=true`,
  `ready_for_live_local_appstore_e2e=false`, `goal_complete=false`,
  `tools_parsed=true`, `live_e2e_capture_self_test_passed=true`,
  `live_e2e_final_gate_self_test_passed=true`, local endpoint
  `https://app.weline.test:9523/api/v1/platform/module/list`, and production
  endpoint `https://app.aiweline.com/api/v1/platform/module/list`.
- Completion audit returned `ok=true`, `complete=false`, with 13 of 14
  completion rows proven, 20 of 22 traceability rows proven, three open rows,
  `checks.live_e2e_capture_wrapper=true`, and
  `checks.live_e2e_final_gate=true`.
- `git diff --check -- app/code/Weline/Server/doc/wls-panel-plan` returned
  exit code 0 with existing LF-to-CRLF working-copy warnings only.
- Remaining live-readiness blockers are still
  `app_schema_has_sqlite_composite_pk_guard`, `app_port_listening`,
  `official_manifest_readable`, `official_manifest_has_wls_positive`,
  `official_manifest_has_negative_canary`,
  `official_manifest_negative_canary_exact`, and `bearer_token_env_present`.
- No App checkout sync, manifest/source write, WLS start, token read, or live
  AppStore network request was performed.

## 2026-06-23 Final Workorder Gate

Scope:

- Added `tools/wls-panel-final-workorder.php` as the compact operator entry
  before any final marketplace side-effect step.
- The tool runs the aggregate final preflight and condenses it into
  `current_state`, `blocked_checks`, `user_authorization_required`,
  `user_secret_required`, local/deployed marketplace roots, forbidden `www.*`
  marketplace roots, local and production capture commands, and final-gate
  commands.
- The workorder is read-only. It does not sync files, start WLS, write
  manifests, read token values, or call the live AppStore API.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-workorder.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-workorder.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-workorder.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
```

Results:

- PHP lint passed for `wls-panel-final-workorder.php`. The local PHP CLI still
  emits duplicate extension and OPcache warnings before normal output.
- Workorder self-test returned `passed=true` with four in-memory cases:
  blocked-state root retention, bearer-token blocker secret handling without
  value leakage, ready-state local-live step gating, and complete-state
  separation from ready-state.
- Normal workorder returned `ok=true`, `workorder_ready=true`,
  `current_state=blocked_before_local_live_capture`,
  `ready_for_local_live_capture=false`, `goal_complete=false`,
  `user_authorization_required=true`, and `user_secret_required=true`.
- Environment policy reported local root `https://app.weline.test:9523`,
  local endpoint `https://app.weline.test:9523/api/v1/platform/module/list`,
  production root `https://app.aiweline.com`, production endpoint
  `https://app.aiweline.com/api/v1/platform/module/list`, and forbidden roots
  including `https://www.aiweline.com`.
- Operator sequence keeps review commands runnable now, while
  `local_live_capture_after_blockers_clear` and
  `production_live_capture_after_launch` remain `safe_to_run_now=false`.
- Acceptance contract includes `captured_valid=true`,
  `tool_results.final_evidence_gate.ready=true`, production deploy-current
  source for `app.aiweline.com`, conclusive `module:wls-extra` negative canary,
  and `no_secret_values_in_evidence`.
- Source summary still reports `completion_open_rows=3`, `drifted_count=36`,
  local platform URL `https://app.weline.test:9523`, and production platform
  URL `https://app.aiweline.com`.
- Aggregate final preflight now mirrors the new workorder gate with
  `checks.final_workorder_self_test_passed=true`,
  `summary.final_workorder_self_test_passed=true`, and
  `tool_results.final_workorder_self_test.passed=true`.
- No App checkout sync, manifest/source write, WLS start, token read, or live
  AppStore network request was performed.

## 2026-06-23 Final Live Evidence Gate For Captured Marketplace Proof

Scope:

- Added `tools/wls-panel-live-evidence-final-gate.php` as a read-only final
  acceptance gate for captured local/production AppStore typed-tag live E2E
  JSON.
- The gate delegates to `validate-appstore-live-e2e-evidence.php`, but final
  acceptance requires capture-wrapper provenance: raw runner output is rejected,
  missing `capture_metadata` is rejected, and the endpoint must match the
  requested environment.
- Updated final preflight so it runs the final gate self-test and exposes
  `live_e2e_final_gate_self_test_passed` in `checks`, `summary`, and
  `tool_results`.
- Updated completion audit, index, final acceptance runbook, completion matrix,
  and traceability so local development keeps using
  `https://app.weline.test:9523` while deployed/production checks are accepted
  only through deployed `var/deploy/current.json` resolving to
  `https://app.aiweline.com`.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-evidence-final-gate.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-evidence-final-gate.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-evidence-final-gate.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
```

Results:

- PHP lint passed for the new final evidence gate, aggregate final preflight,
  and completion audit tools. The local PHP CLI still emits duplicate-extension
  and OPcache warnings before normal output.
- Final evidence gate self-test returned `passed=true` with cases
  `accepts_valid_local_capture_wrapper_evidence`,
  `rejects_raw_runner_payload_for_final_gate`,
  `rejects_missing_capture_metadata`, and
  `rejects_wrong_endpoint_for_environment`.
- Final evidence gate `--report-only=1` returned `ok=true`, `ready=false`,
  `environment=local`, missing
  `var\wls-panel-plan\local-appstore-live-e2e.json`, and
  `local_evidence_not_accepted`, with side effects
  `read-only final evidence gate: no network, no token, no WLS start, no writes`.
- Final preflight returned `ok=true`,
  `ready_for_live_local_appstore_e2e=false`, `goal_complete=false`,
  `tools_parsed=true`, `live_e2e_final_gate_self_test_passed=true`,
  local endpoint `https://app.weline.test:9523/api/v1/platform/module/list`,
  production endpoint `https://app.aiweline.com/api/v1/platform/module/list`,
  and `blocked_preflight_no_evidence_files=true`.
- Completion audit returned `ok=true`, `complete=false`, with 13 of 14
  completion rows proven, 20 of 22 traceability rows proven, `open_rows_total=3`,
  and first incomplete requirement
  `WLS marketplace installs WLS-specific plugins through typed meta tags.`.
- No App checkout sync, manifest/source write, WLS start, token read, or live
  AppStore network request was performed.

## 2026-06-23 Live Gate Endpoint Self-Test Gate

Scope:

- Added in-memory `--self-test=1` coverage to the local and production
  typed-tag live gate wrappers.
- The local wrapper now proves the locked development App Store endpoint,
  `manual_endpoint_rejected`, `local_insecure_disabled`, readiness blocking,
  and conclusive negative-canary live args before any local API call is
  allowed.
- The production wrapper now proves deployment-derived endpoint forwarding,
  `manual_endpoint_rejected`, `production_insecure_disabled`, token blocking,
  and conclusive negative-canary live args before any production API call is
  allowed.
- The aggregate final preflight now promotes those results as
  `local_live_gate_self_test_passed` and
  `production_live_gate_self_test_passed`, including `case_count=6` for each.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-typed-tag-live-gate.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\production-appstore-typed-tag-live-gate.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-typed-tag-live-gate.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\production-appstore-typed-tag-live-gate.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-typed-tag-live-gate.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\production-appstore-typed-tag-live-gate.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --report-only=1
git diff --check -- app/code/Weline/Server/doc/wls-panel-plan
```

Results:

- PHP lint passed for both live-gate wrappers, final preflight, and completion
  audit. The local PHP CLI still emits duplicate extension and OPcache warnings
  before normal output.
- Local live-gate self-test returned `passed=true`, `case_count=6`, and side
  effects `in-memory self-test: no file read, no network, no token, no WLS
  start, no writes`.
- Production live-gate self-test returned `passed=true`, `case_count=6`, and
  the same no-side-effect statement.
- Final preflight returned `ok=true`,
  `ready_for_live_local_appstore_e2e=false`, `goal_complete=false`,
  `local_live_gate_self_test_passed=true`,
  `local_live_gate_self_test_case_count=6`,
  `production_live_gate_self_test_passed=true`,
  `production_live_gate_self_test_case_count=6`,
  local endpoint `https://app.weline.test:9523/api/v1/platform/module/list`,
  and production endpoint `https://app.aiweline.com/api/v1/platform/module/list`.
- Local live-gate report-only output stayed blocked with
  `live_executed=false`, `guard_passed=true`,
  `manual_endpoint_rejected=true`, `local_insecure_disabled=true`, and the
  endpoint locked to `https://app.weline.test:9523/api/v1/platform/module/list`.
- Production live-gate report-only output stayed blocked with
  `live_executed=false`, `guard_passed=true`,
  `manual_endpoint_rejected=true`, `production_insecure_disabled=true`, and
  the endpoint locked to
  `https://app.aiweline.com/api/v1/platform/module/list`.
- Completion audit returned `ok=true`, `complete=false`, with 13 of 14
  completion rows proven and the WLS typed-tag marketplace live E2E row still
  incomplete.
- `git diff --check -- app/code/Weline/Server/doc/wls-panel-plan` exited `0`
  with only existing LF-to-CRLF warnings.
- No App checkout sync, manifest/source write, WLS start, token read, or live
  AppStore network request was performed.

## 2026-06-23 Authorization Packet Live-Gate Self-Test Summary

Scope:

- Extended `wls-panel-live-e2e-authorization-pack.php` so the human review
  packet requires final preflight proof that both local and production
  live-gate wrappers passed their endpoint self-tests.
- The packet now exposes `local_live_gate_self_test_passed`,
  `production_live_gate_self_test_passed`, and
  `live_gate_self_test_case_counts_ok` as top-level `checks`.
- The packet also exposes
  `tool_results.final_preflight.live_gate_self_tests`, including local and
  production pass status plus case counts.
- The packet self-test now covers both the positive case where both wrappers
  and six-case counts are present and a negative case where production wrapper
  coverage is missing.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php --fail-if-unsafe=1
```

Results:

- PHP lint passed for `wls-panel-live-e2e-authorization-pack.php`. The local
  PHP CLI still emits duplicate extension and OPcache warnings before normal
  output.
- Authorization packet self-test returned `passed=true`; the new cases
  `live_gate_self_tests_require_both_wrappers_and_case_counts` and
  `live_gate_self_tests_reject_missing_production_wrapper` both returned
  `case_ok=true`.
- Authorization packet `--fail-if-unsafe=1` returned
  `authorization_pack_ready_for_review=true`,
  `current_state=blocked_before_live_run`,
  `local_live_gate_self_test_passed=true`,
  `production_live_gate_self_test_passed=true`, and
  `live_gate_self_test_case_counts_ok=true`.
- The packet remained read-only: no App checkout sync, manifest/source write,
  WLS start, token read, or live AppStore network request was performed.

## 2026-06-23 Live Evidence Capture Metadata Gate

Scope:

- Extended `wls-panel-live-e2e-capture.php` so any evidence written by the
  capture wrapper includes root-level `capture_metadata`.
- The metadata records schema, capture tool, UTC timestamp, environment,
  source live-gate wrapper, endpoint, and whether the evidence output path is
  inside `var\wls-panel-plan`.
- Extended `validate-appstore-live-e2e-evidence.php` so raw runner evidence is
  still accepted for parser coverage, but capture-wrapper evidence is rejected
  unless `capture_metadata_present`, `capture_metadata_source_gate`,
  environment, endpoint, inside-var flag, and UTC timestamp are all valid.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-capture.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-live-e2e-evidence.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-capture.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-live-e2e-evidence.php --self-test=1
```

Results:

- PHP lint passed for both capture and validator tools. The local PHP CLI still
  emits duplicate extension and OPcache warnings before normal output.
- Capture wrapper self-test returned `passed=true`; the new
  `captured_payload_has_metadata` case returned `case_ok=true`.
- Evidence validator self-test returned `passed=true`; it still accepts local
  raw runner evidence, accepts production wrapper evidence with metadata, and
  the new `rejects_wrapper_missing_capture_metadata` case returned
  `case_ok=true` with failures for `capture_metadata_present`,
  `capture_metadata_schema`, `capture_metadata_tool`,
  `capture_metadata_timestamp_utc`, `capture_metadata_environment`,
  `capture_metadata_source_gate`, `capture_metadata_endpoint_exact`, and
  `capture_metadata_output_inside_var`.
- No App checkout sync, manifest/source write, WLS start, token read, evidence
  file write, or live AppStore network request was performed.

## 2026-06-23 Machine Summary Field Gate

Scope:

- Added stable machine-readable summary fields to the final preflight and
  completion audit so CI and operator scripts do not need to infer state from
  command text.
- `wls-panel-final-preflight.php` now mirrors
  `ready_for_live_local_appstore_e2e` and `goal_complete` into `summary`.
- The final preflight also exposes the local App checkout sync action contract
  under `checks` and `summary.readiness_action_authorized_sync`, including
  sync self-test, compact drift preflight, authorization review, rollback
  review, and post-sync gate booleans.
- `wls-panel-completion-audit.php` now emits `summary` with completion matrix
  totals, proven counts, open-row count, and first incomplete requirement. It
  also exposes stable aliases: `completion_total`,
  `completion_proven_count`, `traceability_total`, and
  `traceability_proven_count`.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
```

Expected assertions:

- Final preflight keeps `ok=true`,
  `ready_for_live_local_appstore_e2e=false`, and `goal_complete=false` while
  the local AppStore live E2E remains blocked.
- Final preflight reports `summary.ready_for_live_local_appstore_e2e=false`,
  `summary.goal_complete=false`, and
  `summary.readiness_action_plan_contract_ok=true`.
- Final preflight reports
  `checks.readiness_action_has_sync_manifest_self_test=true`,
  `checks.readiness_action_has_compact_drift_preflight=true`,
  `checks.readiness_action_has_authorization_review=true`,
  `checks.readiness_action_has_rollback_review=true`, and
  `checks.readiness_action_has_post_sync_gate=true`.
- Completion audit reports `summary.open_rows_total=3` and
  `summary.first_incomplete_requirement` naming the marketplace typed-tag
  live-E2E gate. Automation may also read `summary.completion_total`,
  `summary.completion_proven_count`, `summary.traceability_total`, and
  `summary.traceability_proven_count` without scraping legacy field names.
- No App checkout sync, manifest/source write, WLS start, token read, or live
  AppStore network request is part of this gate.

## 2026-06-23 Completion Audit Summary Alias Gate

Scope:

- Added stable alias fields to `wls-panel-completion-audit.php`:
  `summary.completion_total`, `summary.completion_proven_count`,
  `summary.traceability_total`, and `summary.traceability_proven_count`.
- Existing fields such as `completion_matrix_total`,
  `completion_proven_rows`, `traceability_matrix_total`, and
  `traceability_proven_rows` remain present for backward compatibility.
- This is a read-only reporting improvement for CI/operator consumers; it does
  not change the completion decision.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php --fail-on-incomplete=1
```

Expected assertions:

- Default completion audit returns `ok=true`, `complete=false`,
  `summary.completion_total=14`, `summary.completion_proven_count=13`,
  `summary.traceability_total=22`,
  `summary.traceability_proven_count=20`, and
  `summary.open_rows_total=3`.
- `--fail-on-incomplete=1` returns exit code `1` while preserving
  `ok=true`, `complete=false`, and the same summary counts.
- No App checkout sync, manifest/source write, WLS start, token read, or live
  AppStore network request is part of this gate.

## 2026-06-23 Official Catalog Summary Gate

Scope:

- Added a read-only `catalog_summary` to
  `validate-official-appstore-manifest-contract.php --template=1`.
- The summary proves the generated AppStore manifest template contains the four
  expected positive WLS plugin modules and one strict negative canary before
  any authorized manifest/source write.
- The final preflight now promotes that result into
  `checks.official_manifest_template_catalog_contract_ok` and
  `summary.official_manifest_catalog_summary`.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php --template=1 --template-target="E:\WelineFramework\Framework-Official\App\weline\official-apps\manifest.json"
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
```

Expected assertions:

- Manifest template mode returns `passed=true`,
  `catalog_summary.contract_ok=true`, `app_count=5`,
  `positive_count=4`, `negative_canary_count=1`, and
  `source_entry_count=5`.
- Final preflight returns
  `checks.official_manifest_template_catalog_contract_ok=true`,
  `checks.official_manifest_template_catalog_app_count_ok=true`,
  `checks.official_manifest_template_catalog_positive_count_ok=true`,
  `checks.official_manifest_template_catalog_canary_ok=true`, and
  `checks.official_manifest_template_catalog_source_plan_ok=true`.
- Completion audit remains `ok=true`, `complete=false`, with the same three
  marketplace live-E2E open rows.
- No App checkout sync, manifest/source write, WLS start, token read, or live
  AppStore network request is part of this gate.

## 2026-06-23 Authorization Packet Catalog Summary Gate

Scope:

- Extended `wls-panel-live-e2e-authorization-pack.php` so the human review
  packet exposes the final preflight official manifest catalog summary.
- The packet now checks `official_manifest_catalog_contract_ok`,
  `official_manifest_catalog_app_count_ok`,
  `official_manifest_catalog_positive_count_ok`,
  `official_manifest_catalog_canary_ok`, and
  `official_manifest_catalog_source_plan_ok` before it can be marked safe for
  review.
- This keeps the local development AppStore target locked to
  `https://app.weline.test:9523`, while deployed production live proof remains
  locked to `https://app.aiweline.com` from deployment metadata.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php --include-drift-rows=1 --include-rollback-review=1 --fail-if-unsafe=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
```

Expected assertions:

- Authorization packet returns `authorization_pack_ready_for_review=true`,
  `current_state=blocked_before_live_run`,
  `local_endpoint_exact_root=true`, and
  `production_endpoint_exact_root=true`.
- Authorization packet returns `official_manifest_catalog_contract_ok=true`,
  `official_manifest_catalog_source_plan_ok=true`, and exposes
  `tool_results.final_preflight.official_manifest_catalog_summary`.
- The exposed catalog summary proves `app_count=5`, `positive_count=4`,
  `negative_canary_count=1`, and `source_entry_count=5`.
- Final preflight still returns `ready_for_live_local_appstore_e2e=false` and
  `goal_complete=false` until the authorized App checkout sync, App setup,
  `app.weline.test:9523` WLS startup, bearer token, and local live typed-tag
  evidence exist.
- No App checkout sync, manifest/source write, WLS start, token read, or live
  AppStore network request is part of this gate.

## 2026-06-23 Readiness Action Plan Command Chain

Scope:

- Extended `local-appstore-readiness-probe.php --action-plan-only=1` so the
  `authorized_app_checkout_sync` action exposes the full pre-sync review chain:
  sync-manifest self-test, compact drift preflight, drift+rollback
  authorization packet, rollback review command, and post-sync drift gate.
- This makes the next operator step machine-readable without authorizing or
  executing the `分项` sync.

Commands:

```powershell
php app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php --action-plan-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
```

Results:

- Readiness action plan returned `ready=false` with blockers
  `app_schema_has_sqlite_composite_pk_guard`, `app_port_listening`,
  `official_manifest_readable`, `official_manifest_has_wls_positive`,
  `official_manifest_has_negative_canary`,
  `official_manifest_negative_canary_exact`, and `bearer_token_env_present`.
- The `authorized_app_checkout_sync` action returned `safe_to_run=false` and
  now includes:
  `preflight_self_test_command=php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php --self-test=1`,
  `preflight_command=php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php --with-drift=1 --drift-summary-only=1`,
  `pre_authorization_review_command=php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php --include-drift-rows=1 --include-rollback-review=1 --fail-if-unsafe=1`,
  `rollback_review_command=php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php --with-drift=1 --rollback-review=1`, and
  `post_sync_gate_command=php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php --fail-on-drift=1 --drift-summary-only=1`.
- Final preflight returned `ok=true`,
  `ready_for_live_local_appstore_e2e=false`, `goal_complete=false`,
  `sync_manifest_self_test_passed=true`, the same action-review and
  rollback-review command fields, `drifted_count=36`, local endpoint
  `https://app.weline.test:9523/api/v1/platform/module/list`, and production
  endpoint `https://app.aiweline.com/api/v1/platform/module/list`.
- PHP lint passed for `local-appstore-readiness-probe.php`. The local PHP CLI
  still emits duplicate extension and OPcache warnings before normal output.
- Sync manifest self-test returned `passed=true` with six cases.
- Completion audit returned `ok=true`, `complete=false`, with 13 of 14
  completion rows proven and one marketplace live E2E row still incomplete.
- No App checkout sync, manifest/source write, WLS start, token read, or live
  AppStore network request was performed.

## 2026-06-23 Sync Manifest Self-Test Gate

Scope:

- Added `--self-test=1` to the local AppStore sync manifest checker.
- The self-test is in-memory only and proves rollback-review status parsing,
  rename target normalization, out-of-scope fingerprinting, forbidden-prefix
  detection, and broad-include rejection.
- The aggregate final preflight now includes this result as
  `sync_manifest_self_test_passed`.

Commands:

```powershell
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php --include-drift-rows=1 --include-rollback-review=1 --fail-if-unsafe=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
```

Results:

- Sync manifest self-test returned `passed=true` with `case_count=6` and
  side effects `in-memory self-test: no App checkout read, no git process, no
  sync, no setup, no WLS start, no writes`.
- Final preflight returned `ok=true`,
  `ready_for_live_local_appstore_e2e=false`, `goal_complete=false`,
  `sync_manifest_self_test_passed=true`, `tools_parsed=true`,
  `drifted_count=36`, local URL `https://app.weline.test:9523`, and production
  URL `https://app.aiweline.com`.
- Authorization packet with drift rows and rollback review remained safe for
  review: `current_state=blocked_before_live_run`,
  `rollback_review_parsed=true`, `drift_rows_count=36`,
  App checkout git `exit_code=0`, `out_of_scope_status=2`,
  `out_of_scope_fingerprint=0863c8ebd5abef29`, and `no_secret_values=true`.
- Completion audit returned `ok=true`, `complete=false`, with 13 of 14
  completion rows proven and one marketplace live E2E row still incomplete.
- No App checkout sync, manifest/source write, WLS start, token read, or live
  AppStore network request was performed.

## 2026-06-23 Final Workorder Deployment-Info Contract Self-Test

Scope:

- Hardened `tools/wls-panel-final-workorder.php --self-test=1` so the final
  workorder generator itself must preserve the final evidence acceptance
  contract.
- The new self-test case is
  `acceptance_contract_preserves_final_evidence_invariants`.
- This locks the local development marketplace proof to
  `https://app.weline.test:9523` and locks deployed/production proof to
  `appstore_platform_url=https://app.aiweline.com` read from
  `var/deploy/current.json`.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-workorder.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-workorder.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-final-workorder-deferred-actions.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-final-workorder-deferred-actions.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-goal-completion-gate.php
git diff --check -- app/code/Weline/Server/doc/wls-panel-plan
```

Results:

- PHP lint passed for `wls-panel-final-workorder.php`. The local PHP CLI still
  emits duplicate extension and OPcache warnings before normal output.
- Final workorder self-test returned `passed=true` with eight in-memory cases,
  including `acceptance_contract_preserves_final_evidence_invariants=true`.
- Deferred-action validator self-test returned `passed=true` with nine
  in-memory cases, including the missing-acceptance-invariant and missing
  deploy-current negative cases.
- Live deferred-action validation returned `passed=true` with
  `acceptance_contract_has_required_invariants=true`,
  `local_capture_requires_reviewed_appstore_prerequisites=true`,
  `production_capture_uses_deploy_current=true`, and
  `production_capture_requires_deployed_app_aiweline=true`.
- Final preflight returned `ok=true`,
  `ready_for_live_local_appstore_e2e=false`, `goal_complete=false`,
  `final_workorder_self_test_passed=true`, local deploy platform URL
  `https://app.weline.test:9523`, and production deploy platform URL
  `https://app.aiweline.com`.
- Strict goal completion gate exited `1` as expected with `complete=false`.
  The remaining blockers are the incomplete marketplace typed-tag live E2E row
  plus missing captured evidence files:
  `var\wls-panel-plan\local-appstore-live-e2e.json` and
  `var\wls-panel-plan\production-appstore-live-e2e.json`.
- `git diff --check` exited zero, with existing LF-to-CRLF normalization
  warnings only.
- No App checkout sync, manifest/source write, WLS start, token read, or live
  AppStore network request was performed.

## 2026-06-23 Runtime AppStore Official-Website Host Guard

Scope:

- Hardened `Weline\AppStore\Service\AppStorePlatformUrlResolver` so local
  `WELINE_APPSTORE_PLATFORM_URL` / `appstore.platform_url` and production
  `var/deploy/current.json` values are ignored when they point at known
  official `www.*` website hosts instead of the App Store marketplace root.
- Hardened WLS Panel's standalone fallback resolver the same way, so the panel
  keeps using the local App Store or production App Store root even if AppStore
  is disabled or mid-upgrade.
- Hardened `DeployOrchestratorService` so local deploy-current metadata is not
  written with an official website host when env/config is misconfigured.
- Extended `tools/validate-appstore-endpoint-source-contract.php` with
  `deploy_rejects_official_website_marketplace_sources`,
  `resolver_rejects_official_website_marketplace_sources`, and
  `panel_fallback_rejects_official_website_marketplace_sources`.

Commands:

```powershell
gitnexus impact AppStorePlatformUrlResolver -r dev-workspace -d upstream --depth 1
gitnexus impact resolveLocalPlatformUrl -r dev-workspace -d upstream --depth 1
gitnexus impact resolveFallbackLocalAppStorePlatformResolution -r dev-workspace -d upstream --depth 1
gitnexus impact resolveAppStoreMarketplaceInfo -r dev-workspace -d upstream --depth 1
php -l app\code\Weline\AppStore\Service\AppStorePlatformUrlResolver.php
php -l app\code\Weline\Server\Controller\Backend\WlsPanel.php
php -l app\code\Weline\Deploy\Service\DeployOrchestratorService.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-endpoint-source-contract.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-endpoint-source-contract.php
php vendor\bin\phpunit app\code\Weline\AppStore\test\Unit\AppStorePlatformUrlResolverTest.php
```

Results:

- GitNexus reported all four target symbols as `Target not found`, so the
  change was validated with focused source review, lint, source contract, and
  resolver behavior checks.
- PHP lint passed for the AppStore resolver, WLS Panel controller, Deploy
  orchestrator, and source-contract gate. The local PHP CLI still emits
  duplicate extension and OPcache warnings before normal output.
- Source contract passed and now reports the three official-website host guard
  checks as true.
- Existing AppStore resolver PHPUnit coverage passed: 3 tests, 9 assertions.
- A framework-bootstrap smoke snippet proved
  `WELINE_APPSTORE_PLATFORM_URL=https://www.weline.test:9518` in local mode
  resolves to `https://app.weline.test:9523`, and production
  `current.json` with a `www.*` website URL resolves back to
  `https://app.aiweline.com`. Final live evidence still requires deployed
  `current.json` to explicitly record `appstore_platform_url=https://app.aiweline.com`;
  this runtime guard is resilience, not completion evidence.

## 2026-06-23 Live Evidence Metadata Output Path Match Self-Test

Scope:

- Hardened `tools/wls-panel-live-e2e-capture.php` so captured wrapper evidence
  now writes `capture_metadata.evidence_output_path` alongside the endpoint,
  endpoint source, inside-var flag, and UTC capture timestamp.
- Hardened `tools/validate-appstore-live-e2e-evidence.php` so capture-wrapper
  evidence must prove `capture_metadata.evidence_output_path` matches the
  actual `--evidence` file being validated.
- Hardened `tools/wls-panel-live-evidence-final-gate.php` so final acceptance
  requires the validator's `capture_metadata_output_path_present` and
  `capture_metadata_output_path_matches_file` checks before a captured proof can
  be accepted.
- Added validator self-test case `rejects_wrapper_output_path_mismatch`, so a
  copied wrapper payload with stale metadata cannot be accepted as final live
  evidence for another evidence file.
- Added final-gate self-test case
  `rejects_missing_validator_output_path_match`, so the last acceptance gate
  rejects wrapper evidence when the validator did not prove that metadata path
  matches the actual evidence file.

Commands:

```powershell
gitnexus impact wlsPanelCaptureEvidencePayload -r dev-workspace -d upstream --depth 1
gitnexus impact wlsPanelLiveEvidenceEvaluate -r dev-workspace -d upstream --depth 1
gitnexus impact wlsPanelLiveEvidenceSelfTest -r dev-workspace -d upstream --depth 1
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-live-e2e-evidence.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-capture.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-evidence-final-gate.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-live-e2e-evidence.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-capture.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-evidence-final-gate.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-goal-completion-gate.php
git diff --check -- app/code/Weline/Server/doc/wls-panel-plan
```

Results:

- GitNexus reported all three target symbols as `Target not found`, so this
  remains an unindexed doc/plan tool change validated by focused diff and
  self-tests.
- PHP lint passed for all three touched tools. The local PHP CLI still emits
  duplicate extension and OPcache warnings before normal output.
- Live evidence validator self-test returned `passed=true` with eight
  in-memory cases, including `rejects_wrapper_output_path_mismatch`.
- Live capture wrapper self-test returned `passed=true` with eleven in-memory
  cases, and both local and production captured metadata now include the
  normalized evidence output path.
- Final evidence gate self-test returned `passed=true` with nine in-memory
  cases, including `rejects_missing_validator_output_path_match`.
- Final preflight returned `ok=true`, `ready_for_live_local_appstore_e2e=false`,
  and `goal_complete=false`.
- Strict goal completion gate exited `1` as expected with `complete=false`;
  the remaining blockers are still the missing captured local and production
  live evidence files.
- `git diff --check` exited zero, with existing LF-to-CRLF normalization
  warnings only.
- No App checkout sync, manifest/source write, WLS start, token read, or live
  AppStore network request was performed.

## 2026-06-23 Goal Gate Final Evidence Inside-Var Self-Test

Scope:

- Hardened `tools/wls-panel-goal-completion-gate.php` so final completion now
  requires `inside_var=true` from both local and production final evidence
  gates, in addition to canonical captured evidence paths.
- The blocked output now includes the current `inside_var` value for local and
  production evidence so operators can tell whether a final-gate failure came
  from missing evidence or an evidence path outside `var/wls-panel-plan/`.
- Added self-test cases `rejects_local_final_gate_outside_var` and
  `rejects_production_final_gate_outside_var`.

Commands:

```powershell
gitnexus impact wlsPanelGoalCompletionRun -r dev-workspace -d upstream --depth 1
gitnexus impact wlsPanelGoalCompletionSelfTest -r dev-workspace -d upstream --depth 1
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-goal-completion-gate.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-goal-completion-gate.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-goal-completion-gate.php
git diff --check -- app/code/Weline/Server/doc/wls-panel-plan
```

Results:

- GitNexus reported both target symbols as `Target not found`, so this remains
  an unindexed doc/plan tool change validated by focused diff and self-tests.
- PHP lint passed for `wls-panel-goal-completion-gate.php`. The local PHP CLI
  still emits duplicate extension and OPcache warnings before normal output.
- Goal completion gate self-test returned `passed=true` with eleven in-memory
  cases, including both new outside-var rejection cases.
- Strict goal completion gate exited `1` as expected with `complete=false`.
  The new `local_final_gate_inside_var` and
  `production_final_gate_inside_var` checks reflect the final evidence gate
  payload, while the remaining blockers are still the missing captured local
  and production live evidence files.
- `git diff --check` exited zero, with existing LF-to-CRLF normalization
  warnings only.
- No App checkout sync, manifest/source write, WLS start, token read, or live
  AppStore network request was performed.

## 2026-06-23 Goal Gate Final-Gate Environment Scope Self-Test

Scope:

- Hardened `tools/wls-panel-goal-completion-gate.php` so final completion now
  requires the local final-gate payload to declare `environment=local`,
  `required_environments=["local"]`, and only a `results.local` entry.
- Production final completion now likewise requires `environment=production`,
  `required_environments=["production"]`, and only a `results.production`
  entry.
- Added self-test cases:
  `rejects_local_final_gate_with_both_environment_scope` and
  `rejects_production_final_gate_with_both_environment_scope`.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-goal-completion-gate.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-goal-completion-gate.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-evidence-final-gate.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-goal-completion-gate.php
git diff --check -- app/code/Weline/Server/doc/wls-panel-plan
```

Results:

- PHP lint passed for `wls-panel-goal-completion-gate.php`. The local PHP CLI
  still emits duplicate extension and OPcache warnings before normal output.
- Goal completion gate self-test returned `passed=true` with nine in-memory
  cases, including both-environment final-gate scope rejection for local and
  production completion.
- Live evidence final gate self-test remained `passed=true`.
- Final preflight returned `ok=true`, `goal_complete=false`, and
  `goal_completion_gate_self_test_passed=true`.
- Strict goal completion gate exited `1` as expected with `complete=false`.
  The new `local_final_gate_environment_scope` and
  `production_final_gate_environment_scope` checks were true for the current
  default final-gate invocations, while completion remained blocked only
  because the captured live evidence files do not exist yet.
- `git diff --check` exited zero, with existing LF-to-CRLF normalization
  warnings only.
- No App checkout sync, manifest/source write, WLS start, token read, or live
  AppStore network request was performed.

## 2026-06-23 Goal Gate Evidence Path Isolation Self-Test

Scope:

- Hardened `tools/wls-panel-goal-completion-gate.php` so final completion now
  requires canonical captured evidence paths for both environments:
  `var/wls-panel-plan/local-appstore-live-e2e.json` and
  `var/wls-panel-plan/production-appstore-live-e2e.json`.
- The goal gate also requires the local and production evidence paths to be
  distinct before the WLS Panel goal can be marked complete.
- Added self-test cases:
  `rejects_swapped_local_production_final_gate_payloads` and
  `rejects_local_evidence_using_production_path`.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-goal-completion-gate.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-goal-completion-gate.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-evidence-final-gate.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-goal-completion-gate.php
git diff --check -- app/code/Weline/Server/doc/wls-panel-plan
```

Results:

- PHP lint passed for `wls-panel-goal-completion-gate.php`. The local PHP CLI
  still emits duplicate extension and OPcache warnings before normal output.
- Goal completion gate self-test returned `passed=true` with seven in-memory
  cases, including swapped local/production final-gate payload rejection and
  local evidence using the production capture path rejection.
- Live evidence final gate self-test remained `passed=true`.
- Final preflight returned `ok=true`, `goal_complete=false`, and
  `goal_completion_gate_self_test_passed=true`.
- Strict goal completion gate exited `1` as expected with `complete=false`.
  The new canonical path checks were true for the default local and production
  evidence paths, while completion remained blocked only because the captured
  evidence files do not exist yet.
- `git diff --check` exited zero, with existing LF-to-CRLF normalization
  warnings only.
- No App checkout sync, manifest/source write, WLS start, token read, or live
  AppStore network request was performed.

## 2026-06-23 Live Capture Production Metadata Self-Test

Scope:

- Hardened `tools/wls-panel-live-e2e-capture.php --self-test=1` so the live
  capture wrapper must preserve deployment-info provenance in the captured
  evidence payload.
- The new self-test case is
  `production_captured_payload_uses_deploy_current_metadata`.
- This keeps local development capture on
  `https://app.weline.test:9523/api/v1/platform/module/list` with
  `endpoint_source=deploy-current:tools/deploy-current-local-development.json`,
  while production capture must use
  `https://app.aiweline.com/api/v1/platform/module/list` with
  `endpoint_source=deploy-current:var/deploy/current.json`.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-capture.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-capture.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-live-e2e-evidence.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-evidence-final-gate.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-endpoint-source-contract.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --expect=production
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-local-development.json --expect=local
php app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --resolve-endpoint-only=1
git diff --check -- app/code/Weline/Server/doc/wls-panel-plan
```

Results:

- PHP lint passed for `wls-panel-live-e2e-capture.php`. The local PHP CLI still
  emits duplicate extension and OPcache warnings before normal output.
- Live capture self-test returned `passed=true` with eleven in-memory cases,
  including
  `production_captured_payload_uses_deploy_current_metadata=true`.
- AppStore live evidence validator self-test returned `passed=true` and still
  rejects production wrapper evidence without deploy-current provenance.
- Final evidence gate self-test returned `passed=true` and still rejects
  non-deploy-current production endpoint sources.
- Final preflight returned `ok=true`,
  `ready_for_live_local_appstore_e2e=false`, and `goal_complete=false`.
- Deployment-info source contract returned `passed=true`: release current
  metadata writes include `appstore_platform_url`, the resolver reads deployed
  production `var/deploy/current.json`, WLS Panel exposes the resolver state,
  and the checked source has no `www.*` marketplace host.
- Deploy endpoint policy returned `passed=true` for production
  `appstore_platform_url=https://app.aiweline.com` and local
  `appstore_platform_url=https://app.weline.test:9523`.
- Endpoint resolution-only check returned `passed=true` for production and
  resolved to `https://app.aiweline.com/api/v1/platform/module/list` from the
  provided deployment-current artifact, with token redacted and no live request.
- `git diff --check` exited zero, with existing LF-to-CRLF normalization
  warnings only.
- No App checkout sync, manifest/source write, WLS start, token read, or live
  AppStore network request was performed.

## 2026-06-23 Live Final Gate Evidence Path Scope Self-Test

Scope:

- Hardened `tools/wls-panel-live-evidence-final-gate.php` so custom local or
  production evidence paths must stay under the current workspace
  `var/wls-panel-plan/` directory.
- The final gate now rejects path traversal and outside-var evidence paths
  before accepting captured local or production AppStore live proof.
- The result payload exposes `inside_var` for each environment so downstream
  completion checks can verify the evidence path stayed within the controlled
  WLS Panel evidence directory.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-evidence-final-gate.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-evidence-final-gate.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-goal-completion-gate.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-goal-completion-gate.php
git diff --check -- app/code/Weline/Server/doc/wls-panel-plan
```

Results:

- PHP lint passed for `wls-panel-live-evidence-final-gate.php`. The local PHP
  CLI still emits duplicate extension and OPcache warnings before normal
  output.
- Live evidence final gate self-test returned `passed=true` with eight
  in-memory cases, including `rejects_path_traversal_outside_var` and
  `rejects_evidence_path_outside_var`.
- Goal completion gate self-test returned `passed=true` with nine in-memory
  cases.
- Final preflight returned `ok=true`, `goal_complete=false`, and
  `live_e2e_final_gate_self_test_passed=true`.
- Strict goal completion gate exited `1` as expected with `complete=false`.
  The new path-scope checks did not add new completion blockers; the remaining
  blockers are still the missing captured local and production live evidence
  files.
- `git diff --check` exited zero, with existing LF-to-CRLF normalization
  warnings only.
- No App checkout sync, manifest/source write, WLS start, token read, or live
  AppStore network request was performed.

## 2026-06-23 Local AppStore Sync Gate Coverage Tightening

Scope:

- Extended `92-local-appstore-sync-manifest.md` so a future authorized local
  App checkout sync includes the full WLS Panel marketplace gate chain, not only
  the early readiness scripts.
- Added explicit sync coverage for local and production typed-tag live gates,
  endpoint source contract, live evidence validator, capture wrapper, final
  evidence gate, final work order, deferred-action validator, and goal
  completion gate.
- Hardened `tools/validate-local-appstore-sync-manifest.php` so those gate
  files are required in both `Allowed Sync Paths` and the passphrase-gated
  `-IncludePaths` command shape.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php --with-drift=1 --drift-summary-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
```

Results:

- PHP lint passed for `validate-local-appstore-sync-manifest.php`. The local PHP
  CLI still emits duplicate extension and OPcache warnings before normal output.
- Manifest validator self-test returned `passed=true`.
- Manifest validator returned `ok=true` with `allowed_path_count=46`,
  `include_path_count=46`, `required_sync_path_count=10`, and no errors or
  warnings.
- Read-only drift mode returned `ok=true` and `app_checkout_drift_detected:46`.
  This is expected before authorization because the App checkout has not been
  synced; it confirms the newly required gate files will be visible in the
  pre-sync drift review.
- Final preflight returned `ok=true`,
  `ready_for_live_local_appstore_e2e=false`, and `goal_complete=false`.
- No App checkout sync, manifest/source write, WLS start, token read, or live
  AppStore network request was performed.

## 2026-06-23 Deploy Current AppStore Source Lock

Scope:

- Tightened `tools/validate-deploy-appstore-endpoint-policy.php` so endpoint
  correctness now includes the deployment-recorded source of
  `appstore_platform_url`, not only the final resolved URL.
- Production deployment metadata must keep
  `appstore_platform_url_source=production_default`; this proves deployed tests
  are using the deployment-owned `var/deploy/current.json` production default
  and not a leftover local env/config value.
- Local development metadata remains allowed only through `local_default`,
  `env:WELINE_APPSTORE_PLATFORM_URL`, or `config:appstore.platform_url`, all of
  which resolve to the local App Store root `https://app.weline.test:9523`.
- `www.weline.test:9518` and `www.aiweline.com` remain forbidden marketplace
  roots; they are official website endpoints, not App Store endpoints.

Commands:

```powershell
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-local-development.json --expect=local
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --expect=production
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-endpoint-source-contract.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-goal-completion-gate.php
```

Results:

- PHP lint passed for `validate-deploy-appstore-endpoint-policy.php`. The local
  PHP CLI still emits duplicate extension and OPcache warnings before normal
  output.
- Policy self-test returned `passed=true` and now includes negative cases for
  `production_rejects_config_source` and
  `local_rejects_unknown_appstore_source`.
- Local deploy-current fixture passed with
  `platform_url_source=local_default` and resolved endpoint
  `https://app.weline.test:9523/api/v1/platform/module/list`.
- Production deploy-current fixture passed with
  `platform_url_source=production_default` and resolved endpoint
  `https://app.aiweline.com/api/v1/platform/module/list`.
- Static endpoint source contract returned `passed=true`, proving
  `DeployOrchestratorService`, `AppStorePlatformUrlResolver`,
  `AccountBindService`, and the WLS Panel fallback share the same locked local
  and production marketplace rule.
- Final preflight returned `ok=true`,
  `deploy_endpoint_policy_passed=true`,
  `local_deploy_endpoint_policy_passed=true`,
  `production_deploy_endpoint_policy_passed=true`,
  `production_endpoint_locked=true`, and `goal_complete=false`.
- Strict goal completion gate still exited `1` as expected because the captured
  local and production live AppStore E2E evidence files are still missing.
- No App checkout sync, deploy current write, manifest/source write, WLS start,
  token read, or live AppStore network request was performed.

## 2026-06-23 Standalone Theme Host Attribute Sync

Scope:

- Verified the standalone WLS Panel theme fallback script in
  `view/templates/Backend/WlsPanel/index.phtml` directly from the source
  template.
- The inline theme path now mirrors the shared
  `view/statics/assets/js/wls-panel-plugins.js` host-attribute contract:
  `data-wls-panel-theme`, `data-theme-mode`, `data-bs-theme`,
  `data-sidebar`, `data-topbar`, and `color-scheme`.
- This keeps the independent WLS Panel shell, backend host attributes, and
  WLS plugin shells aligned even when the static plugin bridge is delayed or
  unavailable during panel startup.

Design constraints checked:

- `ui-ux-pro-max` dashboard/dark-mode guidance used for the evidence criteria:
  WLS Panel stays a data-dense operational dashboard, supports dark mode, keeps
  focus/contrast state meaningful, and does not rely on a decorative marketing
  layout.
- The source template remains the edited surface; compiled `view/tpl` output
  was not edited.

Commands:

```powershell
rg -n "syncHostThemeAttributes|data-theme-mode|data-bs-theme|data-sidebar|data-topbar|wls-panel-theme-change" app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml app/code/Weline/Server/view/statics/assets/js/wls-panel-plugins.js
php -l app\code\Weline\Server\view\templates\Backend\WlsPanel\index.phtml
@'
<node fake DOM script; extracts the inline theme IIFE from index.phtml and
asserts fresh/light, stored/dark, prefers-dark, and click-toggle states>
'@ | node -
curl.exe -k -I --max-time 8 --noproxy * "https://127.0.0.1:10043/U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8/server/backend/wls-panel/marketplace?tag=module%3Awls&surface=backend&q=wls-php-manager"
```

Results:

- Static lookup confirmed the source template and shared plugin bridge both
  carry the host theme attributes and `wls-panel-theme-change` event hook.
- PHP lint passed for `index.phtml`. The local PHP CLI still emits duplicate
  extension and OPcache warnings before normal output.
- Node fake-DOM execution returned `passed=true` while reading the real
  `index.phtml` inline theme IIFE. It proved:
  - fresh load starts `light`, sets `data-wls-theme-ready=1`, stores `light`,
    and synchronizes root/body theme attributes;
  - clicking the theme toggle switches to `dark`, updates
    `aria-pressed=true`, label text, localStorage, root/body attributes,
    `color-scheme`, and dispatches `wls-panel-theme-change` with
    `detail.theme=dark`;
  - stored `dark` loads as dark and toggles back to light;
  - `prefers-color-scheme: dark` loads as dark when no stored value exists.
- This validation is behavior-level but not a rendered browser proof. The
  current browser URL on `127.0.0.1:10043` failed to connect during a bounded
  `curl -I` reachability check, so no rendered browser claim is made for this
  slice and no WLS instance was started.
- No App checkout sync, deploy current write, manifest/source write, WLS start,
  token read, live AppStore request, or browser automation was performed.

## 2026-06-23 AppStore Deploy Metadata Endpoint Recheck

Scope:

- Rechecked the corrected marketplace split after the local AppStore checkout
  was identified as `E:\WelineFramework\Framework-Official\App\weline`.
- Local development remains pinned to
  `https://app.weline.test:9523/api/v1/platform/module/list`.
- Production/deployed checks must resolve from
  `deploy_root/var/deploy/current.json`; the deploy artifact must record
  `appstore_environment=production`,
  `appstore_platform_url=https://app.aiweline.com`, and
  `appstore_platform_url_source=production_default`.
- `www.weline.test:9518`, `www.aiweline.com`, manual production endpoints,
  insecure production mode, empty production URLs, local production URLs, and
  platform fields that store the full API path are rejected by the read-only
  gates before any token or live request is allowed.

Commands:

```powershell
php -l app\code\Weline\AppStore\Service\AppStorePlatformUrlResolver.php
php -l app\code\Weline\Deploy\Service\DeployOrchestratorService.php
php -l app\code\Weline\Deploy\Service\DeployWebhookReleaseService.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-endpoint-source-contract.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-local-development.json --expect=local
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --expect=production
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-endpoint-source-contract.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\production-appstore-typed-tag-live-gate.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\production-appstore-typed-tag-live-gate.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json
php app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --resolve-endpoint-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-local-development.json --resolve-endpoint-only=1
```

Results:

- PHP lint passed for the AppStore resolver, Deploy orchestrator, webhook
  release service, deploy endpoint policy checker, and endpoint source
  contract checker. The local PHP CLI still emits duplicate extension and
  OPcache warnings before normal output.
- Deploy endpoint policy self-test returned `passed=true`; positive cases
  resolved local to
  `https://app.weline.test:9523/api/v1/platform/module/list` and production to
  `https://app.aiweline.com/api/v1/platform/module/list`.
- The same self-test rejected empty production URLs, local URLs in production,
  `www.aiweline.com`, API-path values in `appstore_platform_url`, production
  records sourced from config, local API-path values, local production mode, and
  unknown local endpoint sources.
- Local deploy-current fixture passed with `environment=local`,
  `platform_url_source=local_default`, and endpoint
  `https://app.weline.test:9523/api/v1/platform/module/list`.
- Production deploy-current fixture passed with `environment=production`,
  `platform_url_source=production_default`, and endpoint
  `https://app.aiweline.com/api/v1/platform/module/list`.
- Static endpoint source contract returned `passed=true`, proving
  `DeployOrchestratorService`, `AppStorePlatformUrlResolver`,
  `AccountBindService`, and WLS Panel fallback still share the locked endpoint
  policy and reject official `www.*` website hosts as marketplace roots.
- Production live-gate self-test returned `passed=true`; it proves manual
  production endpoint input and insecure mode stay blocked, live execution
  requires token readiness, and live args must forward `--deploy-current`.
- Production live-gate preflight with the production fixture returned
  `guard_passed=true`, `production_deploy_policy_exact_root=true`, and endpoint
  `https://app.aiweline.com/api/v1/platform/module/list`. It exited blocked as
  expected because no production marketplace token is present; no live request
  was made.
- `marketplace-typed-tag-e2e.php --resolve-endpoint-only=1` returned
  `endpoint_source=deploy-current:*` for both fixtures: production resolved to
  `https://app.aiweline.com/api/v1/platform/module/list`, local resolved to
  `https://app.weline.test:9523/api/v1/platform/module/list`.
- No App checkout sync, deploy current write, manifest/source write, WLS start,
  token read, live AppStore request, or browser automation was performed.

## 2026-06-23 Final Workorder And Drift Recheck

Scope:

- Rechecked the final WLS Panel marketplace handoff after the local AppStore
  checkout was confirmed as `E:\WelineFramework\Framework-Official\App\weline`
  and the local endpoint as `https://app.weline.test:9523`.
- Reconfirmed the deployment endpoint contract: local development uses the
  local AppStore root, while production/deployed tests must read
  `appstore_platform_url=https://app.aiweline.com` from deployment metadata.
- Rechecked that `www.weline.test:9518` and `www.aiweline.com` remain forbidden
  marketplace roots for this gate.

Commands:

```powershell
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-workorder.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php --fail-if-unsafe=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php --action-plan-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php --with-drift=1 --drift-summary-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php --with-drift=1 --rollback-review=1
```

Results:

- Final workorder returned `ok=true`, `workorder_ready=true`,
  `current_state=blocked_before_local_live_capture`,
  `ready_for_local_live_capture=false`, and `goal_complete=false`.
- Final workorder keeps the side-effectful steps deferred. The App checkout
  sync, App setup, official manifest/source preparation, App WLS startup, token
  setup, and live typed-tag E2E actions all remain `safe_to_run_now=false`.
- Authorization packet returned `authorization_pack_ready_for_review=true` and
  `current_state=blocked_before_live_run`, but
  `ready_for_live_local_appstore_e2e=false`; it preserved
  `all_side_effect_steps_deferred=true`,
  `only_live_step_runnable_when_ready=true`, and `no_secret_values=true`.
- Readiness action-plan mode returned `ready=false`. Current blockers are the
  missing App sqlite composite-primary-key guard, closed
  `app.weline.test:9523`, missing bearer-token env, and missing official
  `official-apps/manifest.json` with the required positive WLS entries plus the
  strict `module:wls-extra` negative canary.
- Drift summary returned `ok=true` with `allowed_path_count=46`,
  `drifted_count=46`, `same=0`, `different=19`, `missing_app=27`,
  `missing_dev=0`, `missing_both=0`, and `rows_omitted=46`.
- Rollback review still reports two out-of-scope App checkout Admin return-url
  rows and `out_of_scope_fingerprint=0863c8ebd5abef29`; this fingerprint must
  stay unchanged across the later scoped sync unless the operator intentionally
  changes unrelated App files.
- No App checkout sync, setup, deploy current write, manifest/source write, WLS
  start, token read/export, live AppStore request, or browser automation was
  performed.

## 2026-06-23 Production Live Evidence Deploy-Current Source Hardening

Scope:

- Tightened `tools/validate-appstore-live-e2e-evidence.php` so production live
  evidence can no longer pass only because the resolved URL is
  `https://app.aiweline.com`.
- The validator now requires production `live_evidence.endpoint_source` and
  `capture_metadata.endpoint_source` to reference the deployed
  `var/deploy/current.json`, or an absolute path ending in
  `/var/deploy/current.json`.
- Production wrapper evidence sourced from
  `tools/deploy-current-production-default.json`, runtime defaults, manual
  endpoints, or other fixture-style deploy-current files is rejected.

Commands:

```powershell
gitnexus impact wlsPanelLiveEvidenceEvaluate -r dev-workspace -d upstream --depth 2
gitnexus impact validate-appstore-live-e2e-evidence -r dev-workspace -d upstream --depth 2
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-live-e2e-evidence.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-live-e2e-evidence.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-goal-completion-gate.php
git diff --check -- app/code/Weline/Server/doc/wls-panel-plan/tools/validate-appstore-live-e2e-evidence.php app/code/Weline/Server/doc/wls-panel-plan/90-completion-audit-and-next-gates.md app/code/Weline/Server/doc/wls-panel-plan/95-final-acceptance-runbook.md app/code/Weline/Server/doc/wls-panel-plan/96-requirement-traceability.md
```

Results:

- GitNexus did not find the plan-tool symbols
  `wlsPanelLiveEvidenceEvaluate` or `validate-appstore-live-e2e-evidence` in
  the indexed production graph, so impact is treated as a plan-tool scoped
  change and validated through lint plus self-tests.
- PHP lint passed for `validate-appstore-live-e2e-evidence.php`. The local PHP
  CLI still emits duplicate extension and OPcache warnings before normal
  output.
- Validator self-test returned `passed=true` and now includes the negative case
  `rejects_production_wrapper_fixture_deploy_current_source`. That case proves
  production wrapper evidence with
  `endpoint_source=deploy-current:tools/deploy-current-production-default.json`
  is invalid even though the endpoint itself is
  `https://app.aiweline.com/api/v1/platform/module/list`.
- The new validator checks
  `production_evidence_source_is_deployed_current_json` and
  `production_capture_source_is_deployed_current_json` are required for
  production evidence.
- Final preflight returned `ok=true`, kept
  `live_e2e_evidence_validator_self_test_passed=true`, and still reported
  `ready_for_live_local_appstore_e2e=false` and `goal_complete=false`.
- Completion audit still reports `complete=false` with 3 open rows; the first
  incomplete requirement is still WLS marketplace typed meta tags.
- Strict goal completion gate still exited `1` as expected because local and
  production captured live evidence files are absent.
- `git diff --check` passed for the validator and updated WLS Panel Plan docs.
- No App checkout sync, setup, deploy current write, manifest/source write, WLS
  start, token read/export, live AppStore request, or browser automation was
  performed.

## 2026-06-23 Final Gate And Workorder Deploy-Current Source Invariant

Scope:

- Tightened `tools/wls-panel-live-evidence-final-gate.php` so production final
  evidence requires the validator's deployed-source checks, not just a matching
  production endpoint URL.
- Added a production-positive final-gate self-test case for capture-wrapper
  evidence sourced from deployed `var/deploy/current.json`.
- Added a production-negative final-gate self-test case for
  `tools/deploy-current-production-default.json` fixture evidence.
- Extended the final workorder and deferred-action validator acceptance
  contract with
  `production_capture_must_use_deployed_var_current_json_source`.

Commands:

```powershell
gitnexus impact wlsPanelLiveFinalGateAssessValidatorPayload -r dev-workspace -d upstream --depth 2
gitnexus impact wlsPanelDeferredActionsValidate -r dev-workspace -d upstream --depth 2
gitnexus impact wlsPanelWorkorderBuild -r dev-workspace -d upstream --depth 2
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-evidence-final-gate.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-workorder.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-final-workorder-deferred-actions.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-evidence-final-gate.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-workorder.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-final-workorder-deferred-actions.php --self-test=1
```

Results:

- GitNexus did not find the three plan-tool symbols in the indexed production
  graph, so impact is treated as scoped documentation/tooling hardening and
  validated through lint plus self-tests.
- PHP lint passed for all three changed tools. The local PHP CLI still emits
  duplicate extension and OPcache warnings before normal output.
- Final evidence gate self-test returned `passed=true` and now includes
  `accepts_valid_production_capture_wrapper_deployed_current` plus
  `rejects_production_fixture_deploy_current_source`.
- Final workorder self-test returned `passed=true` with the expanded six-item
  acceptance contract.
- Deferred-action validator self-test returned `passed=true` and now requires
  the production capture handoff to preserve deployed endpoint-source metadata.
- No App checkout sync, setup, deploy current write, manifest/source write, WLS
  start, token read/export, live AppStore request, or browser automation was
  performed.

## 2026-06-23 Authorization Packet Full Drift Review Bound

Scope:

- Adjusted `tools/wls-panel-live-e2e-authorization-pack.php` so
  `--include-drift-rows=1 --include-rollback-review=1 --fail-if-unsafe=1`
  remains reviewable for the current 46-row allowed-path DEV/App drift.
- The authorization packet now uses an explicit `drift_row_bound=60` and also
  requires the emitted drift row count to stay at or below the manifest total.
- This preserves bounded output while allowing the full scoped sync list to be
  reviewed immediately before an authorized `分项` sync.

Commands:

```powershell
gitnexus impact wlsPanelAuthPackNormalizeActions -r dev-workspace -d upstream --depth 2
gitnexus impact wlsPanelAuthPack -r dev-workspace -d upstream --depth 2
gitnexus impact wls-panel-live-e2e-authorization-pack -r dev-workspace -d upstream --depth 2
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php --include-drift-rows=1 --include-rollback-review=1 --fail-if-unsafe=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
git diff --check -- app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-live-e2e-authorization-pack.php app/code/Weline/Server/doc/wls-panel-plan/00-INDEX.md app/code/Weline/Server/doc/wls-panel-plan/77-current-integrated-verification-evidence.md app/code/Weline/Server/doc/wls-panel-plan/90-completion-audit-and-next-gates.md app/code/Weline/Server/doc/wls-panel-plan/95-final-acceptance-runbook.md app/code/Weline/Server/doc/wls-panel-plan/96-requirement-traceability.md
rg -n "[ \t]+$" app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-live-e2e-authorization-pack.php app/code/Weline/Server/doc/wls-panel-plan/00-INDEX.md app/code/Weline/Server/doc/wls-panel-plan/77-current-integrated-verification-evidence.md app/code/Weline/Server/doc/wls-panel-plan/90-completion-audit-and-next-gates.md app/code/Weline/Server/doc/wls-panel-plan/95-final-acceptance-runbook.md app/code/Weline/Server/doc/wls-panel-plan/96-requirement-traceability.md
```

Results:

- GitNexus returned `Target not found` for the plan-tool symbols, so impact is
  treated as a plan-tool scoped change and validated through lint plus self-test.
- PHP lint passed. The local PHP CLI still emits duplicate extension and OPcache
  warnings before normal output.
- Authorization packet self-test returned `passed=true` and now includes
  `drift_rows_accept_current_manifest_sized_packet`,
  `drift_rows_reject_packet_above_bound`, and
  `drift_rows_reject_more_rows_than_manifest_total`.
- The full read-only review command returned `ok=true` and
  `authorization_pack_ready_for_review=true`; it reported
  `drift_rows_bounded_when_requested=true`, `drift_row_count=46`,
  `drift_total=46`, and `drift_row_bound=60`.
- The packet still reports `current_state=blocked_before_live_run` and
  `ready_for_live_local_appstore_e2e=false`; this is review evidence, not
  permission to run sync, setup, WLS startup, token export, or live AppStore
  requests.
- No App checkout sync, setup, deploy current write, manifest/source write, WLS
  start, token read/export, live AppStore request, or browser automation was
  performed.

## 2026-06-23 Authorization Drift Fingerprint And Rollback Path Review

Scope:

- Hardened `tools/validate-local-appstore-sync-manifest.php` so every drift
  report emits a stable `review_fingerprint` based on allowed-path rows,
  status, and truncated DEV/App hashes.
- The same fingerprint is emitted even when `--drift-summary-only=1` omits
  per-file rows, so CI and operator logs can compare the reviewed drift object
  without printing the full row list.
- Hardened the App checkout rollback-review parser so both standard
  `git status --short` forms such as ` M path` and `M  path`, plus the legacy
  trimmed `M path` shape, preserve the `app/code/...` path prefix.
- Extended `tools/wls-panel-live-e2e-authorization-pack.php` so the packet must
  report `drift_review_fingerprint_present=true` and expose
  `tool_results.sync_manifest.drift_review_fingerprint` before it can be
  treated as reviewable.

Commands:

```powershell
gitnexus impact wlsPanelManifestDriftReport -r dev-workspace -d upstream --depth 2
gitnexus impact wlsPanelAuthPackDriftRowsBounded -r dev-workspace -d upstream --depth 2
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php --with-drift=1 --drift-summary-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php --include-drift-rows=1 --include-rollback-review=1 --fail-if-unsafe=1
```

Results:

- GitNexus returned `Target not found` for the plan-tool symbols, so impact is
  treated as a plan-tool scoped change and validated through lint plus
  self-tests.
- PHP lint passed for both changed tools. The local PHP CLI still emits
  duplicate extension and OPcache warnings before normal output.
- Manifest self-test returned `passed=true` and now includes stable/changing
  drift fingerprint cases plus `git_status_index_modified_path_preserves_app_prefix`
  and `git_status_legacy_trimmed_path_preserves_app_prefix`.
- Drift summary returned `ok=true`, `drifted_count=46`, `same=0`,
  `different=19`, `missing_app=27`, `rows_omitted=46`, and emitted
  `review_fingerprint`.
- Full authorization packet returned `ok=true`,
  `authorization_pack_ready_for_review=true`,
  `drift_review_fingerprint_present=true`, bounded 46 drift rows, and a
  corrected rollback review whose out-of-scope paths now preserve the
  `app/code/...` prefix.
- The packet still locks marketplace roots to local
  `https://app.weline.test:9523` and production `https://app.aiweline.com`.
  This is read-only review evidence; it does not authorize App checkout sync,
  setup, deploy-current writes, WLS startup, token export, or live AppStore
  requests.

## 2026-06-24 Final Workorder Drift Fingerprint Contract

Scope:

- Tightened `tools/wls-panel-final-preflight.php` so the aggregate preflight
  promotes `sync_manifest_drift_review_fingerprint_present=true` and exposes
  `summary.drift_review_fingerprint`.
- Tightened `tools/wls-panel-final-workorder.php` so the compact operator
  handoff carries that fingerprint under
  `source_summary.drift_review_fingerprint`, includes it in
  `preflight_checks`, and adds
  `app_checkout_sync_must_compare_drift_review_fingerprint_before_and_after_sync`
  to the final acceptance contract.
- Tightened `tools/validate-final-workorder-deferred-actions.php` so a
  workorder without a drift fingerprint, without the full authorization-packet
  review command, or without the post-sync drift gate is rejected before any
  operator handoff can be accepted.

Commands:

```powershell
gitnexus impact wlsPanelWorkorderBuild -r dev-workspace -d upstream --depth 2
gitnexus impact wlsPanelDeferredActionsValidate -r dev-workspace -d upstream --depth 2
gitnexus impact wlsPanelFinalPreflightBool -r dev-workspace -d upstream --depth 2
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-workorder.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-final-workorder-deferred-actions.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-workorder.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-final-workorder-deferred-actions.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-workorder.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-final-workorder-deferred-actions.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-goal-completion-gate.php
```

Results:

- GitNexus returned `Target not found` for the three plan-tool symbols, so the
  change is treated as plan-tool scoped and validated through lint plus
  self-tests.
- PHP lint passed for all three changed tools. The local PHP CLI still emits
  duplicate extension and OPcache warnings before normal output.
- Final workorder self-test returned `passed=true`.
- Deferred-action validator self-test returned `passed=true`, including
  `rejects_missing_drift_review_fingerprint` and
  `rejects_missing_authorization_packet_review_command`.
- Aggregate final preflight returned `ok=true`,
  `ready_for_live_local_appstore_e2e=false`, `goal_complete=false`,
  and `sync_manifest_drift_review_fingerprint_present=true`. The concrete
  `drift_review_fingerprint` value is intentionally read from the latest tool
  output because this evidence document itself is part of the allowed sync
  manifest and changes the fingerprint when edited.
- Final workorder returned `workorder_ready=true`,
  `current_state=blocked_before_local_live_capture`, local root
  `https://app.weline.test:9523`, production root
  `https://app.aiweline.com`, and the same
  `source_summary.drift_review_fingerprint` as the aggregate preflight.
- Deferred-action validation returned `passed=true` with
  `source_summary_drift_review_fingerprint_present=true`,
  `sync_requires_drift_fingerprint_review_chain=true`, and
  `local_capture_requires_reviewed_appstore_prerequisites=true`.
- Completion audit still reports `complete=false` with 3 open rows; the first
  incomplete requirement remains WLS marketplace typed meta tags.
- Strict goal completion gate still exits `1` as expected because local and
  production final live evidence are absent. This prevents falsely marking the
  larger WLS Panel goal complete.
- No App checkout sync, setup, deploy-current write, manifest/source write, WLS
  start, token read/export, live AppStore request, or browser automation was
  performed.

## 2026-06-24 Authorization Packet Rollback Review Safety Gate

Scope:

- Hardened `tools/wls-panel-live-e2e-authorization-pack.php` so
  `--include-rollback-review=1 --fail-if-unsafe=1` treats rollback review as a
  hard safety condition.
- The authorization packet now requires
  `rollback_review_safe_when_requested=true`, `allowed_status_count=0`, a
  non-empty `out_of_scope_fingerprint`, and a complete split between allowed
  sync rows and out-of-scope App checkout rows.
- The check preserves unrelated App checkout status for before/after review,
  while rejecting any packet where the App checkout already has local status
  under the allowed sync paths.

Validation:

```text
gitnexus impact wlsPanelAuthPackNoSecretLeak -r dev-workspace -d upstream --depth 2
gitnexus impact wlsPanelAuthPackDriftRowsBounded -r dev-workspace -d upstream --depth 2
gitnexus impact wlsPanelAuthPackHasPassingCase -r dev-workspace -d upstream --depth 2
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php --include-drift-rows=1 --include-rollback-review=1 --fail-if-unsafe=1
```

Results:

- GitNexus returned `Target not found` for the three plan-tool symbols, so this
  change is treated as plan-tool scoped and validated through lint,
  self-tests, and live packet output.
- PHP lint passed for the changed authorization-pack tool. The local PHP CLI
  still emits the existing duplicate-extension and OPcache warnings before
  normal output.
- Authorization-pack self-test returned `passed=true` with `case_count=19`,
  including rollback-review cases that accept out-of-scope rows with a
  fingerprint, reject allowed-path App status, and reject a missing
  out-of-scope fingerprint.
- The full read-only authorization packet returned
  `authorization_pack_ready_for_review=true` and
  `rollback_review_safe_when_requested=true`.
- The App checkout rollback review reported `allowed_status_count=0`,
  `out_of_scope_status_count=2`, and
  `out_of_scope_fingerprint=100a6a1f2cbffe18`. The out-of-scope rows are the
  existing Admin return-url service/test changes in the App checkout and are
  not part of the WLS scoped sync paths.
- Aggregate final preflight returned `ok=true`,
  `ready_for_live_local_appstore_e2e=false`, and `goal_complete=false`; the
  locked local endpoint remains
  `https://app.weline.test:9523/api/v1/platform/module/list`, and the locked
  production endpoint remains
  `https://app.aiweline.com/api/v1/platform/module/list`.
- Completion audit returned `ok=true`, `complete=false`,
  `completion_matrix_total=14`, `completion_proven_rows=13`,
  `traceability_matrix_total=22`, and `traceability_proven_rows=20`. The first
  remaining incomplete row is still the WLS marketplace typed meta tag live
  AppStore E2E gate.
- `git diff --check` passed for the touched tool and plan docs; Git only
  printed the existing LF-to-CRLF working-copy warning for `00-INDEX.md`.
- The trailing-whitespace scan found no matches in the touched tool or docs.
- No App checkout sync, setup, deploy-current write, manifest/source write, WLS
  start, token read/export, live AppStore request, or browser automation was
  performed.

## 2026-06-24 AppStore Endpoint Source Contract Self-Test

Scope:

- Hardened `tools/validate-appstore-endpoint-source-contract.php` so
  `--self-test=1` is a real self-test instead of being ignored as a normal
  source check.
- Extended the source contract beyond Deploy, resolver, AccountBind, and WLS
  Panel fallback code to cover `ModuleInstallerService`, AppStore backend
  marketplace controller/template, and installed-module controller/template.
- The contract now guards the visible endpoint/source strip, WLS Panel return
  button, `wls_panel_return` query propagation, post-install/update redirect
  back to the standalone WLS Panel, and installer use of
  `AppStorePlatformUrlResolver`.
- `tools/wls-panel-final-preflight.php` now runs that source-contract
  self-test and exposes `endpoint_source_contract_self_test_passed` and
  `endpoint_source_contract_self_test_case_count` before accepting the aggregate
  preflight.

Validation:

```text
gitnexus impact wlsPanelEndpointSourceChecks -r dev-workspace -d upstream --depth 2
gitnexus impact wlsPanelEndpointSourceHasNoWwwMarketplaceHost -r dev-workspace -d upstream --depth 2
gitnexus impact wlsPanelEndpointSourceRead -r dev-workspace -d upstream --depth 2
php -n -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-endpoint-source-contract.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-endpoint-source-contract.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-endpoint-source-contract.php
php -n -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
```

Results:

- GitNexus returned `Target not found` for the three plan-tool functions, so
  this change is treated as plan-tool scoped and validated through lint,
  self-tests, and aggregate preflight output.
- PHP lint passed for both touched tools.
- Source-contract self-test returned `passed=true`, `self_test=true`, and
  `cases=6`. The cases prove the checker rejects an official `www.*`
  marketplace host, missing endpoint strip, missing WLS return URL, missing
  installed-update return redirect, and installer resolver drift.
- The normal source contract returned `passed=true` with AppStore marketplace
  controller, installed-module controller, endpoint strip template,
  installed-template return forms, installer resolver, and no-`www.*` checks
  all true.
- Aggregate final preflight returned `ok=true`,
  `endpoint_source_contract_self_test_passed=true`,
  `endpoint_source_contract_self_test_case_count=6`,
  `endpoint_source_contract_passed=true`,
  `ready_for_live_local_appstore_e2e=false`, and `goal_complete=false`.
- No App checkout sync, setup, deploy-current write, manifest/source write, WLS
  start, token read/export, live AppStore request, or browser automation was
  performed.

## 2026-06-24 Captured Evidence Deployment Contract Embedding

Scope:

- Hardened final live evidence so the deployment endpoint decision is preserved
  inside the captured JSON, not only in preflight output.
- `tools/wls-panel-live-e2e-capture.php --allow-live=1` now carries the
  passing workorder/authorization consistency payload into
  `capture_metadata.workorder_authorization_consistency`.
- The captured metadata records the locked local development root
  `https://app.weline.test:9523`, local module-list endpoint
  `https://app.weline.test:9523/api/v1/platform/module/list`, deployed
  production root `https://app.aiweline.com`, deployed module-list endpoint
  `https://app.aiweline.com/api/v1/platform/module/list`, and the shared
  `drift_review_fingerprint`.
- `tools/validate-appstore-live-e2e-evidence.php` now rejects wrapper evidence
  missing that metadata, using `www.aiweline.com` as the production consistency
  root, or omitting the drift fingerprint.
- `tools/wls-panel-live-evidence-final-gate.php` now requires the validator's
  `capture_consistency_*` checks before local or production captured evidence
  can become final acceptance evidence.
- `tools/wls-panel-live-e2e-authorization-pack.php` now reports
  `capture_consistency_contract_guarded=true`, and
  `tools/wls-panel-final-workorder.php` keeps
  `capture_metadata_must_embed_workorder_authorization_consistency_contract` in
  its final acceptance contract.

Validation:

```text
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-capture.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-live-e2e-evidence.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-evidence-final-gate.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-workorder.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-capture.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-live-e2e-evidence.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-evidence-final-gate.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-workorder.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php --fail-if-unsafe=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-workorder-authorization-consistency.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-goal-completion-gate.php
git diff --check -- app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-live-e2e-capture.php app/code/Weline/Server/doc/wls-panel-plan/tools/validate-appstore-live-e2e-evidence.php app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-live-evidence-final-gate.php app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-live-e2e-authorization-pack.php app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-final-workorder.php app/code/Weline/Server/doc/wls-panel-plan/00-INDEX.md app/code/Weline/Server/doc/wls-panel-plan/77-current-integrated-verification-evidence.md app/code/Weline/Server/doc/wls-panel-plan/90-completion-audit-and-next-gates.md app/code/Weline/Server/doc/wls-panel-plan/95-final-acceptance-runbook.md app/code/Weline/Server/doc/wls-panel-plan/96-requirement-traceability.md
rg -n "[ \t]+$" app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-live-e2e-capture.php app/code/Weline/Server/doc/wls-panel-plan/tools/validate-appstore-live-e2e-evidence.php app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-live-evidence-final-gate.php app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-live-e2e-authorization-pack.php app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-final-workorder.php app/code/Weline/Server/doc/wls-panel-plan/00-INDEX.md app/code/Weline/Server/doc/wls-panel-plan/77-current-integrated-verification-evidence.md app/code/Weline/Server/doc/wls-panel-plan/90-completion-audit-and-next-gates.md app/code/Weline/Server/doc/wls-panel-plan/95-final-acceptance-runbook.md app/code/Weline/Server/doc/wls-panel-plan/96-requirement-traceability.md
```

Results:

- PHP lint passed for all five touched plan tools. The local PHP CLI still emits
  the existing duplicate-extension and OPcache warnings before normal output.
- Self-tests returned `passed=true` with case counts: capture wrapper `15`,
  evidence validator `12`, final evidence gate `14`, authorization pack `16`,
  and final workorder `8`.
- Aggregate final preflight returned `ok=true` and still reports
  `goal_complete=false`.
- Authorization packet returned
  `authorization_pack_ready_for_review=true` with
  `current_state=blocked_before_live_run`.
- Workorder/authorization consistency gate returned `passed=true`, proving the
  local root remains `https://app.weline.test:9523` and deployed production
  remains `https://app.aiweline.com` across final preflight, final workorder,
  authorization packet, and captured-evidence metadata contract.
- Completion audit still returns `complete=false`; strict goal completion gate
  still exits `1` with `complete=false`, as expected while local and production
  captured live evidence are absent.
- `git diff --check` passed for the touched tools/docs; Git only printed the
  existing LF-to-CRLF working-copy warning for `00-INDEX.md`.
- Trailing-whitespace scan found no trailing whitespace in the touched tools or
  docs.
- No App checkout sync, setup, deploy-current write, manifest/source write, WLS
  start, token read/export, live AppStore request, or browser automation was
  performed.

## 2026-06-25 Locked Production AppStore Runtime Resolution

Scope:

- `AppStorePlatformUrlResolver` and the standalone `WlsPanel` fallback resolver
  now only accept production `var/deploy/current.json` marketplace metadata when
  `appstore_platform_url=https://app.aiweline.com` and
  `appstore_platform_url_source=production_default`.
- A production deployment artifact that points to `https://app.weline.test:9523`,
  `www.*`, a custom source, or a full API URL cannot become the runtime
  marketplace root. The runtime falls back to the locked production root, and
  the deploy endpoint policy gate rejects the drift before live evidence can be
  accepted.
- The existing deploy contract remains: local development uses
  `https://app.weline.test:9523`; production capture uses deployed
  `var/deploy/current.json` and resolves to `https://app.aiweline.com`.

Validation:

```text
gitnexus impact AppStorePlatformUrlResolver -r dev-workspace -d upstream --depth 1
gitnexus impact readProductionDeployPlatformUrl -r dev-workspace -d upstream --depth 1
gitnexus impact WlsPanel -r dev-workspace -d upstream --depth 1
gitnexus impact readProductionDeployAppStorePlatformResolution -r dev-workspace -d upstream --depth 1
php -l app\code\Weline\AppStore\Service\AppStorePlatformUrlResolver.php
php -l app\code\Weline\Server\Controller\Backend\WlsPanel.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-endpoint-source-contract.php
php -l app\code\Weline\AppStore\test\Unit\AppStorePlatformUrlResolverTest.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-endpoint-source-contract.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --expect=production
php bin\w phpunit:run --module=Weline_AppStore --name=AppStorePlatformUrlResolverTest
php app\code\Weline\Server\doc\wls-panel-plan\tools\production-appstore-typed-tag-live-gate.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-workorder.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-final-workorder-deferred-actions.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
```

Results:

- GitNexus returned `Target not found` for the new AppStore resolver and both
  private methods; `WlsPanel` returned LOW risk with zero upstream impact.
- PHP lint passed for the modified resolver, WLS Panel controller, source
  contract tool, and resolver test file. The local PHP CLI still emits existing
  duplicate-extension and OPcache warnings before normal output.
- The endpoint source-contract self-test returned `passed=true`, and the deploy
  endpoint policy self-test returned `passed=true`.
- The production fixture policy resolved
  `https://app.aiweline.com/api/v1/platform/module/list` with exact root and
  `production_default` source.
- `AppStorePlatformUrlResolverTest` passed 6 tests / 18 assertions, including
  the new case where production `current.json` points at the local App Store and
  the resolver falls back to `https://app.aiweline.com`.
- Production live gate report mode kept `guard_passed=true`, resolved the
  production endpoint to `https://app.aiweline.com/api/v1/platform/module/list`,
  and stayed blocked because the fixture is not deployed
  `var/deploy/current.json` and no token was read.
- Final workorder and deferred-actions validator passed. Aggregate final
  preflight still reports `ready_for_live_local_appstore_e2e=false` and
  `goal_complete=false`, with endpoint locks green and remaining blockers
  limited to the authorized local AppStore sync/setup/manifest/listener/token
  chain.
- No App checkout sync, setup, deploy-current write, manifest/source write, WLS
  start, token read/export, live AppStore request, or browser automation was
  performed.

## 2026-06-25 Locked Local AppStore Runtime Resolution

Scope:

- Local development is now locked to
  `https://app.weline.test:9523`. `AppStorePlatformUrlResolver`,
  `DeployOrchestratorService`, and the standalone `WlsPanel` fallback resolver
  only accept local env/config marketplace values when the normalized root is
  exactly that URL.
- A local env/config value pointing to `www.*`, a custom host, or a full API URL
  falls back to the locked local App Store root. Production remains locked to
  deployed `var/deploy/current.json` with
  `appstore_platform_url=https://app.aiweline.com` and
  `appstore_platform_url_source=production_default`.

Validation:

```text
php -l app\code\Weline\AppStore\Service\AppStorePlatformUrlResolver.php
php -l app\code\Weline\Deploy\Service\DeployOrchestratorService.php
php -l app\code\Weline\Server\Controller\Backend\WlsPanel.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-endpoint-source-contract.php
php -l app\code\Weline\AppStore\test\Unit\AppStorePlatformUrlResolverTest.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-endpoint-source-contract.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-local-development.json --expect=local
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --expect=production
php bin\w phpunit:run --module=Weline_AppStore --name=AppStorePlatformUrlResolverTest
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-workorder.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-final-workorder-deferred-actions.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
```

Results:

- PHP lint passed for the AppStore resolver, Deploy orchestrator, WLS Panel
  controller, source-contract tool, and resolver test file. The local PHP CLI
  still emits the existing duplicate-extension and OPcache warnings before
  normal output.
- Endpoint source-contract self-test returned `passed=true` with `7` cases,
  including `rejects_runtime_without_locked_local_appstore_root`.
- Deploy endpoint policy self-test returned `passed=true`. The local fixture
  resolved exactly to
  `https://app.weline.test:9523/api/v1/platform/module/list` with
  `platform_url_source=local_default`; the production fixture resolved exactly
  to `https://app.aiweline.com/api/v1/platform/module/list` with
  `platform_url_source=production_default`.
- `AppStorePlatformUrlResolverTest` passed `7` tests / `21` assertions,
  including rejection of a custom local marketplace root
  `https://staging.example.test:9443`.
- Final workorder and deferred-action validation returned `passed=true`.
  Aggregate final preflight returned `ok=true`,
  `ready_for_live_local_appstore_e2e=false`, and `goal_complete=false`; the
  endpoint locks stayed green.
- Completion audit remains `complete=false` with `13/14` completion rows and
  `20/22` traceability rows proven. The open rows are still the authorized local
  AppStore manifest/start/token/live typed-tag E2E chain, not endpoint
  selection.
- No App checkout sync, setup, deploy-current write, manifest/source write, WLS
  start, token read/export, live AppStore request, or browser automation was
  performed.

## 2026-06-25 Production Default Source Workorder Gate

Scope:

- The final operator workorder and deferred-action validator now require
  production live capture to read deployed `var\deploy\current.json` with both
  `appstore_platform_url=https://app.aiweline.com` and
  `appstore_platform_url_source=production_default`.
- `tools/wls-panel-completion-audit.php` now treats that source field as a hard
  documentation and gate invariant, so deployed tests cannot pass merely by
  resolving the right URL from a runtime fallback or fixture.
- `96-requirement-traceability.md` now records the same split: local
  development uses `E:\WelineFramework\Framework-Official\App\weline` and
  `https://app.weline.test:9523`; deployed production capture uses
  `https://app.aiweline.com` only from deployment metadata carrying
  `production_default`.

Validation:

```text
gitnexus impact wlsPanelCompletionRequiredTextChecks -r dev-workspace -d upstream --depth 2
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-workorder.php
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\validate-final-workorder-deferred-actions.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-workorder.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-final-workorder-deferred-actions.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-workorder.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-final-workorder-deferred-actions.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
```

Results:

- GitNexus returned `Target not found` for the plan-tool text-check symbol, so
  the change is treated as plan-tool scoped and validated through lint,
  self-tests, and read-only gates.
- PHP lint passed for the completion audit, final workorder, and deferred-action
  validator tools. The local PHP CLI still emits the existing duplicate
  extension and OPcache warnings before normal output.
- Final workorder self-test passed with `cases=9`, including the acceptance
  invariant `production_capture_must_require_production_default_source`.
- Deferred-action validator self-test passed with `cases=15`, including
  `rejects_production_capture_without_production_default_source`.
- Completion audit returned `ok=true`, `complete=false`, all required text
  checks true, `13/14` completion rows proven, and `20/22` traceability rows
  proven. The remaining open rows are still the authorized local AppStore
  manifest/start/token/live typed-tag E2E chain.
- Real final workorder returned `workorder_ready=true`,
  `current_state=blocked_before_local_live_capture`,
  `ready_for_local_live_capture=false`, `goal_complete=false`, and the
  `production_live_capture_after_launch` operator step now requires both
  deployed `appstore_platform_url=https://app.aiweline.com` and
  `appstore_platform_url_source=production_default`.
- Real deferred-action validation returned `passed=true` with
  `production_capture_requires_production_default_source=true`.
- Aggregate final preflight returned `ok=true`,
  `ready_for_live_local_appstore_e2e=false`, `goal_complete=false`,
  local endpoint `https://app.weline.test:9523/api/v1/platform/module/list`,
  and production endpoint
  `https://app.aiweline.com/api/v1/platform/module/list`.
- No App checkout sync, setup, deploy-current write, manifest/source write, WLS
  start, token read/export, live AppStore request, or browser automation was
  performed.

## 2026-06-25 Deployment Source Lock Documentation Refresh

Scope:

- Updated the WLS Panel Plan index, completion matrix notes, and final
  acceptance runbook so the marketplace environment rule is explicit in both
  human and machine-facing handoff text.
- Local development remains locked to `https://app.weline.test:9523`.
- Production/deployed marketplace proof must read deployed
  `var/deploy/current.json` and require both
  `appstore_platform_url=https://app.aiweline.com` and
  `appstore_platform_url_source=production_default`.
- Runtime defaults, manual endpoints, fixture deploy-current files, and
  `www.*` official website hosts remain non-acceptance evidence for production
  marketplace tests.

Validation:

```text
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
git diff --check -- app/code/Weline/Server/doc/wls-panel-plan/00-INDEX.md app/code/Weline/Server/doc/wls-panel-plan/90-completion-audit-and-next-gates.md app/code/Weline/Server/doc/wls-panel-plan/95-final-acceptance-runbook.md
rg -n "[ \t]+$" app/code/Weline/Server/doc/wls-panel-plan/00-INDEX.md app/code/Weline/Server/doc/wls-panel-plan/90-completion-audit-and-next-gates.md app/code/Weline/Server/doc/wls-panel-plan/95-final-acceptance-runbook.md
```

Results:

- Completion audit returned `ok=true`, `complete=false`, `13/14` completion
  rows proven, and `20/22` traceability rows proven. The open rows remain the
  authorized local AppStore manifest/start/token/live typed-tag E2E chain.
- The remaining marketplace completion row now names both production deployment
  fields in its own `remaining_gate`: `appstore_platform_url=https://app.aiweline.com`
  and `appstore_platform_url_source=production_default`.
- Aggregate final preflight returned `ok=true`,
  `ready_for_live_local_appstore_e2e=false`, `goal_complete=false`, local
  endpoint `https://app.weline.test:9523/api/v1/platform/module/list`, and
  production endpoint `https://app.aiweline.com/api/v1/platform/module/list`.
- `git diff --check` reported no whitespace errors for the touched plan docs.
- The trailing-whitespace scan returned no matches.
- No App checkout sync, setup, deploy-current write, manifest/source write, WLS
  start, token read/export, live AppStore request, or browser automation was
  performed.

## 2026-06-25 Typed Tag Deploy-Current Runner Revalidation

Scope:

- Revalidated the tightened typed-tag runner after the plan docs were updated
  to require deployed production metadata to carry both
  `appstore_platform_url=https://app.aiweline.com` and
  `appstore_platform_url_source=production_default`.
- Corrected the human handoff text so final production captured evidence also
  names `capture_consistency_local_app_env_endpoint_locked=true` alongside the
  exact local App checkout and endpoint checks.

Validation:

```text
php -l app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --resolve-endpoint-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-local-development.json --resolve-endpoint-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-typed-tag-live-gate.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\production-appstore-typed-tag-live-gate.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-completion-audit.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-preflight.php --report-only=1
git diff --check -- app/code/Weline/Server/doc/wls-panel-plan/00-INDEX.md app/code/Weline/Server/doc/wls-panel-plan/77-current-integrated-verification-evidence.md app/code/Weline/Server/doc/wls-panel-plan/90-completion-audit-and-next-gates.md app/code/Weline/Server/doc/wls-panel-plan/96-requirement-traceability.md app/code/Weline/Server/doc/wls-panel-plan/tools/marketplace-typed-tag-e2e.php
rg -n "[ \t]+$" app\code\Weline\Server\doc\wls-panel-plan\00-INDEX.md app\code\Weline\Server\doc\wls-panel-plan\77-current-integrated-verification-evidence.md app\code\Weline\Server\doc\wls-panel-plan\90-completion-audit-and-next-gates.md app\code\Weline\Server\doc\wls-panel-plan\96-requirement-traceability.md app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php
```

Results:

- `marketplace-typed-tag-e2e.php` lint passed. Its 14-case self-test passed,
  including production `production_default` source acceptance, production
  non-`production_default` source rejection, production API-path platform URL
  rejection, local `app.weline.test:9523` source acceptance, and local
  `production_default` source rejection.
- Production endpoint-only preflight resolved
  `tools/deploy-current-production-default.json` to
  `https://app.aiweline.com/api/v1/platform/module/list` with
  `endpoint_source=deploy-current:*` and `appstore_environment=production`.
- Local endpoint-only preflight resolved
  `tools/deploy-current-local-development.json` to
  `https://app.weline.test:9523/api/v1/platform/module/list` with
  `endpoint_source=deploy-current:*` and `appstore_environment=local`.
- Local and production live-gate wrapper self-tests passed with no file read,
  no network, no token, no WLS start, and no writes.
- Completion audit returned `ok=true`, `complete=false`, `13/14` completion
  rows proven, and `20/22` traceability rows proven. The remaining open rows
  are still the authorized local AppStore manifest/start/token/live typed-tag
  E2E chain.
- Aggregate final preflight returned `ok=true`,
  `ready_for_live_local_appstore_e2e=false`, `goal_complete=false`, local
  endpoint `https://app.weline.test:9523/api/v1/platform/module/list`, and
  production endpoint `https://app.aiweline.com/api/v1/platform/module/list`.
- `git diff --check` reported no whitespace errors for the touched files. The
  trailing-whitespace scan returned no matches.
- No App checkout sync, setup, deploy-current write, manifest/source write, WLS
  start, token read/export, live AppStore request, or browser automation was
  performed.

## 2026-06-25 Final Workorder And Authorization Packet Refresh

Scope:

- Refreshed the read-only final workorder, deferred-action validator,
  workorder/authorization consistency gate, authorization packet, and strict
  goal-completion gate after the marketplace endpoint-source lock was tightened.
- Confirmed that WLS Panel Plan files store the AppStore sync authorization
  keyword as `分项`; earlier mojibake seen in shell output was caused by
  PowerShell default encoding when reading UTF-8 Chinese text, not by stored
  document content.

Validation:

```text
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-workorder.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-final-workorder-deferred-actions.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-workorder-authorization-consistency.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php --include-drift-rows=1 --include-rollback-review=1 --fail-if-unsafe=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-goal-completion-gate.php
rg -n "分项" app\code\Weline\Server\doc\wls-panel-plan
```

Results:

- Final workorder returned `ok=true`, `workorder_ready=true`,
  `current_state=blocked_before_local_live_capture`, and
  `goal_complete=false`.
- Deferred-action validator returned `passed=true`. It confirmed the ordered
  action chain `authorized_app_checkout_sync`,
  `run_local_app_setup_after_sync`, `prepare_official_manifest`,
  `start_local_app_wls`, `set_local_marketplace_bearer_token`, and
  `run_live_typed_tag_e2e`; while blockers remain, all six actions stay
  `safe_to_run_now=false`.
- Authorization packet returned `ok=true`,
  `authorization_pack_ready_for_review=true`,
  `current_state=blocked_before_live_run`, exact local root
  `https://app.weline.test:9523`, exact production root
  `https://app.aiweline.com`, and `drifted_count=47`.
- Rollback review in the authorization packet still sees two out-of-scope App
  checkout rows, both outside the allowed sync path list:
  `app/code/Weline/Admin/Service/BackendLoginReturnUrlService.php` and
  `app/code/Weline/Admin/Test/Unit/Service/BackendLoginReturnUrlServiceTest.php`;
  the out-of-scope fingerprint must be recaptured immediately before and after
  any authorized scoped `分项` sync.
- Workorder/authorization consistency returned `passed=true`. It confirmed the
  preflight, final workorder, and authorization packet all share the same
  freshly computed drift fingerprint, local App checkout
  `E:\WelineFramework\Framework-Official\App\weline`, local App env endpoint
  `https://app.weline.test:9523`, and production root
  `https://app.aiweline.com`.
- Strict goal-completion gate returned exit code `1` with `complete=false`, as
  expected. It reports completion audit incomplete, local final evidence
  missing at `var\wls-panel-plan\local-appstore-live-e2e.json`, and production
  final evidence missing at
  `var\wls-panel-plan\production-appstore-live-e2e.json`.
- `rg` found the correct `分项` spelling in WLS Panel Plan files. Use
  `Get-Content -Encoding UTF8` for Chinese doc reads to avoid PowerShell
  display mojibake.
- No App checkout sync, setup, deploy-current write, manifest/source write, WLS
  start, token read/export, live AppStore request, or browser automation was
  performed.
