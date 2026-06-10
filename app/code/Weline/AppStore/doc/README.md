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
