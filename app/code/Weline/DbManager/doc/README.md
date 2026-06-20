# WLS Database Manager Plan

## Scope

This module gives WLS Panel a real database-management plugin target. It keeps the first delivery small and safe: inspect current database profiles, pass project context from the panel, and run an operator-triggered connection test.

## Typed Tags

The module declares WLS plugin identity in `etc/marketplace/meta.json`:

| Tag | Purpose |
| --- | --- |
| `module:wls` | Makes the AppStore and WLS Panel treat this as a WLS plugin. |
| `custom:wls-database-manager` | Satisfies the WLS Panel `database-profile` operation slot. |
| `feature:database-profile` | Marks database profile UI capability. |
| `capability:database-read` | Marks read-only configuration summary support. |
| `capability:database-test` | Marks guarded connection-test support. |
| `capability:database-backup-dry-run` | Marks safe backup/restore planning support with execution disabled. |
| `capability:database-migration-dry-run` | Marks safe migration dry-run planning support with execution disabled. |
| `system:true` | Keeps the module in the system plugin family. |

## Stage 1 Behavior

- Route: `weline_dbmanager/backend/wls-db-manager`
- Reads effective DB config using `Env::getDbConfig()`.
- Supports `master`, direct default config, and `slaves`.
- Masks username and password state.
- Keeps the WLS Panel context: `operation`, `project_id`, `domain`, and `project_type`.
- Provides POST-only connection testing with method checks and sanitized error messages.

## Stage 2 Behavior

- Adds `Weline\DbManager\Model\WlsDatabaseProfile` with the
  `w_db_manager_project_profile` table.
- Saves project-level database Profiles by `profile_key`:
  `project:<project_id>`, `domain:<domain>`, or `local`.
- Uses the selected env DB profile as the inherited form baseline, then stores
  explicit project overrides.
- Encrypts saved passwords with a local WLS panel secret key and never renders
  the clear value back to the browser.
- Supports blank password input as "keep the existing encrypted value" and a
  separate clear-password checkbox.
- Supports explicit env password import: when the operator checks the import
  control and types `COPY_ENV_PASSWORD`, the selected source env profile
  password is copied into the encrypted Project Profile without rendering the
  clear value.
- Adds a recent audit feed backed by `var/log/wls/db-manager-audit.jsonl`.
- Adds an operator-selected runtime action:
  `none` only saves the Profile, while `reload` calls
  `IpcControlGateway::reloadAsync(<instance>, force)`.
- Allows the connection-test form to test the saved `Project Profile` after it
  is enabled.

## Stage 3 Behavior

- Adds `Weline\DbManager\Service\WlsDatabaseEnvApplyService` as the service
  boundary for env-backed writes.
- Reads the persistent `db` config from `app/etc/env.php` as the write target,
  even when the current runtime is showing effective `sandbox_db` profiles.
- Supports `db.master`, direct `db`, and guarded existing-slave writes. Slave
  writes are limited to a `db.slaves` entry that already exists in
  `app/etc/env.php`; this slice does not create, delete, or reorder slave
  entries.
- Reuses the saved Project Profile as the desired config and preserves the
  existing env password when the Profile has no encrypted password.
- Renders an Env Apply plan with target label, write mode, password source,
  latest backup, and a masked diff. Password values are never rendered, and
  password changes are shown only as state changes.
- Requires `APPLY_DB_ENV` plus an explicit checkbox before writing env.php.
- Creates backups under `var/backups/wls/db-manager` before writes and stores
  metadata next to each backup.
- Requires `ROLLBACK_DB_ENV` plus an explicit checkbox before restoring the
  selected backup, then calls `Env::reload()` so the current PHP process sees
  the restored config.
- Appends apply, rollback, no-op, and failure events to
  `var/log/wls/db-manager-audit.jsonl`.
- Keeps optional WLS reload as an operator-selected runtime action.

## Stage 4 Behavior

- Adds `Weline\DbManager\Service\WlsDatabaseLifecyclePlanService` as the
  service boundary for explicit DBA lifecycle intent.
- The standalone Database Manager shell renders a `Database Lifecycle Plan`
  section for `create_database`, `create_user`, and `grant_user`.
- The plan validates profile driver support, database identifiers, usernames,
  host scope, and password readiness before any future execution adapter can
  run.
- Execution is intentionally disabled in this slice. No database, user, grant,
  backup, env write, connection test, or WLS reload operation is executed from
  the lifecycle plan.
- Future vendor adapters must provide allowlisted SQL generation, confirmation
  phrases, audit records, verification queries, and rollback or cleanup
  guidance before the disabled action button can become executable.

## Stage 5 Behavior

- Adds `Weline\DbManager\Service\WlsDatabaseBackupPlanService` as the service
  boundary for database backup, restore, and migration dry-run intent.
- The standalone Database Manager shell renders a
  `Backup Restore And Migration Plan` section beside lifecycle and env apply.
- The plan supports `backup_database`, `restore_database`, and
  `migration_dry_run` previews.
- Backup planning captures scope (`schema_only`, `data_only`,
  `schema_and_data`), safe artifact names, profile source, driver, database
  target, and the future confined artifact directory:
  `var/backups/wls/db-manager/database`.
- Restore planning requires a safe artifact name and is always shown behind a
  destructive-operation boundary: preflight, pre-restore backup, confirmation
  phrase, audit records, verification probes, and rollback guidance must exist
  before execution can be enabled.
- Migration dry-run planning requires a safe migration target reference and
  stays free of DDL, DML, dump writes, restore writes, and WLS reload side
  effects.
- Execution is intentionally disabled in this slice. No database dump, restore,
  migration, file write, SQL, connection test, or WLS reload operation is
  executed from the backup plan.
- The module marketplace meta now declares
  `capability:database-backup-dry-run` and
  `capability:database-migration-dry-run` so WLS Panel/AppStore can distinguish
  the plugin's safe operations surface.

## Next Slices

- Add real mysql/pgsql lifecycle adapters for create database, create user, and
  grant user after the dry-run contract is covered by adapter-level tests or a
  controlled local DB harness.
- Add real mysql/pgsql backup, restore, and migration execution adapters after
  the dry-run contract is covered by adapter-level tests or a controlled local
  DB harness.
- Add explicit slave create/remove flows.
