# Local AppStore Sync Execution Manifest

Date: 2026-06-22

This manifest prepares the remaining local marketplace gate. It must not be
executed until a user message explicitly authorizes the `分项` workflow.

## Purpose

The WLS Panel release candidate is blocked only by the local token-authenticated
App Store typed-tag API E2E. The local App Store checkout is:

```text
E:\WelineFramework\Framework-Official\App\weline
https://app.weline.test:9523
```

The official website endpoints `www.weline.test:9518` and `www.aiweline.com`
are not marketplace endpoints for this gate.

## Deployment Endpoint Rule

This endpoint split is part of the deploy artifact contract:

- Local development uses the local App Store checkout URL
  `https://app.weline.test:9523`.
- Runtime local env/config overrides are only accepted when they normalize to
  `https://app.weline.test:9523`; any other local marketplace root, `www.*`
  host, or full API URL falls back to the locked local App Store root and fails
  the deploy endpoint policy gate when written to `current.json`.
- The local readiness probe derives its default host and port from
  `tools/deploy-current-local-development.json`, then requires
  `app_checkout_is_framework_official_app=true`,
  `app_checkout_has_platform_appstore_module=true`,
  `app_checkout_has_appstore_module=true`,
  `app_env_deploy_mode_local=true` and
  `app_env_wls_endpoint_matches_deploy_current=true`,
  `app_env_wls_endpoint_matches_probe_endpoint=true`, and
  `local_deploy_current_matches_probe_endpoint=true` before the live typed-tag
  runner can be considered runnable. A manually supplied `--host` or `--port`
  is only diagnostic; if it disagrees with deploy-current metadata, or if
  `app/etc/env.php` is not explicitly `deploy=dev/local`, the live gate stays
  blocked.
- If the App checkout path is not
  `E:\WelineFramework\Framework-Official\App\weline`, or if it does not expose
  both `app/code/Weline/PlatformAppStore` and `app/code/Weline/AppStore`, the
  probe emits `select_local_appstore_checkout` and blocks the live call.
- If `app/etc/env.php` does not expose WLS as
  `https://app.weline.test:9523` through `wls.host`, `wls.port`, and
  `wls.https`, the probe emits
  `fix_local_deploy_current_marketplace_metadata` and blocks the live call.
- Deployed verification uses `deploy_root/var/deploy/current.json`.
- A production `current.json` with `appstore_environment=production` must
  record `appstore_platform_url=https://app.aiweline.com` and
  `appstore_platform_url_source=production_default` unless a later release
  explicitly designs a different production App Store endpoint.
- `appstore_platform_url` must store the platform root only. A full API endpoint
  such as `https://app.aiweline.com/api/v1/platform/module/list` is rejected by
  `production_records_exact_app_aiweline_platform_url`.
- Non-local deployment checks must not read leftover local
  `WELINE_APPSTORE_PLATFORM_URL` or `appstore.platform_url` values.
  Runtime clients keep `https://app.aiweline.com` as a last-resort fallback,
  but release validation does not accept an empty production
  `appstore_platform_url` in `current.json`.
- Runtime fallback also rejects production `current.json` marketplace metadata
  unless the root is exactly `https://app.aiweline.com` and
  `appstore_platform_url_source=production_default`; a local App Store root,
  `www.*` host, custom source, or full API URL falls back to the locked
  production App Store root and fails the deploy endpoint policy gate.

## Current App Checkout State

Read-only proof from the current host:

- `app.weline.test` resolves to `127.0.0.1`.
- `app.weline.test:9523` is not listening.
- The App checkout has unrelated local Admin return-url changes:
  `app/code/Weline/Admin/Service/BackendLoginReturnUrlService.php` and
  `app/code/Weline/Admin/Test/Unit/Service/BackendLoginReturnUrlServiceTest.php`.
- The App checkout still lacks the DEV sqlite composite-primary-key
  `AUTO_INCREMENT` guard. It has the composite primary-key check, but does not
  suppress `AUTO_INCREMENT` for sqlite composite keys.
- The readiness probe now reports the exact `schema_sync` state for this
  blocker: DEV
  `app/code/Weline/Framework/Database/Schema/SchemaMigrationExecutor.php`
  has `source.guard_present=true`, while the local App checkout target has
  `target.guard_present=false`; `authorized_sync_required=true` and
  `setup_required_after_sync=true` keep this as a reviewed sync/setup item
  rather than a missing DEV implementation.
- The App checkout currently has no readable
  `official-apps/manifest.json`. The local AppStore typed-tag API E2E therefore
  still needs an authorized official manifest/catalog preparation step that
  includes real `module:wls` package entries, the lightweight install-proof
  `Weline_WlsDemoPlugin` demo package, and a real `module:wls-extra` negative
  canary entry without `module:wls`.
- The latest read-only drift review reports `allowed_path_count=47` and all 47
  allowed paths still drift before the authorized App checkout sync:
  `same=0`, `different=19`, `missing_app=28`, `missing_dev=0`, and
  `missing_both=0`. This is review evidence only; it does not authorize sync.
- The latest rollback review still sees two out-of-scope Admin return-url rows
  in the App checkout. Capture the current `out_of_scope_fingerprint` from
  `validate-local-appstore-sync-manifest.php --with-drift=1 --rollback-review=1`
  immediately before and after the scoped sync. Do not reuse a fingerprint
  copied from this document: documentation edits and unrelated App checkout
  work can legitimately change the current review value. The freshly captured
  fingerprint should stay unchanged across the scoped sync unless the operator
  intentionally changes unrelated App files.

Those Admin changes must be preserved. They are not part of this WLS Panel sync.

## Allowed Sync Paths

When the `分项` workflow is authorized, limit the include list to these paths.
Do not broad-sync the repository.

### Core sqlite setup prerequisite

```text
app/code/Weline/Framework/Database/Schema/SchemaMigrationExecutor.php
```

### AppStore endpoint resolution and marketplace visibility

```text
app/code/Weline/AppStore/Controller/Backend/Index.php
app/code/Weline/AppStore/Service/AccountBindService.php
app/code/Weline/AppStore/Service/AppStorePlatformUrlResolver.php
app/code/Weline/AppStore/Service/ModuleInstallerService.php
app/code/Weline/AppStore/doc/README.md
app/code/Weline/AppStore/i18n/en_US.csv
app/code/Weline/AppStore/i18n/zh_Hans_CN.csv
app/code/Weline/AppStore/view/templates/Backend/Index/index.phtml
```

Optional validation-only paths, safe to sync if App checkout keeps module tests:

```text
app/code/Weline/AppStore/test/bootstrap.php
app/code/Weline/AppStore/test/Unit/AppStorePlatformUrlResolverTest.php
app/code/Weline/AppStore/test/Unit/ModuleUpdateServiceTest.php
```

### Deploy current.json marketplace metadata

```text
app/code/Weline/Deploy/Service/DeployOrchestratorService.php
app/code/Weline/Deploy/Service/DeployWebhookReleaseService.php
app/code/Weline/Deploy/doc/backend-config.md
app/code/Weline/Deploy/i18n/en_US.csv
app/code/Weline/Deploy/i18n/zh_Hans_CN.csv
app/code/Weline/Deploy/view/templates/Backend/WlsDeploy/index.phtml
```

### WLS Panel endpoint display and evidence

```text
app/code/Weline/Server/Controller/Backend/WlsPanel.php
app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml
app/code/Weline/Server/doc/wls-panel-plan/00-INDEX.md
app/code/Weline/Server/doc/wls-panel-plan/77-current-integrated-verification-evidence.md
app/code/Weline/Server/doc/wls-panel-plan/90-completion-audit-and-next-gates.md
app/code/Weline/Server/doc/wls-panel-plan/92-local-appstore-sync-manifest.md
app/code/Weline/Server/doc/wls-panel-plan/93-official-appstore-manifest-contract.md
app/code/Weline/Server/doc/wls-panel-plan/95-final-acceptance-runbook.md
app/code/Weline/Server/doc/wls-panel-plan/96-requirement-traceability.md
app/code/Weline/Server/doc/wls-panel-plan/tools/deploy-current-local-development.json
app/code/Weline/Server/doc/wls-panel-plan/tools/deploy-current-production-default.json
app/code/Weline/Server/doc/wls-panel-plan/tools/local-appstore-typed-tag-live-gate.php
app/code/Weline/Server/doc/wls-panel-plan/tools/local-appstore-readiness-probe.php
app/code/Weline/Server/doc/wls-panel-plan/tools/marketplace-typed-tag-e2e.php
app/code/Weline/Server/doc/wls-panel-plan/tools/production-appstore-typed-tag-live-gate.php
app/code/Weline/Server/doc/wls-panel-plan/tools/validate-appstore-endpoint-source-contract.php
app/code/Weline/Server/doc/wls-panel-plan/tools/validate-appstore-live-e2e-evidence.php
app/code/Weline/Server/doc/wls-panel-plan/tools/validate-deploy-appstore-endpoint-policy.php
app/code/Weline/Server/doc/wls-panel-plan/tools/validate-final-workorder-deferred-actions.php
app/code/Weline/Server/doc/wls-panel-plan/tools/validate-local-appstore-sync-manifest.php
app/code/Weline/Server/doc/wls-panel-plan/tools/validate-official-appstore-manifest-contract.php
app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-final-preflight.php
app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-completion-audit.php
app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-final-workorder.php
app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-goal-completion-gate.php
app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-live-e2e-authorization-pack.php
app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-live-e2e-capture.php
app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-live-evidence-final-gate.php
app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-workorder-authorization-consistency.php
```

Other WLS Panel Plan files may be included only when the diff is documentation
evidence for this same marketplace endpoint gate.

## Forbidden Sync Scope

Do not include these paths in this marketplace gate sync:

```text
app/code/Weline/Admin/Service/BackendLoginReturnUrlService.php
app/code/Weline/Admin/Test/Unit/Service/BackendLoginReturnUrlServiceTest.php
generated/
var/
vendor/
```

Do not overwrite App checkout secrets, tokens, local database files, or runtime
state. Do not run a broad reset or checkout in the App checkout.

## Manifest Self-Check

Before the `分项` workflow is authorized, and again immediately before the
dry-run after authorization, validate that the manifest still contains only the
scoped App sync command:

```powershell
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php --with-drift=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php --with-drift=1 --drift-summary-only=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\marketplace-typed-tag-e2e.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php --template=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php --template=1 --template-target=E:\WelineFramework\Framework-Official\App\weline\official-apps\manifest.json
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-local-development.json --expect=local
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-deploy-appstore-endpoint-policy.php --deploy-current=app\code\Weline\Server\doc\wls-panel-plan\tools\deploy-current-production-default.json --expect=production
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-workorder-authorization-consistency.php --self-test=1
```

The checker confirms:

- The local App Store checkout and `https://app.weline.test:9523` endpoint are
  recorded.
- The production deploy endpoint rule includes `https://app.aiweline.com`.
- The self-test proves production records `https://app.aiweline.com` directly
  in the deploy artifact and rejects empty production URLs, local App Store,
  `www.*`, and local deploy-mode endpoint drift before any live App Store token
  or network call.
- The local readiness action plan reports the deployment-derived endpoint,
  `app_env`, `local_deploy_current`,
  `local_deploy_current_matches_probe_endpoint`, and
  `fix_local_deploy_current_marketplace_metadata` if the fixture, supplied
  endpoint, or App env deploy mode drifts.
- The typed-tag runner self-test proves exact local parsing for string,
  JSON-string, structured `type/value`, locale-grouped `tags_resolved`,
  `marketplace_meta.tags`, `system:false`, and negative `module:wls-extra`
  cases before the live local AppStore route is available.
- The official manifest contract self-test proves the expected WLS-positive and
  `module:wls-extra` canary shape before a real `official-apps/manifest.json`
  exists.
- The official manifest template output reads current DEV WLS plugin metadata
  and emits a `manifest_template` plus `source_plan` payload without writing
  the App checkout.
- The official manifest materialize dry-run reports the exact target path and
  `would_write=true` without writing the App checkout. The later write path
  requires `--write=1 --confirm=WRITE_WLS_OFFICIAL_MANIFEST`.
- The official source catalog dry-run reports target directories under
  `official-apps/modules/*` without writing the App checkout. The later source
  write path requires
  `--write-sources=1 --confirm-sources=WRITE_WLS_OFFICIAL_SOURCES`.
- The readiness probe checks the App checkout `official-apps/manifest.json` for
  a WLS-positive marketplace source and a strict `module:wls-extra` negative
  canary. It also reports `official_manifest_materialize` with
  `dry_run_command`, `authorized_write_command`,
  `authorized_source_write_command`, `authorized_catalog_write_command`, and
  the confirmation phrases so the authorized catalog preparation uses the same
  target paths. The final live E2E must not pass on algorithm-only unit tests
  or an empty negative query.
- The command targets only `E:\WelineFramework\Framework-Official\App`.
- The `-IncludePaths` list exactly matches the allowed sync paths.
- The sync list explicitly includes the local/production typed-tag live gates,
  endpoint source contract gate, live evidence validator, capture wrapper,
  final evidence gate, final work order, workorder/authorization consistency
  gate, deferred-action validator, and goal completion gate, so the App
  checkout cannot receive only part of the WLS Panel marketplace verification
  chain.
- Forbidden Admin, `generated/`, `var/`, and `vendor/` paths are not included.
- The command does not use `www.weline.test` or `www.aiweline.com` as a
  marketplace endpoint.
- `--with-drift=1` hashes only allowed manifest paths under the DEV workspace
  and `E:\WelineFramework\Framework-Official\App\weline`, then reports
  `same`, `different`, `missing_app`, `missing_dev`, and `missing_both` counts
  without running sync or writing either checkout.
- `--drift-summary-only=1` keeps the same read-only comparison and gate result,
  but omits per-file rows and emits `rows_omitted` for CI or deployment logs
  that only need compact counts.
- `--fail-on-drift=1` is intended for the post-sync check. It enables the same
  read-only hash comparison and exits non-zero if any allowed path is still
  different or missing in either checkout.
- `--rollback-review=1` is read-only. It records the App checkout
  `git status --short --untracked-files=all`, marks which rows are inside the
  allowed sync list, and emits an `out_of_scope_fingerprint` that can be
  compared before and after the scoped `分项` run.
- The manifest validator rejects hard-coded
  `out_of_scope_fingerprint=<hex>` values in this document. The field name may
  be documented, but the current value must come from the latest command output.
- `--self-test=1` is in-memory and does not read the App checkout. It proves
  rollback-review status parsing, rename target normalization, out-of-scope
  fingerprinting, hard-coded fingerprint rejection, forbidden-prefix detection,
  and broad-include rejection.

The readiness probe is read-only. Before sync it is expected to report
`ready=false` until the App checkout has the sqlite composite-primary-key guard,
`official-apps/manifest.json` contains at least one `module:wls` package entry,
the same manifest contains a strict `module:wls-extra` negative canary entry,
App WLS is listening on `app.weline.test:9523`, and a local
`WLS_MARKETPLACE_BEARER_TOKEN` is supplied outside repository files.
Even while `ready=false`, its `official_manifest_materialize` section must show
`dry_run_available=true` before an authorized manifest write is attempted.
The deploy endpoint policy checker is also read-only; it proves local fixtures
resolve to `app.weline.test:9523` and production fixtures both record and
resolve to `app.aiweline.com` without using `www.*` hosts.
The drift report is intentionally non-blocking before authorization: detected
drift tells the operator what the scoped `分项` run must update; it is not a
permission to sync or write the App checkout.
After the authorized scoped sync completes, residual drift is blocking: run
`--fail-on-drift=1` and do not proceed to App setup, WLS startup, or live
typed-tag API E2E until it reports `ok=true`.
Use `--fail-on-drift=1 --drift-summary-only=1` when the same post-sync gate is
running in CI or deployment logs and row-level drift details are not needed.
The readiness probe action plan exposes the same command chain on the
`authorized_app_checkout_sync` action: `preflight_self_test_command`,
`preflight_command`, `pre_authorization_review_command`,
`rollback_review_command`, and `post_sync_gate_command`.

## Authorized Command Shape

After the user explicitly says `分项`, run a scoped App-checkout dry-run first,
then the actual sync only if the include paths are still limited to the allowed
list.

```powershell
.\dev\tools\fenxiang\fenxiang-update.ps1 -Branch dev -Sites @(
  'E:\WelineFramework\Framework-Official\App'
) -DryRun -IncludePaths @(
  'app/code/Weline/Framework/Database/Schema/SchemaMigrationExecutor.php',
  'app/code/Weline/AppStore/Controller/Backend/Index.php',
  'app/code/Weline/AppStore/Service/AccountBindService.php',
  'app/code/Weline/AppStore/Service/AppStorePlatformUrlResolver.php',
  'app/code/Weline/AppStore/Service/ModuleInstallerService.php',
  'app/code/Weline/AppStore/doc/README.md',
  'app/code/Weline/AppStore/i18n/en_US.csv',
  'app/code/Weline/AppStore/i18n/zh_Hans_CN.csv',
  'app/code/Weline/AppStore/view/templates/Backend/Index/index.phtml',
  'app/code/Weline/Deploy/Service/DeployOrchestratorService.php',
  'app/code/Weline/Deploy/Service/DeployWebhookReleaseService.php',
  'app/code/Weline/Deploy/doc/backend-config.md',
  'app/code/Weline/Deploy/i18n/en_US.csv',
  'app/code/Weline/Deploy/i18n/zh_Hans_CN.csv',
  'app/code/Weline/Deploy/view/templates/Backend/WlsDeploy/index.phtml',
  'app/code/Weline/Server/Controller/Backend/WlsPanel.php',
  'app/code/Weline/Server/view/templates/Backend/WlsPanel/index.phtml',
  'app/code/Weline/Server/doc/wls-panel-plan/00-INDEX.md',
  'app/code/Weline/Server/doc/wls-panel-plan/77-current-integrated-verification-evidence.md',
  'app/code/Weline/Server/doc/wls-panel-plan/90-completion-audit-and-next-gates.md',
  'app/code/Weline/Server/doc/wls-panel-plan/92-local-appstore-sync-manifest.md',
  'app/code/Weline/Server/doc/wls-panel-plan/93-official-appstore-manifest-contract.md',
  'app/code/Weline/Server/doc/wls-panel-plan/95-final-acceptance-runbook.md',
  'app/code/Weline/Server/doc/wls-panel-plan/96-requirement-traceability.md',
  'app/code/Weline/Server/doc/wls-panel-plan/tools/deploy-current-local-development.json',
  'app/code/Weline/Server/doc/wls-panel-plan/tools/deploy-current-production-default.json',
  'app/code/Weline/Server/doc/wls-panel-plan/tools/local-appstore-typed-tag-live-gate.php',
  'app/code/Weline/Server/doc/wls-panel-plan/tools/local-appstore-readiness-probe.php',
  'app/code/Weline/Server/doc/wls-panel-plan/tools/marketplace-typed-tag-e2e.php',
  'app/code/Weline/Server/doc/wls-panel-plan/tools/production-appstore-typed-tag-live-gate.php',
  'app/code/Weline/Server/doc/wls-panel-plan/tools/validate-appstore-endpoint-source-contract.php',
  'app/code/Weline/Server/doc/wls-panel-plan/tools/validate-appstore-live-e2e-evidence.php',
  'app/code/Weline/Server/doc/wls-panel-plan/tools/validate-deploy-appstore-endpoint-policy.php',
  'app/code/Weline/Server/doc/wls-panel-plan/tools/validate-final-workorder-deferred-actions.php',
  'app/code/Weline/Server/doc/wls-panel-plan/tools/validate-local-appstore-sync-manifest.php',
  'app/code/Weline/Server/doc/wls-panel-plan/tools/validate-official-appstore-manifest-contract.php',
  'app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-final-preflight.php',
  'app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-completion-audit.php',
  'app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-final-workorder.php',
  'app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-goal-completion-gate.php',
  'app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-live-e2e-authorization-pack.php',
  'app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-live-e2e-capture.php',
  'app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-live-evidence-final-gate.php',
  'app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-workorder-authorization-consistency.php',
  'app/code/Weline/AppStore/test/bootstrap.php',
  'app/code/Weline/AppStore/test/Unit/AppStorePlatformUrlResolverTest.php',
  'app/code/Weline/AppStore/test/Unit/ModuleUpdateServiceTest.php'
)
```

If the dry-run output is scoped as expected, repeat the same command without
`-DryRun`. Keep `-Sites @('E:\WelineFramework\Framework-Official\App')` for this
local marketplace gate unless the user explicitly asks for the broader
multi-project `分项` sweep. Do not replace the explicit file list with a
directory-level include unless the new directory contents are audited first.

## Post-Sync App Checkout Validation

Run from `E:\WelineFramework\Framework-Official\App\weline` after sync:

```powershell
php -l app\code\Weline\AppStore\Service\AppStorePlatformUrlResolver.php
php -l app\code\Weline\Framework\Database\Schema\SchemaMigrationExecutor.php
php bin/w setup:upgrade --route --skip-env-check --skip-composer-dump
```

Then start the local App Store WLS on the endpoint reported by
`tools/deploy-current-local-development.json` and verify it is listening:

```powershell
php bin/w server:start wls --host app.weline.test --port 9523 --ssl-domain app.weline.test
curl.exe -k -I --max-time 12 --noproxy * --resolve app.weline.test:9523:127.0.0.1 https://app.weline.test:9523/
```

Before the live typed-tag run, make sure the App Store official catalog source
is prepared by an authorized App checkout change, not by broad syncing WLS plan
files:

```text
E:\WelineFramework\Framework-Official\App\weline\official-apps\manifest.json
```

It must include at least one installable WLS marketplace entry tagged
`module:wls` and one negative canary entry tagged `module:wls-extra` without
`module:wls`. The canary exists only to prove exact tag matching; it should not
appear in the WLS Panel plugin list.

Validate the official manifest contract from the DEV workspace before the live
API E2E:

```powershell
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php --template=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php --template=1 --template-target=E:\WelineFramework\Framework-Official\App\weline\official-apps\manifest.json
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php --template=1 --template-target=E:\WelineFramework\Framework-Official\App\weline\official-apps\manifest.json --write=1 --confirm=WRITE_WLS_OFFICIAL_MANIFEST --create-dir=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php --template=1 --template-target=E:\WelineFramework\Framework-Official\App\weline\official-apps\manifest.json --write-sources=1 --confirm-sources=WRITE_WLS_OFFICIAL_SOURCES --create-source-dirs=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php --template=1 --template-target=E:\WelineFramework\Framework-Official\App\weline\official-apps\manifest.json --write=1 --confirm=WRITE_WLS_OFFICIAL_MANIFEST --create-dir=1 --write-sources=1 --confirm-sources=WRITE_WLS_OFFICIAL_SOURCES --create-source-dirs=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php --manifest=E:\WelineFramework\Framework-Official\App\weline\official-apps\manifest.json
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php --fail-on-drift=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php --fail-on-drift=1 --drift-summary-only=1
```

Before any write command, WLS start, token export, or live API call, generate
the read-only authorization packet from the DEV workspace:

```powershell
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-endpoint-source-contract.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php --include-drift-rows=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-authorization-pack.php --include-drift-rows=1 --include-rollback-review=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-live-e2e-evidence.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-capture.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-capture.php --environment=local
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-evidence-final-gate.php --environment=local
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-final-workorder.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-final-workorder-deferred-actions.php --self-test=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-goal-completion-gate.php --self-test=1
```

The self-test must report `passed=true` before the full authorization packet is
counted; it rejects bearer values, cookie values, private key markers, and
non-live runnable steps without reading files or contacting any service. The
packet must report `authorization_pack_ready_for_review=true`,
`local_endpoint_exact_root=true`, `production_endpoint_exact_root=true`,
`local_env_is_explicit_dev_or_local=true`, `sync_manifest_ok=true`,
`all_side_effect_steps_deferred=true`,
`only_live_step_runnable_when_ready=true`, and `no_secret_values=true`. If the
local live route is still blocked, `current_state` remains
`blocked_before_live_run` and every listed execution step remains
`safe_to_run_now=false`. When the route becomes ready, only
`run_live_typed_tag_e2e` may become runnable.

Use the default authorization packet for compact CI logs. Use
`--include-drift-rows=1` for the human authorization review immediately before
any `分项` sync: it keeps the packet read-only but includes bounded per-file
sync drift rows under `tool_results.sync_manifest.drift_rows`, showing whether
each allowed path is `different`, `missing_app`, `missing_dev`, or
`missing_both`. This drift table is review evidence only; it is not permission
to sync until the user explicitly says `分项`.

When App checkout already has unrelated work, add
`--include-rollback-review=1` to the same authorization packet. The packet then
adds `tool_results.sync_manifest.rollback_review.app_git_status`, including
`allowed_status_count`, `out_of_scope_status_count`, and
`out_of_scope_fingerprint`. Capture this packet before and after the scoped
sync. The out-of-scope fingerprint should stay unchanged unless the operator
intentionally changed unrelated App checkout files outside the WLS Panel sync
list.

The write command is allowed only after the App checkout catalog preparation is
authorized. Manifest write and source catalog write have separate confirmation
phrases. The guarded catalog commands write only
`official-apps/manifest.json` and `official-apps/modules/*`; they do not
authorize broad file sync, WLS startup, token use, or network calls.

The local API E2E is then run from the DEV workspace with a local bearer token
provided through `WLS_MARKETPLACE_BEARER_TOKEN` or a token file:

```powershell
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-e2e-capture.php --environment=local --allow-live=1 --evidence-output=var\wls-panel-plan\local-appstore-live-e2e.json
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-appstore-live-e2e-evidence.php --evidence=var\wls-panel-plan\local-appstore-live-e2e.json --expect=local
```

## Acceptance Evidence

Record the result in `77-current-integrated-verification-evidence.md` and
update `90-completion-audit-and-next-gates.md` only when all are true:

- App checkout unrelated Admin changes are still present.
- `setup:upgrade --route --skip-env-check --skip-composer-dump` succeeds in the
  App checkout.
- `app.weline.test:9523` responds over HTTPS.
- The typed-tag runner proves `tag=module:wls` returns WLS-compatible plugins.
- The negative exact-match check proves `module:wls-extra` does not satisfy
  `module:wls`.
- The strict negative canary option proves the negative query returned a real
  `module:wls-extra` item; an empty negative response is not accepted as the
  final exact-match proof.
- The captured live-gate JSON contains `live_evidence` and passes
  `tools/validate-appstore-live-e2e-evidence.php --evidence=... --expect=local`.
  The validator must confirm the deployment-derived endpoint,
  `single_tag_module_wls`, `structured_tags_all_match`,
  `negative_exact_match_module_wls-extra`, `require_negative_conclusive`, and
  `no_secret_values`.
- Prefer the capture wrapper for final local proof. It must report
  `captured_valid`, `evidence_written=true`, and write only
  `var\wls-panel-plan\local-appstore-live-e2e.json` after `live_executed=true`.
- The capture wrapper must normalize custom `--evidence-output` paths before
  checking the allowed evidence root. Its self-test must include
  `path_traversal_outside_var_rejected`; a path like
  `var\wls-panel-plan\..\leak.json` must fail with
  `evidence_output_inside_var=false`.
- No bearer token, account token, cookie, or private credential is written to
  any repository file or plan evidence.

## Rollback Boundary

If sync or setup fails, do not reset the App checkout. Capture:

- `git -C E:\WelineFramework\Framework-Official\App\weline status --short`
- `php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-local-appstore-sync-manifest.php --with-drift=1 --rollback-review=1`
- the exact failing command
- the first actionable error line
- whether `app.weline.test:9523` was started and needs cleanup

Compare the new `out_of_scope_fingerprint` with the pre-sync authorization
packet. If it changed, stop and review unrelated App checkout work before any
manual recovery. Then stop any App WLS test instance that was started for this
gate.
