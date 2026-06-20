# Weline_AppStore

Weline_AppStore provides the local application store integration for account binding, module download history, license views, installed-module records, and setup-time AppStore metadata initialization.

## Setup Logging

Setup and observer error handling must keep the `Weline\Framework\App\Env::log_error()` contract:

```php
Env::log_error(string $filename, string $message): bool
```

Use an `appstore/*.log` filename string as the first argument. The second argument must be a string message; serialize any context into the message or use the global logger API when structured context is required.

## Validation

- WEL-86 adds a focused PHPUnit regression for AppStore `Env::log_error()` calls so setup logging cannot pass an array as the message again.
- After setup logging changes, validate with the targeted AppStore logging test and a non-production `setup:upgrade` command.

## Installed Module Updates

- The installed-module page checks the platform `check-update` API with local module name, current runtime `register.php` version, platform module ID, and current domain. The installed-module record version is only a fallback when runtime module metadata is unavailable.
- Local or private HTTPS marketplace sources must keep certificate verification enabled. Configure `appstore.ca_bundle` or `WELINE_APPSTORE_CA_BUNDLE` with a readable CA bundle path when the platform URL uses a local development CA.
- Updating a module reuses `ModuleInstallerService::download()` and `install()` with `action=upgrade`; install records include the previous version and old-version backup directory.
- Install and update completion refreshes the command index through a fresh `php bin/w command:upgrade` process, then targets WLS reload at the current `WLS_INSTANCE` when an instance record exists.
- The installed-module page uninstalls directly through `ModuleUninstallService`, which delegates to the framework `php bin/w module:remove Vendor_Module` flow so database and file backups stay owned by system uninstall.
- The installed-module page shows immediate in-page uninstall progress and reads the latest uninstall audit record when the list becomes empty, so users still see a completion result after the module record has been removed.
- If a previous install left module files with the `商城应用.md` marker but no AppStore install row, the installed-module page recovers only that marked marketplace module record so the user can still manage and uninstall it from My modules.

## Marketplace Meta / Tags

- AppStore package installation requires WMP-Meta v1 from `etc/marketplace/meta.json` after package structure validation. Packages may also declare `marketplace_meta.path` and `marketplace_meta.sha256` in `weline-appstore-package.json`; declared hashes are verified before install continues.
- Marketplace tag codes support both legacy dot tags such as `surface.backend` and typed tags such as `module:wls`, `custom:wls-file-manager`, and `system:false`.
- Marketplace home normalizes tag filters before calling the platform module list API, so `tag=module:wls` is forwarded as a stable typed code and `surface:backend` is resolved to `surface=backend`.
- Platform snapshots may return `tags`, flat `tags_resolved`, locale-grouped `tags_resolved`, or full `marketplace_meta.tags`; AppStore normalizes these codes before storing installed-module meta.
- `module_name` mismatches, declared meta hash mismatches, missing meta, missing source locale display name, empty tags, or tag entries without source locale labels block AppStore package installation. Legacy local-module recovery and `module:info` keep non-strict compatibility for old modules.
- Package meta only needs complete source locale text. Other locale labels/descriptions are optional; `InstalledModuleMetaService` submits marketplace source words and any provided translations to `Weline_I18n::collect_translations` so I18n can maintain dictionary rows and AI translation queues.
- After successful install or upgrade, `InstalledModuleMetaService::syncOnInstall()` writes `marketplace_meta_json`, `marketplace_meta_hash`, `marketplace_meta_locale`, `primary_tag_code`, and `surface_codes` to the installed-module record.
- `w_query('appstore', 'installedModules')` exposes localized module labels, normalized `tag_codes` / `surface_codes`, raw `marketplace_meta`, `capabilities`, and optional panel entry fields such as `wls_panel_url`, `panel_url`, `backend_url`, `capability_url`, `panel_entry`, `backend_entry`, and `wls_panel` so caller modules can discover plugin capabilities without direct AppStore class coupling.
- The installed-module page shows localized tag badges and supports tag/surface filtering. The marketplace home cards display 1-3 tags when the platform API returns `tags` or `tags_resolved`.
- `商城应用.md` includes an “应用标签” section when structured meta tags are available.
- `php bin/w appstore:sync-tags --locale=zh_Hans_CN` refreshes the optional platform tag registry cache used for display fallback.
