# WLS PHP Manager Plugin

`Weline_PhpManager` is a WLS Panel plugin for project PHP configuration.

## Discovery

The module is discovered by the WLS Panel through typed Marketplace tags in
`etc/marketplace/meta.json`:

- `module:wls`
- `custom:wls-php-manager`
- `feature:php-config`
- `capability:php-read`
- `capability:php-profile-write`
- `capability:php-ini-apply`
- `capability:wls-reload-request`

No WLS-specific PHP inheritance contract is required. The ordinary module
metadata/tag path is the plugin contract.

## Current Slice

The first implementation slice provides:

- standalone panel route: `weline_phpmanager/backend/wls-php-manager`;
- runtime readout from the current PHP process;
- project context fields: `profile_key`, `project_id`, `domain`, and
  `project_type`;
- guarded Project Profile form with confirmation checkbox;
- project-scoped persistence in `w_php_manager_project_profile`;
- audit log entries in `var/log/wls/php-manager-audit.jsonl`;
- optional WLS reload request through `IpcControlGateway::reloadAsync()`;
- PHP Profile inheritance map that compares runtime values, project Profile
  values, effective values, override/alignment state, and required-extension
  satisfaction before an operator applies php.ini;
- extension lifecycle dry-run planning that normalizes install/remove intent,
  checks loaded/core/profile-required state, and keeps execution disabled until
  a future platform adapter slice supplies allowlisted commands;
- php.ini apply plan with directive diff, target guard, and managed block
  detection;
- backup-first php.ini apply to bundled project/sandbox ini paths;
- latest-backup rollback from the same standalone panel.

## Guardrails

- php.ini writes only touch the WLS-managed directive block.
- Every write creates a backup under `var/backups/wls/php-manager` before the
  target file changes.
- Rollback only restores PHP Manager backup files with matching sidecar
  metadata.
- The target ini path must already exist, be readable/writable, and live under
  `extend/server/php` or a WLS PHP Manager sandbox directory.
- PHP binary fields are saved as project Profile metadata only.
- Extension lifecycle actions are dry-run only in this slice; they do not
  install, remove, enable, disable, or reload PHP extensions yet.
- Runtime changes require an explicit runtime action and target instance.

## Future Slices

- import the current `php.ini` into a versioned profile;
- implement platform-specific extension install/remove adapters with explicit
  allowlists, confirmation phrases, audit records, and WLS reload binding;
- bind PHP profiles to Gateway/worker restart plans;
- extend drift evidence into historical profile snapshots.
