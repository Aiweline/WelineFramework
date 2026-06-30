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
| `capability:database-backup-execute` | Marks guarded mysql/pgsql `backup_database` execution support. |
| `capability:database-restore-execute` | Marks guarded MySQL/MariaDB and PostgreSQL `restore_database` execution support. |
| `capability:database-migration-dry-run` | Marks safe migration dry-run planning support with execution disabled. |
| `capability:database-migration-preflight` | Marks read-only migration preflight with backup evidence and risk classification. |
| `capability:database-migration-execute` | Marks guarded MySQL/MariaDB migration import with backup-first execution. |
| `capability:database-sql-apply` | Marks guarded additive SQL Apply support with backup-first execution. |
| `capability:database-health-probe` | Marks guarded read-only Project Health connection probe support. |
| `system:true` | Keeps the module in the system plugin family. |

## Related Design Notes

- [PostgreSQL Plain SQL Restore Schema Reset Adapter](postgresql-plain-sql-restore-schema-reset-adapter.md)
  defines the implemented public-schema reset safety gate for PostgreSQL
  `.sql` / `.sql.gz` restore execution, plus the future reset modes that remain
  out of scope.

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
- Supports `db.master`, direct `db`, and guarded existing-slave writes. Normal
  Env Apply is limited to updating a `db.slaves` entry that already exists in
  `app/etc/env.php`.
- Adds an explicit Slave Create / Remove panel path for `db.slaves` structural
  changes. Creating requires `CREATE_DB_SLAVE`, a safe new slave key, an enabled
  Project Database Profile, and a backup-first env write. Removing requires
  `REMOVE_DB_SLAVE`, selects only an existing slave entry, and also creates a
  backup before writing.
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
- Adds `Weline\DbManager\Service\Adapter\WlsDatabaseLifecycleSqlPlanAdapter`
  as the vendor-aware SQL plan boundary. It generates mysql/pgsql SQL
  statements, verification queries, rollback or cleanup guidance, an audit
  event name, and the `RUN_DB_LIFECYCLE` confirmation phrase.
- Adds `Weline\DbManager\Service\WlsDatabaseLifecycleExecutionService` and the
  `weline_dbmanager/backend/wls-db-manager/lifecycle-execute` POST route as the
  first real lifecycle execution boundary.
- The standalone Database Manager shell renders a `Database Lifecycle Plan`
  section for `create_database`, `create_user`, and `grant_user`.
- The plan validates profile driver support, database identifiers, usernames,
  host scope, password readiness, adapter plan state, and enabled Project
  Profile state before execution is exposed.
- Execution requires a POST request, ACL permission, the explicit checkbox,
  the exact `RUN_DB_LIFECYCLE` phrase, a server-side rebuilt adapter SQL plan,
  and an enabled Project Profile. The source env profile is used as the DBA
  connection, while the Project Profile supplies the target password for
  `create_user`.
- Successful execution appends `lifecycle_executed` audit records with action,
  driver, masked user, statement count, and verification count. Failures append
  `lifecycle_execute_failed` records with sanitized error text. Cleartext
  passwords and full executable SQL are not written into audit records.
- The adapter revalidates allowlisted database/user names and blocks unsafe
  SQL planning before any statement is rendered. MySQL plans require an
  explicit host scope for user actions; PostgreSQL plans keep host scope only as
  panel context.
- MySQL/MariaDB lifecycle success-path proof now exists in task
  `2026-06-21-1658-wls-db-lifecycle-mysql-docker-success-harness`: the
  existing execution service created a disposable database, created a
  disposable user, granted access, verified that the new user could
  create/insert/read data, wrote sanitized `lifecycle_executed` audit events,
  and cleaned up the database, user, and container.
- PostgreSQL lifecycle success-path proof now exists in task
  `2026-06-21-1809-wls-db-postgres-lifecycle-harness-proof`: the harness first
  exposed a real `public` schema permission gap, then the execution service and
  adapter were tightened so PostgreSQL schema/table/sequence grants run against
  the target database. The final disposable PostgreSQL 15 proof creates a
  database, creates a role, grants access, verifies target-role
  create/insert/read behavior, writes sanitized `lifecycle_executed` audit
  events, and cleans up the database, role, and container.
- This slice still does not execute backup, restore, migration, slave
  create/remove, destructive database/user deletion, env writes, or WLS reloads
  from the lifecycle form.

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
- Backup execution stays disabled unless the plan is a mysql/pgsql
  `backup_database` plan backed by an enabled Project Profile and a safe
  executable artifact. MySQL execution supports `.sql` and `.sql.gz`;
  PostgreSQL execution supports `.sql`, `.sql.gz`, `.dump`, and `.backup`.
  Restore execution stays disabled except for the later guarded restore slices;
  migration execution and WLS reload side effects remain disabled from this
  section. SQL Apply is handled by the separate guarded Stage 11 form.
- The module marketplace meta now declares
  `capability:database-backup-dry-run` and
  `capability:database-backup-execute` plus
  `capability:database-restore-execute` plus
  `capability:database-migration-dry-run`,
  `capability:database-migration-preflight`,
  `capability:database-migration-execute`,
  `capability:database-sql-apply`, and
  `capability:database-health-probe` so WLS Panel/AppStore can distinguish the
  plugin's safe operations surface.

## Stage 6 Behavior

- Adds `Weline\DbManager\Service\WlsDatabaseBackupExecutionService` as the
  guarded execution boundary for mysql/pgsql `backup_database` only.
- The standalone Database Manager shell renders a POST-only
  `Run Database Backup` form when the server-side plan is executable.
- Execution requires both a checkbox confirmation and the exact phrase
  `RUN_DB_BACKUP`. The controller rebuilds the plan from submitted input before
  calling the execution service.
- The service reloads the enabled Project Profile connection, passes the
  password through `PGPASSWORD` for PostgreSQL or `MYSQL_PWD` for MySQL,
  invokes `pg_dump`/`mysqldump` through an argv array with shell bypass, and
  never places the database password in command arguments.
- Backup artifacts are confined to
  `var/backups/wls/db-manager/database`, must use a safe executable filename,
  and cannot overwrite an existing artifact. MySQL artifacts are limited to
  `.sql` and `.sql.gz`; PostgreSQL custom-format `.dump`/`.backup` artifacts
  stay PostgreSQL-only.
- `.sql.gz` artifacts are created by dumping to a temporary plain SQL file in
  the same confined directory, streaming it through PHP `zlib`, writing the
  final gzip artifact, and deleting the temporary SQL file before returning.
- Successful execution writes JSON metadata next to the artifact with size,
  SHA-256 checksum, duration, driver, scope, host, port, database, compression
  state, and masked username. Failures delete partial artifacts before metadata
  is written.
- Audit records use `backup_executed` and `backup_execute_failed` with
  sanitized payloads. Cleartext passwords and raw command strings are not
  written to audit records.
- Restore, migration dry-run, SQL apply, and WLS reload remain disabled from
  this execution path.

## Stage 7 Behavior

- Adds `Weline\DbManager\Service\WlsDatabaseRestorePreflightService` as the
  read-only restore preflight boundary for `restore_database`.
- Adds `Weline\DbManager\Service\WlsDatabaseRestoreExecutionService` as the
  guarded destructive restore boundary for MySQL/MariaDB `.sql` / `.sql.gz`
  artifacts, PostgreSQL custom-format `.dump` / `.backup` artifacts, and
  PostgreSQL plain `.sql` / `.sql.gz` artifacts behind a public-schema reset
  adapter.
- Adds
  `Weline\DbManager\Service\Adapter\WlsDatabasePostgreSqlPlainRestoreAdapter`
  as the vendor-specific adapter for PostgreSQL plain SQL restore safety
  checks, `public` schema reset, and `psql` single-transaction replay.
- The standalone Database Manager shell renders a separate
  `Run Restore Preflight` form when the server-side restore plan is
  `ready_to_preflight`, and a separate
  `Database Restore Execution Boundary` form when the server-side restore plan
  is `ready_to_restore_execute`.
- Preflight requires both a checkbox confirmation and the exact phrase
  `CHECK_DB_RESTORE`. Destructive restore additionally requires an execution
  checkbox plus the exact `RUN_DB_RESTORE` phrase. The controller rebuilds the
  submitted restore plan before choosing preflight or execution.
- The service only resolves an existing artifact inside
  `var/backups/wls/db-manager/database`, reads its adjacent JSON metadata,
  recalculates SHA-256, verifies size, driver, database, artifact name, and
  readability, and then appends a sanitized audit event.
- MySQL restore preflight accepts `.sql` and `.sql.gz`; PostgreSQL restore
  preflight accepts `.sql`, `.sql.gz`, `.dump`, and `.backup`.
- MySQL/MariaDB restore execution re-runs the preflight, creates a fresh
  pre-restore backup through the guarded backup execution service, streams the
  selected artifact into `mysql`/`mariadb` through an argv process with shell
  bypass and password in `MYSQL_PWD`, then verifies the target connection.
- PostgreSQL custom-format restore execution re-runs the same preflight,
  creates the same fresh pre-restore backup, runs `pg_restore --clean
  --if-exists --no-owner --exit-on-error --single-transaction` through an argv
  process with shell bypass and password in `PGPASSWORD`, then verifies the
  target connection.
- PostgreSQL plain SQL restore execution re-runs the same preflight, requires
  `CHECK_DB_RESTORE`, `RUN_DB_RESTORE`, and `RESET_PG_SCHEMA`, creates a fresh
  PostgreSQL custom-format `.dump` pre-restore backup, blocks extra user
  schemas, active non-idle sessions, prepared transactions, and other advisory
  locks, resets only the `public` schema, streams `.sql` / `.sql.gz` through
  `psql --single-transaction --set=ON_ERROR_STOP=1`, then verifies the target
  connection.
- Audit records use `restore_preflight_passed`,
  `restore_preflight_failed`, `restore_executed`, and
  `restore_execute_failed`. Cleartext passwords and raw command strings are not
  written to audit records.
- MySQL/MariaDB restore success-path proof now exists in task
  `2026-06-21-1714-wls-db-restore-execution-boundary`: a disposable MariaDB
  11.4 database plus ephemeral PHP client created a source backup, mutated the
  database, executed restore behind `CHECK_DB_RESTORE` and `RUN_DB_RESTORE`,
  verified the restored rows `alpha,beta`, proved the pre-restore backup
  captured the mutated `gamma` state, recorded the expected sanitized audit
  chain, and cleaned up artifacts, database, user, and container.
- PostgreSQL custom-format restore success-path proof now exists in task
  `2026-06-21-1746-wls-db-postgres-restore-execution`: a disposable
  PostgreSQL 15 database plus ephemeral PHP client created a custom `.dump`
  backup, mutated the database, executed restore behind `CHECK_DB_RESTORE` and
  `RUN_DB_RESTORE`, verified the restored rows `alpha,beta`, proved PostgreSQL
  plain `.sql` restore remains preflight-only, recorded the expected sanitized
  audit chain, and cleaned up artifacts, database, user, and container.
- PostgreSQL plain SQL restore safety-contract proof now exists in task
  `2026-06-21-1926-wls-db-pgsql-plain-restore-safety-contract`: a focused plan
  probe verifies `.sql` returns `ready_to_preflight` with
  `can_restore_execute=false`, while `.dump` remains
  `ready_to_restore_execute`.
- PostgreSQL plain SQL restore reset-adapter proof now exists in task
  `2026-06-21-2017-wls-db-postgres-plain-restore-schema-reset`: a disposable
  PostgreSQL 15 harness creates a Database Manager-compatible plain `.sql`
  artifact, mutates the target with `gamma`, executes restore behind
  `CHECK_DB_RESTORE`, `RUN_DB_RESTORE`, and `RESET_PG_SCHEMA`, verifies rows are
  restored to `alpha,beta`, verifies the custom `.dump` pre-restore artifact
  captured `gamma`, checks sanitized audit events, and cleans up the container
  and generated artifacts.
- Explicit restore rollback automation now exists in task
  `2026-06-22-1128-wls-db-restore-rollback-automation`: rollback is offered
  only for a recent `restore_executed` audit record with a validated
  `pre_restore_artifact`, requires `ROLLBACK_DB_RESTORE`, preserves the
  PostgreSQL `RESET_PG_SCHEMA` gate for plain SQL artifacts, appends sanitized
  rollback audit events, and has guard-probe plus logged-in browser proof.
- WLS reload, project-health remediation/deeper probes, full project-code
  migration runners, schema diff execution, and
  PostgreSQL reset modes beyond public schema remain disabled until later
  adapter slices provide disposable harness evidence. SQL Apply is covered by
  the guarded Stage 11 slice, and MySQL/MariaDB backup-artifact migration
  import is covered by Stage 12.

## Stage 8 Behavior

- Adds `Weline\DbManager\Service\WlsDatabaseMigrationPreflightService` as the
  read-only migration preflight boundary for `migration_dry_run`.
- The standalone Database Manager shell renders a separate
  `Run Migration Preflight` form when the server-side migration plan is
  `ready_to_migration_preflight`.
- Preflight requires both a checkbox confirmation and the exact phrase
  `CHECK_DB_MIGRATION`. The controller rebuilds the submitted migration plan
  before calling the preflight service.
- The service reloads the enabled Project Profile configuration through the
  same profile boundary as restore preflight, then validates driver support,
  connection field readiness, safe database identifier, and submitted driver
  consistency without opening a PDO connection or running SQL.
- The selected backup artifact must already exist inside
  `var/backups/wls/db-manager/database`, must match the driver-specific
  artifact extension rules, and must include adjacent JSON metadata from a
  Database Manager backup.
- The service recalculates SHA-256, verifies byte size, driver, database, and
  artifact name against metadata, probes artifact readability, and classifies
  the migration target as `release_reference`, `schema_review`,
  `data_review`, or `requires_review`. Targets containing destructive
  keywords such as `drop`, `truncate`, or `delete` are blocked before
  preflight can pass.
- Audit records use `migration_preflight_passed` and
  `migration_preflight_failed`. For MySQL/MariaDB artifacts, a later Stage 12
  form can re-run this preflight and execute a guarded import; PostgreSQL
  migration execution, schema diff execution, project-code migration runners,
  cleanup automation, and WLS reload remain outside this preflight slice. SQL
  Apply is handled by the separate guarded Stage 11 form.

## Stage 9 Behavior

- Adds `Weline\DbManager\Service\WlsDatabaseProjectHealthService` as a
  read-only health summary boundary for the standalone Database Manager shell.
- The shell renders a `Project Health` section and sidebar entry before the
  project-context editor. It reports the selected profile, Project Profile,
  driver runtime, backup directory, env backup state, backup/migration/restore
  plan, and slave profile coverage.
- The summary produces a normalized `ready`, `attention`, or `blocked` state,
  plus counters and suggested next actions. It uses only already loaded panel
  data and filesystem metadata; it does not open a database connection or run
  SQL.
- The section is intentionally safe-by-default: no migration runner, SQL apply,
  restore, rollback, env write, or WLS reload is triggered from the health
  summary.
- Browser proof now exists in task
  `2026-06-22-0535-wls-db-project-health-summary`: dedicated instance
  `ai-test-wls-db-health-10035` rendered `#project-health` across desktop and
  mobile in light/dark themes with seven health checks, read-only copy, no
  fatal text, no horizontal overflow, and cleanup left port `10035` closed.

## Stage 10 Behavior

- Adds `Weline\DbManager\Service\WlsDatabaseConnectionProbeService` as the
  shared active connection-probe boundary for the existing connection test and
  the Project Health probe.
- The Project Health probe is POST-only, requires both the checkbox and the
  exact `CHECK_DB_HEALTH` phrase, and can target either the enabled Project
  Profile or a selected env database profile.
- The probe opens a short PDO connection with a 3 second timeout, executes only
  `SELECT 1`, records driver/duration/status into sanitized audit, and never
  runs migrations, applies SQL, restores data, writes `app/etc/env.php`, or
  reloads WLS.
- The module marketplace meta declares `capability:database-health-probe` and
  `database.health_probe` so the WLS Panel/AppStore can identify that this
  plugin exposes a guarded active-read diagnostic.
- Browser proof now exists in task
  `2026-06-21-2152-wls-db-health-active-probe`: dedicated instance
  `ai-test-wls-db-health-probe-10036` rendered the guarded Project Health
  probe form across desktop and mobile in light/dark themes, with no fatal
  text, no horizontal overflow, `CHECK_DB_HEALTH` guard copy, and the
  unconfirmed submit returning a guard error instead of running a probe.
- Browser success proof now exists in task
  `2026-06-21-2228-wls-db-health-browser-success-probe`: dedicated instance
  `ai-test-wls-db-health-success-10037` submitted the `Master` profile through
  the standalone panel with checkbox confirmation plus `CHECK_DB_HEALTH`,
  returned visible success copy, kept desktop/mobile light/dark layouts free of
  horizontal overflow, and wrote a sanitized `health_probe` audit event with no
  credential or confirmation phrase fields.

## Stage 11 Behavior

- Adds `Weline\DbManager\Service\WlsDatabaseSqlApplyExecutionService` as the
  guarded SQL Apply boundary for reviewed `.sql` / `.sql.gz` artifacts.
- The shared backup/restore/migration plan section now supports `sql_apply`
  and can reach `ready_to_sql_apply` only for an enabled mysql/pgsql Project
  Profile with a safe SQL artifact name inside
  `var/backups/wls/db-manager/database`.
- The standalone Database Manager shell renders a separate
  `Database SQL Apply Boundary` form guarded by the exact
  `RUN_DB_SQL_APPLY` phrase plus checkbox confirmation.
- Execution re-resolves the artifact inside the WLS backup directory, rejects
  oversized files, splits statements server-side, blocks destructive or
  data-touching SQL, and allows only additive `CREATE TABLE`, `CREATE INDEX`,
  or `ALTER TABLE ADD` statements in this first slice.
- Before applying SQL, the service creates a fresh schema/data pre-apply
  backup through `WlsDatabaseBackupExecutionService`. PostgreSQL uses a custom
  `.backup` safety artifact; MySQL/MariaDB uses `.sql`.
- SQL is applied through the selected PDO driver, followed by a verification
  `SELECT 1`. Successful execution appends `sql_apply_executed` audit records
  with artifact checksum, statement count, pre-apply backup artifact, duration,
  and no raw SQL or credentials. Failures append `sql_apply_failed` with
  sanitized error text and any pre-apply artifact reference.
- Browser proof now exists in task
  `2026-06-22-0415-wls-db-sql-apply-guarded-adapter`: dedicated instance
  `ai-test-wls-db-sql-apply-10041` rendered the ready SQL Apply form across
  desktop and mobile in light/dark themes with `RUN_DB_SQL_APPLY`, additive
  allowlist copy, enabled submit button, no fatal text, no horizontal overflow,
  and stopped/port-closed cleanup. The browser smoke deliberately did not
  submit the SQL Apply form against a production database.
- Disposable execution proof also exists in the same task: a MariaDB 11.4
  Docker harness applied 3 additive DDL statements through PDO after creating
  a real pre-apply backup, verified row readback, recorded sanitized
  `backup_executed` and `sql_apply_executed` audit events, and removed its
  container/network after the run.

## Stage 12 Behavior

- Adds `Weline\DbManager\Service\WlsDatabaseMigrationExecutionService` as the
  first guarded migration execution boundary.
- The shared backup/restore/migration plan section can now reach
  `ready_to_migration_execute` only for an enabled MySQL/MariaDB Project
  Profile, a safe migration target reference, and an existing `.sql` or
  `.sql.gz` Database Manager backup artifact with matching metadata.
- The standalone Database Manager shell renders a separate
  `Database Migration Execution Boundary` form guarded by both
  `CHECK_DB_MIGRATION` and `RUN_DB_MIGRATION` plus checkbox confirmations.
- Execution re-runs `WlsDatabaseMigrationPreflightService`, re-resolves the
  artifact inside `var/backups/wls/db-manager/database`, compares checksum,
  creates a fresh schema/data pre-migration backup through
  `WlsDatabaseBackupExecutionService`, streams the verified artifact into the
  `mysql` / `mariadb` client with shell bypass and `MYSQL_PWD`, then verifies
  the target connection with `SELECT 1`.
- Audit records use `migration_executed` and `migration_execute_failed` with
  sanitized payloads containing artifact name, SHA-256, risk classification,
  pre-migration backup artifact, adapter, duration, and verification count.
  Cleartext credentials, raw command strings, inline SQL, and arbitrary paths
  are not written to audit records.
- This slice does not run project-code migration scripts, schema diff
  generators, arbitrary SQL, PostgreSQL migration execution, rollback
  automation, cleanup automation, or WLS reload. Those remain separate guarded
  slices with their own harness requirements.

## Next Slices

- MySQL/MariaDB lifecycle execution success-path proof now exists in task
  `2026-06-21-1658-wls-db-lifecycle-mysql-docker-success-harness`, and
  PostgreSQL lifecycle execution success-path proof now exists in task
  `2026-06-21-1809-wls-db-postgres-lifecycle-harness-proof`.
- MySQL backup success-path proof now exists in task
  `2026-06-21-1635-wls-dbmanager-mysql-backup-docker-success-harness`: a
  disposable MariaDB 11.4 database plus ephemeral PHP client generated a real
  `schema_and_data` `.sql` backup, verified seeded table data, metadata,
  checksum, sanitized audit, artifact cleanup, and container cleanup.
- MySQL/MariaDB restore execution success-path proof now exists in task
  `2026-06-21-1714-wls-db-restore-execution-boundary`; PostgreSQL
  custom-format restore execution success-path proof now exists in task
  `2026-06-21-1746-wls-db-postgres-restore-execution`; PostgreSQL plain SQL
  reset-adapter proof now exists in task
  `2026-06-21-2017-wls-db-postgres-plain-restore-schema-reset`. Explicit
  restore rollback proof now exists in
  `2026-06-22-1128-wls-db-restore-rollback-automation`. Guarded SQL Apply UI
  and disposable MariaDB execution proof now exists in
  `2026-06-22-0415-wls-db-sql-apply-guarded-adapter`. Guarded MySQL/MariaDB
  migration import implementation is tracked by
  `2026-06-22-0504-wls-db-migration-execution-guarded-adapter`. Continue with
  disposable migration execution proof, project-health remediation/deeper
  probes, WLS reload controls, and broader UI browser acceptance slices.
