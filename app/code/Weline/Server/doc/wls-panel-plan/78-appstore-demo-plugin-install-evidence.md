# WLS AppStore Demo Plugin Install Evidence

Date: 2026-06-25

Status: passed for the local AppStore demo plugin install, the deployed
production AppStore demo plugin install, and the canonical guarded local plus
production typed-tag live evidence files. This evidence closes the concrete
demo-plugin marketplace install and WLS typed-tag marketplace slice. It does
not by itself mark the full WLS Panel goal complete; the full goal still
depends on the requirement matrix and final goal-completion gate.

## 2026-06-28 Canonical Live Evidence

The canonical capture-wrapper evidence is now complete for both environments:

```text
Local evidence:
E:\WelineFramework\DEV-workspace\var\wls-panel-plan\local-appstore-live-e2e.json

Production evidence:
E:\WelineFramework\DEV-workspace\var\wls-panel-plan\production-appstore-live-e2e.json
```

Final gate command:

```text
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-live-evidence-final-gate.php --environment=both --local-evidence=var\wls-panel-plan\local-appstore-live-e2e.json --production-evidence=var\wls-panel-plan\production-appstore-live-e2e.json
```

Final gate result:

- `ok=true`.
- `ready=true`.
- local endpoint:
  `https://app.weline.test:9523/api/v1/platform/module/list`.
- local endpoint source: `arg:endpoint`.
- production endpoint:
  `https://app.aiweline.com/api/v1/platform/module/list`.
- production endpoint source: `deploy-current:var\deploy\current.json`.
- both evidence files are inside `var/wls-panel-plan`.
- both validators report `valid=true`.
- both wrappers report `live_executed=true` and `live_passed=true`.
- no secret values were found in either evidence file.

Typed-tag live results in both environments:

- `tag=module:wls` returned 5 WLS plugin candidates.
- `tags=[module:wls, custom:wls-panel-plugin]` returned exactly
  `Weline_WlsDemoPlugin`.
- `tag=module:wls-extra` returned only the canary
  `Weline_WlsTagCanary`, proving the negative case is conclusive and
  `module:wls-extra` is not accepted as `module:wls`.

The production demo module catalog row was updated on the true production
AppStore root `/www/wwwroot/app.aiweline.com` so `Weline_WlsDemoPlugin` now
includes `custom:wls-panel-plugin` in addition to the earlier WLS tags.

## 2026-06-27 True Production Recheck

The previous 2026-06-26 recheck targeted the wrong host/root. Earlier
environment-specific host and checkout assumptions recorded during diagnosis are
not authoritative deployment defaults for this repository.

The authoritative production AppStore target for this plan was the verified
`app.aiweline.com` production environment used by the evidence run.

A fresh true-production demo install validation was run on 2026-06-27:

```text
cd /www/wwwroot/app.aiweline.com
/www/server/php/84/bin/php var/wls-panel-plan/run-production-demo-install.php
cat var/wls-panel-plan/production-demo-install-evidence.json
```

Current fresh evidence:

- `/www/wwwroot/app.aiweline.com/var/wls-panel-plan/production-demo-install-evidence.json`
  reports `passed=true`.
- `api_list_demo_plugin.status=200`.
- `api_list_demo_plugin.success=true`.
- `api_list_demo_plugin.total=1`.
- `api_list_demo_plugin.found_demo=true`.
- returned tags include `official`, `module:wls`,
  `custom:wls-demo-plugin`, `category:server-tools`,
  `feature:marketplace-demo`, and `system:false`.
- `api_download_metadata.status=200` and `success=true`.
- package endpoint root is
  `https://app.aiweline.com/api/v1/platform/module/package`.
- `download_package_zip.status=200`.
- package SHA-256 matched:
  `e41096aaab4f14ddd780aa147559eb8a76e7bee4b665e3e77699508299fd2588`.
- `install_package.success=true`.
- install target:
  `/www/wwwroot/app.aiweline.com/app/code/Weline/WlsDemoPlugin`.
- `install_package.target_exists=true`.
- `install_package.register_exists=true`.
- install record:
  `/www/wwwroot/app.aiweline.com/var/appstore/install-records/2026-06.jsonl`.

The production fixes applied for this successful recheck were:

- `app/code/Weline/PlatformAppStore/Controller/Api/V1/Platform/Module.php`:
  token fallback reads raw bearer token data when the request wrapper drops or
  transforms authorization headers.
- `app/code/Weline/PlatformAppStore/Service/OfficialAccountService.php`:
  token fallback reads `Authorization`, `X-Weline-Token`, query/form `token`,
  and request URI/body sources before declaring the caller unauthorized.
- `app/code/Weline/Framework/Http/WlsRequest.php`: inject all non-content HTTP
  headers as `HTTP_*` server keys while preserving existing explicit mappings.

Production syntax validation passed for all three files with
`/www/server/php/84/bin/php -l`.

This closes the concrete true-production demo-plugin marketplace install slice.
It does not close the full WLS Panel goal because the strict final gate still
requires canonical capture-wrapper evidence for local and production.

## Scope

- Added `Weline_WlsDemoPlugin` to the AppStore official catalog.
- Preserved the typed WLS tag contract:
  `module:wls`, `custom:wls-demo-plugin`, `category:server-tools`,
  `feature:marketplace-demo`, and `system:false`.
- Verified local AppStore list, package metadata, zip hash, and install.
- Deployed the AppStore update to `app.aiweline.com`.
- Verified true-production HTTPS list, package metadata, zip hash, and install
  through `https://app.aiweline.com` and `/www/wwwroot/app.aiweline.com`.

## Code And Deployment

```text
AppStore commit: 3060c5dcb4c5414778ab83c069c93bd425e44e1b
Remote branch: appstore/dev
Message: feat: add WLS official app marketplace catalog
Production root: /www/wwwroot/app.aiweline.com
Production backup: /www/wwwroot/app.aiweline.com/var/appstore-demo-backup-before-3060c5dcb.tgz
Production WLS: http://127.0.0.1:9523
Production HTTPS: https://app.aiweline.com
```

Production deployment used a scoped patch package because the production root
is not a Git checkout. After extraction, production ran `deploy:mode:set prod`,
`setup:upgrade --route`, official catalog sync, and WLS startup. Production
WLS status reported Master PID `108337` and 3/3 workers running.

## Local Evidence

Evidence file:

```text
E:\WelineFramework\Framework-Official\App\weline\var\wls-panel-plan\local-demo-install-evidence.json
```

Important fields:

- `environment=local`
- `base_url=https://app.weline.test:9523`
- `module_name=Weline_WlsDemoPlugin`
- `api_list_demo_plugin.status=200`
- `api_list_demo_plugin.success=true`
- `api_list_demo_plugin.total=1`
- `api_list_demo_plugin.found_demo=true`
- returned tags include `module:wls`, `custom:wls-demo-plugin`,
  `category:server-tools`, `feature:marketplace-demo`, and `system:false`
- `api_download_metadata.status=200`
- `download_package_zip.status=200`
- package SHA-256 matched:
  `1da5456041aa2fe1bb1af56d1aea36fd3065a47acc2093c177cceba2702738d7`
- `install_package.success=true`
- install target:
  `E:\WelineFramework\Framework-Official\App\weline\app\code\Weline\WlsDemoPlugin`
- `install_package.register_exists=true`
- top-level `passed=true`

Note: the local startup command wrapper returned a non-zero command code, but
the following probe reached the App WLS endpoint with HTTP 200 and the
marketplace API/install flow passed. Treat the HTTP probe and install evidence
as authoritative for this demo install slice.

## Production Evidence

Evidence file:

```text
/www/wwwroot/app.aiweline.com/var/wls-panel-plan/production-demo-install-evidence.json
```

Important fields:

- `environment=production`
- `base_url=https://app.aiweline.com`
- `module_name=Weline_WlsDemoPlugin`
- `sync_official_catalog.success=true`
- `api_list_demo_plugin.status=200`
- `api_list_demo_plugin.success=true`
- `api_list_demo_plugin.total=1`
- `api_list_demo_plugin.found_demo=true`
- returned tags include `module:wls`, `custom:wls-demo-plugin`,
  `category:server-tools`, `feature:marketplace-demo`, and `system:false`
- `api_download_metadata.status=200`
- package URL root:
  `https://app.aiweline.com/api/v1/platform/module/package`
- `download_package_zip.status=200`
- package SHA-256 matched:
  `e41096aaab4f14ddd780aa147559eb8a76e7bee4b665e3e77699508299fd2588`
- `install_package.success=true`
- install target:
  `/www/wwwroot/app.aiweline.com/app/code/Weline/WlsDemoPlugin`
- `install_package.register_exists=true`
- top-level `passed=true`

The latest true-production proof relies on the API/package/install evidence
above. During the 2026-06-27 recheck, the production `wls` instance status
showed stopped workers, so WLS response headers are not treated as current
acceptance evidence for this slice.

## Remaining Gate

The concrete marketplace slice is now proven by both the demo install evidence
and the canonical local/production live capture-wrapper evidence. The full WLS
Panel goal remains open only to the extent that the broader completion matrix
still has non-marketplace rows marked `Partial`, `Environment gate`, or any
state other than `Proven`.

Before marking the thread goal complete, run the strict goal gate after the
latest docs and evidence are in place:

```text
php app\code\Weline\Server\doc\wls-panel-plan\tools\wls-panel-goal-completion-gate.php --local-evidence=var\wls-panel-plan\local-appstore-live-e2e.json --production-evidence=var\wls-panel-plan\production-appstore-live-e2e.json
```

That goal gate, not this demo evidence file alone, decides whether every WLS
Panel requirement is complete.
