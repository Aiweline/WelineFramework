# PostgreSQL Plain SQL Restore Schema Reset Adapter

## Status

Implemented first slice for public-schema-only restore execution. Whole
database reset and explicit schema-list reset remain out of scope.

## Problem

PostgreSQL custom-format artifacts can be restored with `pg_restore --clean`
inside the existing guarded restore adapter. Plain `.sql` / `.sql.gz` artifacts
are different: `psql` streams statements exactly as written and cannot provide a
safe, uniform cleanup boundary before replaying the dump.

WLS Database Manager executes plain SQL restore only after it resets the target
schema safely, proves the reset in an isolated database harness, and keeps a
recoverable pre-restore backup.

## Adapter Contract

The adapter may only run when all of these gates pass:

- The active action is `restore_database`.
- The enabled Project Database Profile uses `pgsql`.
- The artifact is a Database Manager `.sql` or `.sql.gz` artifact with matching
  metadata, checksum, driver, database, and byte size.
- Restore preflight has passed in the current request.
- A fresh pre-restore backup has been created as a PostgreSQL custom-format
  `.dump` artifact, not only as plain SQL.
- The operator confirms the existing `CHECK_DB_RESTORE` and `RUN_DB_RESTORE`
  gates plus the reset-specific confirmation phrase `RESET_PG_SCHEMA`.
- The target database name is a safe identifier and matches the artifact
  metadata.
- The target has no active non-current sessions, prepared transactions, or
  advisory locks owned by another restore operation.
- The schema inventory is acceptable for the chosen reset mode.

## Reset Modes

### Public Schema Only

Default and first implemented mode.

- Reset only the `public` schema.
- Block execution if user-owned non-system schemas exist.
- Preserve the Project Profile user as the recreated `public` owner and grant
  all schema privileges back to that user.
- Execute:
  - `DROP SCHEMA IF EXISTS public CASCADE`
  - `CREATE SCHEMA public AUTHORIZATION <profile_user>`
  - restore captured safe privileges for the profile user
- Then stream the artifact through `psql --single-transaction --set ON_ERROR_STOP=1`.

### Explicit Schema List

Later implementation candidate.

- Operator selects an allowlisted schema list from preflight output.
- Every schema must match the safe identifier pattern.
- The confirmation phrase must include the count of schemas to reset.
- Non-selected user schemas remain untouched.

### Whole Database

Not part of the first adapter. Dropping and recreating the database requires a
separate DBA connection, active-session termination policy, ownership reset, and
rollback path. It should remain a separate future task.

## Execution Flow

1. Rebuild the restore plan server-side.
2. Run restore preflight again.
3. Open a PostgreSQL admin connection to the target database with the enabled
   Project Profile.
4. Acquire a WLS-specific PostgreSQL advisory lock for the reset/replay span.
5. Query schema inventory from `pg_namespace`, excluding `pg_catalog`,
   `information_schema`, and `pg_toast`.
6. Block if unexpected user schemas exist in public-schema-only mode.
7. Create a fresh custom-format pre-restore backup with `pg_dump --format=custom`.
8. Reset the selected schemas.
9. Stream `.sql` or `.sql.gz` through `psql` using argv process execution,
   shell bypass, `PGPASSWORD`, `--single-transaction`, and
   `--set ON_ERROR_STOP=1`.
10. Verify connection health after replay.
11. Write sanitized audit records with reset mode, schema count, artifact name,
    checksum, pre-restore artifact, duration, and verification count.

## Failure Behavior

- If reset fails, do not stream the SQL artifact.
- If SQL replay fails after reset, report the failure and keep the custom
  pre-restore backup path in the audit record.
- Do not auto-rollback in the first implementation; rollback must remain an
  explicit guarded action using the pre-restore artifact.
- Never log passwords, raw command strings, SQL payloads, or full filesystem
  paths in audit records.

## UI Requirements

- PostgreSQL `.sql` / `.sql.gz` restore plans can show
  `ready_to_restore_execute` only when the plan also exposes
  `restore_reset_required=true`, `restore_reset_mode=public_schema`, and
  `restore_reset_confirmation_phrase=RESET_PG_SCHEMA`.
- The execution form must display reset mode and reset phrase before enabling
  submit.
- The button must remain disabled until all checkboxes and confirmation phrases
  are satisfied.
- The help copy must keep explaining that WLS reload, migration execution, SQL
  apply, and project-health remediation/deeper probes are outside this adapter.

## Validation Requirements

The implementation task is not complete without:

- PHP lint for touched service, controller, template, and task probes.
- A disposable PostgreSQL harness that creates user data, provides a Database
  Manager-compatible plain SQL artifact, mutates the database, executes
  reset+restore, verifies restored data, and verifies the custom-format
  pre-restore backup captured the mutated state.
- A failure harness for unexpected extra schemas.
- A failure harness for incorrect confirmation phrases.
- Audit inspection proving no credential, raw SQL, raw command, or absolute
  path leakage.
- Cleanup proof for generated artifacts, roles/databases, and containers.

Current proof: task
`2026-06-21-2017-wls-db-postgres-plain-restore-schema-reset`.
