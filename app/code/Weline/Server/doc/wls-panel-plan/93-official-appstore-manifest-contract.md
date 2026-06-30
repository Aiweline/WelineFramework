# Official AppStore Manifest Contract For WLS Plugins

Date: 2026-06-23

This file defines the missing App Store catalog source needed before the WLS
Panel marketplace typed-tag E2E can be accepted. It is a contract and readiness
gate only; it does not authorize broad syncing or editing the local App Store
checkout.

## Scope

The local development marketplace source is:

```text
E:\WelineFramework\Framework-Official\App\weline\official-apps\manifest.json
```

The production marketplace is:

```text
https://app.aiweline.com
```

The official website hosts `www.weline.test:9518` and `www.aiweline.com` are not
WLS Panel marketplace endpoints.

## Required WLS Entries

The manifest must contain real package entries for the current WLS Panel plugin
modules. Each positive entry must expose an exact `module:wls` tag plus its
own `custom:wls-*` identity tag.

| Module | Required tags | Source meta |
| --- | --- | --- |
| `Weline_PhpManager` | `module:wls`, `custom:wls-php-manager` | `PhpManager/etc/marketplace/meta.json` |
| `Weline_DbManager` | `module:wls`, `custom:wls-database-manager` | `DbManager/etc/marketplace/meta.json` |
| `Weline_FileManager` | `module:wls`, `custom:wls-file-manager` | `FileManager/etc/marketplace/meta.json` |
| `Weline_Deploy` | `module:wls`, `custom:wls-deploy` | `Deploy/etc/marketplace/meta.json` |
| `Weline_WlsDemoPlugin` | `module:wls`, `custom:wls-demo-plugin` | `WlsDemoPlugin/etc/marketplace/meta.json` |

The source module directories must be real official-apps sources with
`register.php` and `etc/marketplace/meta.json`. The WLS plugin metadata remains
the source of labels, capabilities, panel menu contributions, and typed tags.

## Required Negative Canary

The final exact-match proof must not rely on an empty negative query. The
official manifest must include one real negative canary package that can be
returned by `tag=module:wls-extra`.

Contract:

- The canary has `module:wls-extra`.
- The canary does not have `module:wls`.
- The canary has a custom identity such as `custom:wls-tag-canary`.
- The canary is only a verification item. It must never appear in the WLS Panel
  plugin list queried by `tag=module:wls`.

This proves `module:wls-extra` is a different exact tag from `module:wls`.

## Minimal Shape

The exact source directories are owned by the App Store checkout. The shape
below shows the required typed-tag fields only:

```json
{
  "schema_version": 1,
  "apps": [
    {
      "name": "Weline_FileManager",
      "source_dir": "modules/FileManager",
      "installable_module": "yes",
      "tags": ["module:wls", "custom:wls-file-manager", "system:false"]
    },
    {
      "name": "Weline_WlsTagCanary",
      "source_dir": "modules/WlsTagCanary",
      "installable_module": "no",
      "tags": ["module:wls-extra", "custom:wls-tag-canary", "system:false"]
    }
  ]
}
```

## Validation

Run the contract self-test from the DEV workspace:

```powershell
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php --self-test=1
```

Generate a read-only manifest template from the current DEV WLS plugin metadata
when preparing the App checkout catalog source:

```powershell
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php --template=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php --template=1 --template-target=E:\WelineFramework\Framework-Official\App\weline\official-apps\manifest.json
```

The template mode reads the current DEV `etc/marketplace/meta.json` and
`register.php` files for `Weline_PhpManager`, `Weline_DbManager`,
`Weline_FileManager`, `Weline_Deploy`, and `Weline_WlsDemoPlugin`, then emits a
JSON payload containing `manifest_template` and `source_plan`. Passing
`--template-target=...` is still read-only by default; it reports
`materialize.would_write=true`, `source_plan.would_write=true`, and does not
write the App checkout. The source plan maps real plugin sources from DEV
module directories into `official-apps/modules/*`, including the lightweight
`Weline_WlsDemoPlugin` install-proof module, and generates the non-installable
`Weline_WlsTagCanary` source used for strict negative exact-match proof.

After the App checkout catalog preparation is explicitly authorized, the same
tool can materialize the manifest JSON target and source catalog targets with
separate confirmation phrases:

```powershell
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php --template=1 --template-target=E:\WelineFramework\Framework-Official\App\weline\official-apps\manifest.json --write=1 --confirm=WRITE_WLS_OFFICIAL_MANIFEST --create-dir=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php --template=1 --template-target=E:\WelineFramework\Framework-Official\App\weline\official-apps\manifest.json --write-sources=1 --confirm-sources=WRITE_WLS_OFFICIAL_SOURCES --create-source-dirs=1
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php --template=1 --template-target=E:\WelineFramework\Framework-Official\App\weline\official-apps\manifest.json --write=1 --confirm=WRITE_WLS_OFFICIAL_MANIFEST --create-dir=1 --write-sources=1 --confirm-sources=WRITE_WLS_OFFICIAL_SOURCES --create-source-dirs=1
```

Write mode is deliberately narrow:

- It requires an absolute `--template-target` ending in `manifest.json`.
- Manifest write requires `--confirm=WRITE_WLS_OFFICIAL_MANIFEST`.
- Source catalog write requires
  `--confirm-sources=WRITE_WLS_OFFICIAL_SOURCES`.
- Manifest write refuses to overwrite an existing target unless
  `--overwrite=1` is passed.
- Source catalog write refuses to overwrite an existing source target unless
  `--overwrite-sources=1` is passed.
- It can create the manifest target directory only when `--create-dir=1` is
  passed, and source parent directories only when `--create-source-dirs=1` is
  passed.
- It writes only `official-apps/manifest.json` and
  `official-apps/modules/*`; it does not start WLS, call App Store, read
  credentials, or run a broad checkout sync.

Validate the real local App Store source after the App checkout has been
prepared:

```powershell
php app\code\Weline\Server\doc\wls-panel-plan\tools\validate-official-appstore-manifest-contract.php --manifest=E:\WelineFramework\Framework-Official\App\weline\official-apps\manifest.json
```

The readiness probe also includes these checks:

```powershell
php app\code\Weline\Server\doc\wls-panel-plan\tools\local-appstore-readiness-probe.php
```

## Acceptance

The local App Store typed-tag API gate can move forward only when all are true:

- The official manifest validator passes.
- The real App checkout contains the source directories referenced by
  `source_dir`, including the generated `Weline_WlsTagCanary` canary source.
- The readiness probe reports `official_manifest_has_wls_positive=true`.
- The readiness probe reports `official_manifest_has_negative_canary=true`.
- The readiness probe reports `official_manifest_negative_canary_exact=true`.
- App WLS is listening on `app.weline.test:9523`.
- The token-safe live runner passes with `--require-negative-conclusive=1`.

Until then, the completion audit must keep the WLS marketplace typed-tag rows as
`Partial`.
