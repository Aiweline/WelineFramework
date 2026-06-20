# Weline DbManager

Weline_DbManager is the WLS Panel database-profile plugin.

Implemented panel capabilities:

- exposes typed Marketplace tags such as `module:wls` and `custom:wls-database-manager`;
- contributes a WLS Panel menu entry through `etc/marketplace/meta.json`;
- reads the effective database configuration from `Env::getDbConfig()`;
- masks sensitive account information in the panel;
- supports guarded connection testing;
- stores a project-scoped database Profile in `w_db_manager_project_profile`;
- encrypts saved database passwords, never echoes them back, and records only
  password state in the UI/audit log;
- can explicitly copy the selected source env profile password into the
  encrypted Project Profile after the operator enters `COPY_ENV_PASSWORD`;
- writes JSONL audit records to `var/log/wls/db-manager-audit.jsonl`;
- can optionally request `IpcControlGateway::reloadAsync()` for an
  operator-selected WLS instance after save;
- previews differences between the saved Project Profile and the persistent
  `db`/`db.master` config in `app/etc/env.php`;
- applies the enabled Project Profile into `app/etc/env.php` after creating a
  backup under `var/backups/wls/db-manager`;
- can restore the latest Database Manager env backup and reload the in-memory
  `Env` singleton after rollback.

Slave profile writes and richer child-project DB lifecycle actions such as
create database, user grants, backup/restore, and migration dry-runs remain
future slices.
