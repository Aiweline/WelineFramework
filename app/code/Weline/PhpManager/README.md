# Weline PhpManager

Weline_PhpManager is the WLS Panel PHP-profile plugin.

Implemented panel capabilities:

- exposes typed Marketplace tags such as `module:wls` and
  `custom:wls-php-manager`;
- contributes a WLS Panel menu entry through `etc/marketplace/meta.json`;
- opens an independent WLS PHP Manager shell instead of the ordinary backend
  layout;
- reads the effective PHP runtime, ini paths, key ini values, and loaded
  extensions;
- stores a project-scoped PHP Profile in `w_php_manager_project_profile`;
- previews php.ini directive drift against the saved Project Profile;
- writes a WLS-managed directive block to allowed bundled/sandbox php.ini
  files after creating a backup;
- restores the latest PHP Manager php.ini backup from the panel;
- writes JSONL audit records to `var/log/wls/php-manager-audit.jsonl`;
- can optionally request `IpcControlGateway::reloadAsync()` for an
  operator-selected WLS instance after save, apply, or rollback.

This guarded-write slice only manages explicit php.ini directive blocks. It does
not switch PHP binaries or install/remove extensions yet.
