<?php
declare(strict_types=1);

namespace Weline\DbManager\Service;

class WlsDatabaseBackupPlanService
{
    private const ACTION_BACKUP_DATABASE = 'backup_database';
    private const ACTION_RESTORE_DATABASE = 'restore_database';
    private const ACTION_MIGRATION_DRY_RUN = 'migration_dry_run';
    private const ACTION_SQL_APPLY = 'sql_apply';
    private const DRIVER_MYSQL = 'mysql';
    private const DRIVER_PGSQL = 'pgsql';
    private const DRIVER_SQLITE = 'sqlite';
    private const CONFIRMATION_PHRASE = 'RUN_DB_BACKUP';
    private const RESTORE_PREFLIGHT_PHRASE = 'CHECK_DB_RESTORE';
    private const RESTORE_EXECUTION_PHRASE = 'RUN_DB_RESTORE';
    private const RESTORE_RESET_PHRASE = 'RESET_PG_SCHEMA';
    private const MIGRATION_PREFLIGHT_PHRASE = 'CHECK_DB_MIGRATION';
    private const MIGRATION_EXECUTION_PHRASE = 'RUN_DB_MIGRATION';
    private const SQL_APPLY_PHRASE = 'RUN_DB_SQL_APPLY';

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $projectProfile
     * @param array<string, mixed> $selectedProfile
     * @return array<string, mixed>
     */
    public function buildPlan(array $input, array $projectProfile, array $selectedProfile = []): array
    {
        $action = $this->normalizeAction((string)($input['backup_action'] ?? ''));
        $scope = $this->normalizeScope((string)($input['backup_scope'] ?? 'schema_and_data'));
        $profile = $this->effectiveProfile($projectProfile, $selectedProfile);
        $driver = $this->normalizeDriver((string)($profile['type'] ?? ''));
        $database = $this->normalizeIdentifier((string)($profile['database'] ?? ''));
        $path = $this->normalizePath((string)($profile['path'] ?? ''));
        $artifactInput = (string)($input['backup_artifact'] ?? '');
        $artifact = $this->resolveArtifactName($action, $artifactInput, $database);
        $migrationTarget = $this->normalizeMigrationTarget((string)($input['migration_target'] ?? ''));
        $requiresPostgreSqlSchemaReset = $action === self::ACTION_RESTORE_DATABASE
            && $driver === self::DRIVER_PGSQL
            && $this->isPostgreSqlPlainSqlRestoreArtifact($artifact);

        if ($action === '') {
            return $this->plan([
                'action' => '',
                'action_label' => (string)__('No Action Selected'),
                'scope' => $scope,
                'scope_label' => $this->scopeLabel($scope),
                'profile' => $profile,
                'driver' => $driver,
                'database' => $database,
                'path' => $path,
                'artifact' => $artifact,
                'migration_target' => $migrationTarget,
                'state' => 'idle',
                'state_label' => (string)__('Waiting for Input'),
                'errors' => [],
                'warnings' => [],
            ]);
        }

        $errors = [];
        $warnings = [];

        if (!$this->supportsDriver($driver)) {
            $errors[] = (string)__('Database backup, restore, migration, and SQL apply planning currently require mysql or pgsql profile drivers.');
        }

        if ($database === '' && $driver !== self::DRIVER_SQLITE) {
            $errors[] = (string)__('Database name is required before building a backup, restore, migration, or SQL apply plan.');
        }

        if ($database !== '' && !$this->isSafeIdentifier($database)) {
            $errors[] = (string)__('Database name must start with a letter or underscore and contain only letters, numbers, and underscores.');
        }

        if (($profile['source'] ?? '') !== 'project_profile') {
            $warnings[] = (string)__('This preview is using the selected env profile. Backup execution requires an enabled Project Profile.');
        }

        if ($action === self::ACTION_RESTORE_DATABASE) {
            if (\trim($artifactInput) === '') {
                $errors[] = (string)__('Restore planning requires a backup artifact name.');
            } elseif (!$this->isSafeArtifactName($artifact)) {
                $errors[] = (string)__('Backup artifact must be a safe file name ending in .sql, .sql.gz, .dump, or .backup.');
            }
            $warnings[] = (string)__('Restore is destructive and must stay behind preflight, pre-restore backup, confirmation phrase, and audit logging before execution is enabled.');
            if ($requiresPostgreSqlSchemaReset) {
                $warnings[] = (string)__('PostgreSQL plain SQL restore requires RESET_PG_SCHEMA and resets only the public schema; unexpected user schemas block execution.');
            }
        }

        if ($action === self::ACTION_MIGRATION_DRY_RUN) {
            if ($migrationTarget === '') {
                $errors[] = (string)__('Migration dry-run requires a target profile, branch, tag, or migration reference.');
            } elseif (!$this->isSafeMigrationTarget($migrationTarget)) {
                $errors[] = (string)__('Migration target may contain only letters, numbers, underscores, dots, colons, and dashes.');
            }

            if (\trim($artifactInput) === '') {
                $warnings[] = (string)__('Migration preflight requires a verified backup artifact before it can run.');
            } elseif (!$this->isSafeArtifactName($artifact)) {
                $errors[] = (string)__('Backup artifact must be a safe file name ending in .sql, .sql.gz, .dump, or .backup.');
            } elseif (!$this->isRestorePreflightArtifact($artifact, $driver)) {
                $warnings[] = $this->migrationArtifactHelp($driver);
            }
            $warnings[] = (string)__('Migration preflight is read-only; MySQL/MariaDB execution is a separate guarded import path that creates a fresh pre-migration backup first.');
        }

        if ($action === self::ACTION_SQL_APPLY) {
            if (\trim($artifactInput) === '') {
                $errors[] = (string)__('SQL apply planning requires a reviewed SQL artifact name.');
            } elseif (!$this->isSqlApplyArtifact($artifact)) {
                $errors[] = (string)__('SQL apply artifact must be a safe file name ending in .sql or .sql.gz.');
            }
            $warnings[] = (string)__('SQL apply executes only additive allowlisted DDL and creates a fresh pre-apply schema/data backup before execution.');
        }

        if ($action === self::ACTION_BACKUP_DATABASE && !$this->isSafeArtifactName($artifact)) {
            $errors[] = (string)__('Generated backup artifact name is not safe; adjust the database name or artifact label.');
        }

        $blocked = $errors !== [];
        $canExecute = !$blocked
            && $action === self::ACTION_BACKUP_DATABASE
            && $this->supportsBackupExecutionDriver($driver)
            && ($profile['source'] ?? '') === 'project_profile'
            && $this->isExecutableBackupArtifact($artifact, $driver);
        $canPreflight = !$blocked
            && $action === self::ACTION_RESTORE_DATABASE
            && $this->supportsBackupExecutionDriver($driver)
            && ($profile['source'] ?? '') === 'project_profile'
            && $this->isRestorePreflightArtifact($artifact, $driver);
        $canRestoreExecute = $canPreflight
            && $this->supportsRestoreExecutionDriver($driver)
            && $this->isExecutableRestoreArtifact($artifact, $driver);
        $canMigrationPreflight = !$blocked
            && $action === self::ACTION_MIGRATION_DRY_RUN
            && $this->supportsBackupExecutionDriver($driver)
            && ($profile['source'] ?? '') === 'project_profile'
            && $migrationTarget !== ''
            && $this->isSafeMigrationTarget($migrationTarget)
            && $this->isRestorePreflightArtifact($artifact, $driver);
        $canMigrationExecute = $canMigrationPreflight
            && $this->supportsMigrationExecutionDriver($driver)
            && $this->isExecutableMigrationArtifact($artifact, $driver);
        $canSqlApplyExecute = !$blocked
            && $action === self::ACTION_SQL_APPLY
            && $this->supportsSqlApplyDriver($driver)
            && ($profile['source'] ?? '') === 'project_profile'
            && $this->isSqlApplyArtifact($artifact);
        if ($action === self::ACTION_BACKUP_DATABASE && $this->supportsDriver($driver) && !$this->supportsBackupExecutionDriver($driver)) {
            $warnings[] = (string)__('This execution slice does not have a backup execution adapter for the selected driver yet.');
        }
        if ($action === self::ACTION_BACKUP_DATABASE && $this->isSafeArtifactName($artifact) && !$this->isExecutableBackupArtifact($artifact, $driver)) {
            $warnings[] = $this->backupArtifactHelp($driver);
        }
        if ($action === self::ACTION_RESTORE_DATABASE && $this->isSafeArtifactName($artifact) && !$this->isRestorePreflightArtifact($artifact, $driver)) {
            $warnings[] = $this->restoreArtifactHelp($driver);
        }
        if ($action === self::ACTION_SQL_APPLY && $this->isSafeArtifactName($artifact) && !$this->isSqlApplyArtifact($artifact)) {
            $warnings[] = $this->sqlApplyArtifactHelp();
        }
        if ($action === self::ACTION_MIGRATION_DRY_RUN && $this->isSafeArtifactName($artifact) && !$this->supportsMigrationExecutionDriver($driver)) {
            $warnings[] = (string)__('Migration execution is currently enabled only for MySQL/MariaDB .sql and .sql.gz backup artifacts; this driver remains preflight-only.');
        }
        $state = 'dry_run_only';
        $stateLabel = (string)__('Dry Run Only');
        if ($blocked) {
            $state = 'blocked';
            $stateLabel = (string)__('Blocked');
        } elseif ($canExecute) {
            $state = 'ready_to_execute';
            $stateLabel = (string)__('Ready To Execute');
        } elseif ($canRestoreExecute) {
            $state = 'ready_to_restore_execute';
            $stateLabel = (string)__('Ready To Restore');
        } elseif ($canPreflight) {
            $state = 'ready_to_preflight';
            $stateLabel = (string)__('Ready To Preflight');
        } elseif ($canMigrationExecute) {
            $state = 'ready_to_migration_execute';
            $stateLabel = (string)__('Ready To Migrate');
        } elseif ($canMigrationPreflight) {
            $state = 'ready_to_migration_preflight';
            $stateLabel = (string)__('Ready To Preflight');
        } elseif ($canSqlApplyExecute) {
            $state = 'ready_to_sql_apply';
            $stateLabel = (string)__('Ready To Apply');
        }

        return $this->plan([
            'action' => $action,
            'action_label' => $this->actionLabel($action),
            'scope' => $scope,
            'scope_label' => $this->scopeLabel($scope),
            'profile' => $profile,
            'driver' => $driver,
            'database' => $database,
            'path' => $path,
            'artifact' => $artifact,
            'migration_target' => $migrationTarget,
            'state' => $state,
            'state_label' => $stateLabel,
            'execution_label' => $this->executionLabel($action, $blocked, $canExecute, $canMigrationExecute, $canSqlApplyExecute, $driver, $profile, $artifact, $migrationTarget),
            'can_execute' => $canExecute,
            'can_preflight' => $canPreflight,
            'can_restore_execute' => $canRestoreExecute,
            'can_migration_preflight' => $canMigrationPreflight,
            'can_migration_execute' => $canMigrationExecute,
            'can_sql_apply_execute' => $canSqlApplyExecute,
            'confirmation_phrase' => self::CONFIRMATION_PHRASE,
            'preflight_confirmation_phrase' => self::RESTORE_PREFLIGHT_PHRASE,
            'restore_execution_confirmation_phrase' => self::RESTORE_EXECUTION_PHRASE,
            'restore_reset_required' => $requiresPostgreSqlSchemaReset && $canRestoreExecute,
            'restore_reset_mode' => $requiresPostgreSqlSchemaReset ? 'public_schema' : '',
            'restore_reset_confirmation_phrase' => self::RESTORE_RESET_PHRASE,
            'migration_preflight_confirmation_phrase' => self::MIGRATION_PREFLIGHT_PHRASE,
            'migration_execution_confirmation_phrase' => self::MIGRATION_EXECUTION_PHRASE,
            'sql_apply_confirmation_phrase' => self::SQL_APPLY_PHRASE,
            'errors' => $errors,
            'warnings' => $warnings,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function plan(array $data): array
    {
        $action = (string)($data['action'] ?? '');
        $scope = (string)($data['scope'] ?? 'schema_and_data');
        $profile = \is_array($data['profile'] ?? null) ? $data['profile'] : [];
        $driver = (string)($data['driver'] ?? '');
        $database = (string)($data['database'] ?? '');
        $path = (string)($data['path'] ?? '');
        $artifact = (string)($data['artifact'] ?? '');
        $migrationTarget = (string)($data['migration_target'] ?? '');
        $state = (string)($data['state'] ?? 'idle');
        $canPreflight = !empty($data['can_preflight']);
        $canRestoreExecute = !empty($data['can_restore_execute']);
        $canMigrationPreflight = !empty($data['can_migration_preflight']);
        $canMigrationExecute = !empty($data['can_migration_execute']);
        $canSqlApplyExecute = !empty($data['can_sql_apply_execute']);
        $restoreResetRequired = !empty($data['restore_reset_required']);

        return [
            'action' => $action,
            'action_label' => (string)($data['action_label'] ?? $this->actionLabel($action)),
            'scope' => $scope,
            'scope_label' => (string)($data['scope_label'] ?? $this->scopeLabel($scope)),
            'profile' => $profile,
            'profile_source_label' => (string)($profile['source_label'] ?? __('No Profile')),
            'driver' => $driver,
            'database' => $database,
            'path' => $path,
            'artifact' => $artifact,
            'artifact_dir_label' => 'var/backups/wls/db-manager/database',
            'migration_target' => $migrationTarget,
            'state' => $state,
            'state_label' => (string)($data['state_label'] ?? __('Waiting for Input')),
            'execution_label' => (string)($data['execution_label'] ?? __('Execution disabled in this slice')),
            'can_execute' => !empty($data['can_execute']),
            'can_preflight' => $canPreflight,
            'can_restore_execute' => $canRestoreExecute,
            'can_migration_preflight' => $canMigrationPreflight,
            'can_migration_execute' => $canMigrationExecute,
            'can_sql_apply_execute' => $canSqlApplyExecute,
            'confirmation_phrase' => (string)($data['confirmation_phrase'] ?? self::CONFIRMATION_PHRASE),
            'preflight_confirmation_phrase' => (string)($data['preflight_confirmation_phrase'] ?? self::RESTORE_PREFLIGHT_PHRASE),
            'restore_execution_confirmation_phrase' => (string)($data['restore_execution_confirmation_phrase'] ?? self::RESTORE_EXECUTION_PHRASE),
            'restore_reset_required' => $restoreResetRequired,
            'restore_reset_mode' => (string)($data['restore_reset_mode'] ?? ''),
            'restore_reset_confirmation_phrase' => (string)($data['restore_reset_confirmation_phrase'] ?? self::RESTORE_RESET_PHRASE),
            'migration_preflight_confirmation_phrase' => (string)($data['migration_preflight_confirmation_phrase'] ?? self::MIGRATION_PREFLIGHT_PHRASE),
            'migration_execution_confirmation_phrase' => (string)($data['migration_execution_confirmation_phrase'] ?? self::MIGRATION_EXECUTION_PHRASE),
            'sql_apply_confirmation_phrase' => (string)($data['sql_apply_confirmation_phrase'] ?? self::SQL_APPLY_PHRASE),
            'errors' => \array_values((array)($data['errors'] ?? [])),
            'warnings' => \array_values((array)($data['warnings'] ?? [])),
            'checks' => $this->checks(
                $action,
                $scope,
                $profile,
                $driver,
                $database,
                $path,
                $artifact,
                $migrationTarget,
                $state,
                !empty($data['can_execute']),
                $canPreflight,
                $canRestoreExecute,
                $canMigrationPreflight,
                $canMigrationExecute,
                $canSqlApplyExecute,
                (string)($data['execution_label'] ?? __('Execution disabled in this slice'))
            ),
            'steps' => $this->steps($action, $driver),
        ];
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<int, array<string, string>>
     */
    private function checks(
        string $action,
        string $scope,
        array $profile,
        string $driver,
        string $database,
        string $path,
        string $artifact,
        string $migrationTarget,
        string $state,
        bool $canExecute,
        bool $canPreflight,
        bool $canRestoreExecute,
        bool $canMigrationPreflight,
        bool $canMigrationExecute,
        bool $canSqlApplyExecute,
        string $executionLabel
    ): array {
        $artifactRequired = $action === self::ACTION_BACKUP_DATABASE
            || $action === self::ACTION_RESTORE_DATABASE
            || $action === self::ACTION_MIGRATION_DRY_RUN
            || $action === self::ACTION_SQL_APPLY;
        $migrationRequired = $action === self::ACTION_MIGRATION_DRY_RUN;
        $sqlApplyRequired = $action === self::ACTION_SQL_APPLY;
        $artifactReady = !$artifactRequired || ($artifact !== '' && $this->isSafeArtifactName($artifact));
        $executionAdapterLabel = (string)__('Guarded');
        if ($canExecute) {
            $executionAdapterLabel = $this->adapterLabel($driver);
        } elseif ($canRestoreExecute) {
            $executionAdapterLabel = $this->restoreAdapterLabel($driver, $artifact);
        } elseif ($canPreflight) {
            $executionAdapterLabel = (string)__('Restore Preflight');
        } elseif ($canMigrationExecute) {
            $executionAdapterLabel = (string)__('MySQL Migration Import');
        } elseif ($canMigrationPreflight) {
            $executionAdapterLabel = (string)__('Migration Preflight');
        } elseif ($canSqlApplyExecute) {
            $executionAdapterLabel = (string)__('SQL Apply');
        }

        return [
            [
                'label' => (string)__('Backup Action'),
                'value' => $this->actionLabel($action),
                'detail' => $action === '' ? (string)__('Select a backup, restore, migration dry-run, or SQL apply action to build a guarded plan.') : (string)__('The action is normalized before any future execution adapter can run.'),
                'state' => $action === '' ? 'idle' : 'ready',
            ],
            [
                'label' => (string)__('Profile Source'),
                'value' => (string)($profile['source_label'] ?? __('No Profile')),
                'detail' => (string)($profile['source_detail'] ?? __('No database profile is available for this context.')),
                'state' => (string)($profile['source_state'] ?? 'blocked'),
            ],
            [
                'label' => (string)__('Driver Support'),
                'value' => $driver !== '' ? $driver : '-',
                'detail' => $this->driverSupportDetail($action, $driver),
                'state' => $this->supportsDriver($driver) ? 'ready' : 'blocked',
            ],
            [
                'label' => (string)__('Database Target'),
                'value' => $database !== '' ? $database : ($path !== '' ? $path : '-'),
                'detail' => $database !== '' ? (string)__('Used as the future dump, restore, or schema-diff database identifier.') : (string)__('A database name is required for this action.'),
                'state' => $database !== '' && $this->isSafeIdentifier($database) ? 'ready' : 'blocked',
            ],
            [
                'label' => (string)__('Backup Scope'),
                'value' => $this->scopeLabel($scope),
                'detail' => $action === self::ACTION_BACKUP_DATABASE
                    ? (string)__('Scope is passed to the database dump adapter when execution is enabled.')
                    : ($sqlApplyRequired
                        ? (string)__('SQL apply always creates a schema and data pre-apply backup before executing additive statements.')
                        : (string)__('Scope is ignored unless the selected action is backup.')),
                'state' => $action === self::ACTION_BACKUP_DATABASE ? ($canExecute ? 'ready' : 'dry_run_only') : 'idle',
            ],
            [
                'label' => (string)__('Artifact Boundary'),
                'value' => $artifactRequired && $artifact !== '' ? $artifact : '-',
                'detail' => $sqlApplyRequired
                    ? (string)__('SQL apply requires an existing reviewed .sql or .sql.gz artifact inside var/backups/wls/db-manager/database.')
                    : ($migrationRequired
                    ? (string)__('Migration preflight requires an existing Database Manager backup artifact with matching metadata; MySQL execution accepts only .sql or .sql.gz.')
                    : ($artifactRequired
                        ? (string)__('Execution may only write backup artifacts inside var/backups/wls/db-manager/database.')
                        : (string)__('Not required for this plan.'))),
                'state' => $artifactReady ? 'ready' : ($migrationRequired ? 'attention' : 'blocked'),
            ],
            [
                'label' => (string)__('Migration Target'),
                'value' => $migrationRequired && $migrationTarget !== '' ? $migrationTarget : '-',
                'detail' => $migrationRequired ? (string)__('Used as the future target profile, branch, tag, or migration reference.') : (string)__('Not required for backup or restore planning.'),
                'state' => !$migrationRequired || ($migrationTarget !== '' && $this->isSafeMigrationTarget($migrationTarget)) ? 'ready' : 'blocked',
            ],
            [
                'label' => (string)__('Execution Adapter'),
                'value' => $executionAdapterLabel,
                'detail' => $executionLabel,
                'state' => $state === 'blocked' ? 'attention' : ($canExecute || $canPreflight || $canRestoreExecute || $canMigrationPreflight || $canMigrationExecute || $canSqlApplyExecute ? 'ready' : 'dry_run_only'),
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function steps(string $action, string $driver): array
    {
        if ($action === '') {
            return [
                (string)__('Choose a database backup, restore, or migration dry-run action before future execution adapters are allowed to run.'),
            ];
        }

        if ($action === self::ACTION_BACKUP_DATABASE) {
            return [
                (string)__('Reload the enabled Project Database Profile and selected source env profile.'),
                (string)__('Verify driver support, database identifier safety, credential state, artifact name, and backup directory confinement.'),
                (string)__('%{1} adapter builds a vendor dump command inside the adapter boundary and writes only to the WLS backup directory.', [$driver !== '' ? $driver : 'database']),
                (string)__('Record artifact metadata, checksum, profile scope, operator intent, and backup result in the DbManager audit log.'),
                (string)__('Keep restore, migration, SQL apply, and WLS reload side effects disabled from the backup execution path.'),
            ];
        }

        if ($action === self::ACTION_RESTORE_DATABASE) {
            return [
                (string)__('Resolve the selected artifact by server-side name lookup inside the WLS backup directory.'),
                (string)__('Run restore preflight to verify artifact metadata, checksum, driver, database, and readability before destructive restore is allowed.'),
                (string)__('Create a fresh pre-restore backup before destructive restore execution runs.'),
                (string)__('Require CHECK_DB_RESTORE, RUN_DB_RESTORE, and adapter-specific reset confirmation when schema reset is required.'),
                (string)__('%{1} adapter restores through an allowlisted command path and then runs verification queries.', [$driver !== '' ? $driver : 'database']),
                (string)__('WLS reload remains disabled; project health is reported separately as a read-only summary.'),
            ];
        }

        if ($action === self::ACTION_SQL_APPLY) {
            return [
                (string)__('Resolve the reviewed SQL artifact by server-side name lookup inside the WLS backup directory.'),
                (string)__('Reject oversized artifacts and block destructive or data-touching SQL before any adapter connects.'),
                (string)__('Create a fresh schema and data pre-apply backup through the existing dump adapter.'),
                (string)__('Execute only additive CREATE TABLE, CREATE INDEX, or ALTER TABLE ADD statements through the selected PDO driver.'),
                (string)__('Run a verification query, append sanitized audit evidence, and keep WLS reload separate from SQL apply execution.'),
                (string)__('Use the recorded pre-apply backup as the rollback source if the additive SQL must be reversed.'),
            ];
        }

        if ($action === self::ACTION_MIGRATION_DRY_RUN && $driver === self::DRIVER_MYSQL) {
            return [
                (string)__('Resolve the migration target and selected MySQL/MariaDB backup artifact by server-side name lookup inside the WLS backup directory.'),
                (string)__('Run migration preflight to verify metadata, byte size, checksum, driver, database, and risk classification before execution is offered.'),
                (string)__('Require CHECK_DB_MIGRATION and RUN_DB_MIGRATION confirmations before importing the artifact.'),
                (string)__('Create a fresh schema and data pre-migration backup through the existing dump adapter.'),
                (string)__('Import the verified .sql or .sql.gz artifact through the MySQL client with credentials passed through process environment only.'),
                (string)__('Run a verification query, append sanitized audit evidence, and keep WLS reload separate from migration execution.'),
            ];
        }

        return [
            (string)__('Resolve source and target profiles without executing project migration scripts.'),
            (string)__('Verify backup artifact metadata, byte size, checksum, driver, and database before any migration execution path can exist.'),
            (string)__('Build a schema/data drift report using adapter-owned inspection commands or safe framework metadata.'),
            (string)__('Classify generated migration intent as additive, destructive, data-touching, or unsupported before any apply button exists.'),
            (string)__('Require backup evidence, confirmation phrase, audit record, and rollback guidance before a later migration execution slice can run.'),
            (string)__('Keep this dry-run path free of DDL, DML, dump writes, restore writes, and WLS reload side effects.'),
        ];
    }

    /**
     * @param array<string, mixed> $projectProfile
     * @param array<string, mixed> $selectedProfile
     * @return array<string, mixed>
     */
    private function effectiveProfile(array $projectProfile, array $selectedProfile): array
    {
        if (!empty($projectProfile['has_profile']) && !empty($projectProfile['enabled'])) {
            return [
                'source' => 'project_profile',
                'source_label' => (string)__('Project Profile'),
                'source_detail' => (string)__('The enabled Project Profile is the preferred source for project-scoped operations.'),
                'source_state' => 'ready',
                'key' => (string)($projectProfile['profile_key'] ?? 'local'),
                'type' => (string)($projectProfile['type'] ?? ''),
                'hostname' => (string)($projectProfile['hostname'] ?? ''),
                'hostport' => (string)($projectProfile['hostport'] ?? ''),
                'database' => (string)($projectProfile['database'] ?? ''),
                'path' => (string)($projectProfile['path'] ?? ''),
                'username' => (string)($projectProfile['username'] ?? ''),
            ];
        }

        if ($selectedProfile !== []) {
            return [
                'source' => 'selected_env',
                'source_label' => (string)__('Selected env profile'),
                'source_detail' => (string)__('The selected env profile is shown for preview, but future execution should require explicit confirmation.'),
                'source_state' => 'attention',
                'key' => (string)($selectedProfile['key'] ?? ''),
                'type' => (string)($selectedProfile['type'] ?? ''),
                'hostname' => (string)($selectedProfile['hostname'] ?? ''),
                'hostport' => (string)($selectedProfile['hostport'] ?? ''),
                'database' => (string)($selectedProfile['database'] ?? ''),
                'path' => (string)($selectedProfile['path'] ?? ''),
                'username' => (string)($selectedProfile['username'] ?? ''),
            ];
        }

        return [
            'source' => 'none',
            'source_label' => (string)__('No Profile'),
            'source_detail' => (string)__('No database profile is available for this context.'),
            'source_state' => 'blocked',
            'key' => '',
            'type' => '',
            'hostname' => '',
            'hostport' => '',
            'database' => '',
            'path' => '',
            'username' => '',
        ];
    }

    private function normalizeAction(string $action): string
    {
        $action = \strtolower(\trim($action));
        return \in_array($action, [self::ACTION_BACKUP_DATABASE, self::ACTION_RESTORE_DATABASE, self::ACTION_MIGRATION_DRY_RUN, self::ACTION_SQL_APPLY], true)
            ? $action
            : '';
    }

    private function normalizeScope(string $scope): string
    {
        $scope = \strtolower(\trim($scope));
        return \in_array($scope, ['schema_only', 'data_only', 'schema_and_data'], true) ? $scope : 'schema_and_data';
    }

    private function normalizeDriver(string $driver): string
    {
        $driver = \strtolower(\trim($driver));
        if ($driver === '') {
            return self::DRIVER_MYSQL;
        }

        return \in_array($driver, [self::DRIVER_MYSQL, self::DRIVER_PGSQL, self::DRIVER_SQLITE], true)
            ? $driver
            : $driver;
    }

    private function normalizeIdentifier(string $identifier): string
    {
        return \mb_substr(\trim($identifier), 0, 63);
    }

    private function normalizePath(string $path): string
    {
        $path = \preg_replace('/[\x00-\x1F\x7F]/', '', \trim($path)) ?? '';
        return \mb_substr($path, 0, 240);
    }

    private function normalizeMigrationTarget(string $target): string
    {
        return \mb_substr(\trim($target), 0, 120);
    }

    private function resolveArtifactName(string $action, string $artifactInput, string $database): string
    {
        $artifactInput = \trim($artifactInput);
        if ($artifactInput !== '') {
            return \mb_substr($artifactInput, 0, 160);
        }

        if ($action !== self::ACTION_BACKUP_DATABASE) {
            return '';
        }

        $seed = $database !== '' ? $database : 'database';
        $safeSeed = \preg_replace('/[^A-Za-z0-9_.-]+/', '-', $seed) ?: 'database';
        return \mb_substr(\trim($safeSeed, '.-') ?: 'database', 0, 80) . '-backup.sql';
    }

    private function supportsDriver(string $driver): bool
    {
        return \in_array($driver, [self::DRIVER_MYSQL, self::DRIVER_PGSQL], true);
    }

    private function isSafeIdentifier(string $identifier): bool
    {
        return \preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/', $identifier) === 1;
    }

    private function isSafeArtifactName(string $artifact): bool
    {
        return \preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,150}\.(sql|sql\.gz|dump|backup)$/i', $artifact) === 1
            && !\str_contains($artifact, '..')
            && !\str_contains($artifact, '/')
            && !\str_contains($artifact, '\\')
            && !\str_contains($artifact, ':');
    }

    private function isExecutableBackupArtifact(string $artifact, string $driver): bool
    {
        if (!$this->isSafeArtifactName($artifact)) {
            return false;
        }

        return match ($driver) {
            self::DRIVER_MYSQL => \preg_match('/\.(sql|sql\.gz)$/i', $artifact) === 1,
            self::DRIVER_PGSQL => \preg_match('/\.(sql|sql\.gz|dump|backup)$/i', $artifact) === 1,
            default => false,
        };
    }

    private function isRestorePreflightArtifact(string $artifact, string $driver): bool
    {
        if (!$this->isSafeArtifactName($artifact)) {
            return false;
        }

        return match ($driver) {
            self::DRIVER_MYSQL => \preg_match('/\.(sql|sql\.gz)$/i', $artifact) === 1,
            self::DRIVER_PGSQL => \preg_match('/\.(sql|sql\.gz|dump|backup)$/i', $artifact) === 1,
            default => false,
        };
    }

    private function isExecutableRestoreArtifact(string $artifact, string $driver): bool
    {
        if (!$this->isSafeArtifactName($artifact)) {
            return false;
        }

        return match ($driver) {
            self::DRIVER_MYSQL => \preg_match('/\.(sql|sql\.gz)$/i', $artifact) === 1,
            self::DRIVER_PGSQL => \preg_match('/\.(sql|sql\.gz|dump|backup)$/i', $artifact) === 1,
            default => false,
        };
    }

    private function isExecutableMigrationArtifact(string $artifact, string $driver): bool
    {
        if (!$this->isSafeArtifactName($artifact)) {
            return false;
        }

        return $driver === self::DRIVER_MYSQL && \preg_match('/\.(sql|sql\.gz)$/i', $artifact) === 1;
    }

    private function isSqlApplyArtifact(string $artifact): bool
    {
        return \preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,150}\.(sql|sql\.gz)$/i', $artifact) === 1
            && !\str_contains($artifact, '..')
            && !\str_contains($artifact, '/')
            && !\str_contains($artifact, '\\')
            && !\str_contains($artifact, ':');
    }

    private function isPostgreSqlPlainSqlRestoreArtifact(string $artifact): bool
    {
        return $this->isSafeArtifactName($artifact)
            && \preg_match('/\.(sql|sql\.gz)$/i', $artifact) === 1;
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function executionLabel(
        string $action,
        bool $blocked,
        bool $canExecute,
        bool $canMigrationExecute,
        bool $canSqlApplyExecute,
        string $driver,
        array $profile,
        string $artifact,
        string $migrationTarget
    ): string
    {
        if ($blocked) {
            return (string)__('Fix blocked plan inputs before execution can run.');
        }

        if ($canExecute) {
            return (string)__('%{1} execution is available after RUN_DB_BACKUP confirmation.', [$this->adapterLabel($driver)]);
        }

        if ($canSqlApplyExecute) {
            return (string)__('SQL apply execution is available after RUN_DB_SQL_APPLY confirmation; WLS creates a pre-apply backup first.');
        }

        if ($canMigrationExecute) {
            return (string)__('MySQL/MariaDB migration import is available after CHECK_DB_MIGRATION and RUN_DB_MIGRATION confirmations; WLS creates a pre-migration backup first.');
        }

        if ($action === self::ACTION_RESTORE_DATABASE) {
            if (($profile['source'] ?? '') !== 'project_profile') {
                return (string)__('Save and enable the Project Profile before restore preflight.');
            }

            if (!$this->supportsBackupExecutionDriver($driver)) {
                return (string)__('Restore preflight currently supports mysql and pgsql profiles.');
            }

            if (!$this->isRestorePreflightArtifact($artifact, $driver)) {
                return $this->restoreArtifactHelp($driver);
            }

            if ($driver === self::DRIVER_PGSQL && $this->isPostgreSqlPlainSqlRestoreArtifact($artifact)) {
                return (string)__('PostgreSQL plain SQL restore is available after CHECK_DB_RESTORE, RUN_DB_RESTORE, and RESET_PG_SCHEMA confirmations; WLS resets only the public schema after a custom-format pre-restore backup.');
            }

            if ($this->supportsRestoreExecutionDriver($driver) && $this->isExecutableRestoreArtifact($artifact, $driver)) {
                return (string)__('Restore execution is available after CHECK_DB_RESTORE and RUN_DB_RESTORE confirmations; a fresh pre-restore backup is created first.');
            }

            return (string)__('Restore preflight is available after CHECK_DB_RESTORE confirmation; restore execution remains disabled for this driver.');
        }

        if ($action === self::ACTION_MIGRATION_DRY_RUN) {
            if (($profile['source'] ?? '') !== 'project_profile') {
                return (string)__('Save and enable the Project Profile before migration preflight.');
            }

            if (!$this->supportsBackupExecutionDriver($driver)) {
                return (string)__('Migration preflight currently supports mysql and pgsql profiles.');
            }

            if ($migrationTarget === '' || !$this->isSafeMigrationTarget($migrationTarget)) {
                return (string)__('Provide a safe migration target reference before migration preflight.');
            }

            if (!$this->isRestorePreflightArtifact($artifact, $driver)) {
                return $this->migrationArtifactHelp($driver);
            }

            if ($this->supportsMigrationExecutionDriver($driver) && $this->isExecutableMigrationArtifact($artifact, $driver)) {
                return (string)__('Migration import is available after CHECK_DB_MIGRATION and RUN_DB_MIGRATION confirmations; a fresh pre-migration backup is created first.');
            }

            return (string)__('Migration preflight is available after CHECK_DB_MIGRATION confirmation; migration execution remains disabled for this driver.');
        }

        if ($action === self::ACTION_SQL_APPLY) {
            if (($profile['source'] ?? '') !== 'project_profile') {
                return (string)__('Save and enable the Project Profile before SQL apply execution.');
            }

            if (!$this->supportsSqlApplyDriver($driver)) {
                return (string)__('SQL apply currently supports mysql and pgsql profiles.');
            }

            if (!$this->isSqlApplyArtifact($artifact)) {
                return $this->sqlApplyArtifactHelp();
            }

            return (string)__('SQL apply execution is guarded until all checks are ready.');
        }

        if ($action !== self::ACTION_BACKUP_DATABASE) {
            return (string)__('Restore, migration, and SQL apply execution remain disabled in this slice.');
        }

        if (($profile['source'] ?? '') !== 'project_profile') {
            return (string)__('Save and enable the Project Profile before backup execution.');
        }

        if (!$this->supportsBackupExecutionDriver($driver)) {
            return (string)__('Backup execution currently supports mysql and pgsql profiles.');
        }

        if (!$this->isExecutableBackupArtifact($artifact, $driver)) {
            return $this->backupArtifactHelp($driver);
        }

        return (string)__('Backup execution is guarded until all checks are ready.');
    }

    private function isSafeMigrationTarget(string $target): bool
    {
        return \preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,119}$/', $target) === 1
            && !\str_contains($target, '..');
    }

    private function actionLabel(string $action): string
    {
        return match ($action) {
            self::ACTION_BACKUP_DATABASE => (string)__('Backup Database'),
            self::ACTION_RESTORE_DATABASE => (string)__('Restore Database'),
            self::ACTION_MIGRATION_DRY_RUN => (string)__('Migration Dry Run'),
            self::ACTION_SQL_APPLY => (string)__('SQL Apply'),
            default => (string)__('No Action Selected'),
        };
    }

    private function scopeLabel(string $scope): string
    {
        return match ($scope) {
            'schema_only' => (string)__('Schema Only'),
            'data_only' => (string)__('Data Only'),
            default => (string)__('Schema And Data'),
        };
    }

    private function supportsBackupExecutionDriver(string $driver): bool
    {
        return \in_array($driver, [self::DRIVER_MYSQL, self::DRIVER_PGSQL], true);
    }

    private function supportsRestoreExecutionDriver(string $driver): bool
    {
        return \in_array($driver, [self::DRIVER_MYSQL, self::DRIVER_PGSQL], true);
    }

    private function supportsMigrationExecutionDriver(string $driver): bool
    {
        return $driver === self::DRIVER_MYSQL;
    }

    private function supportsSqlApplyDriver(string $driver): bool
    {
        return \in_array($driver, [self::DRIVER_MYSQL, self::DRIVER_PGSQL], true);
    }

    private function backupArtifactHelp(string $driver): string
    {
        return $driver === self::DRIVER_MYSQL
            ? (string)__('Use a .sql or .sql.gz artifact before MySQL backup execution.')
            : (string)__('Use a .sql, .sql.gz, .dump, or .backup artifact before backup execution.');
    }

    private function restoreArtifactHelp(string $driver): string
    {
        return $driver === self::DRIVER_MYSQL
            ? (string)__('Use a .sql or .sql.gz artifact before MySQL restore preflight.')
            : (string)__('Use a .sql, .sql.gz, .dump, or .backup artifact before restore preflight.');
    }

    private function migrationArtifactHelp(string $driver): string
    {
        return $driver === self::DRIVER_MYSQL
            ? (string)__('Use a .sql or .sql.gz backup artifact before MySQL migration preflight.')
            : (string)__('Use a .sql, .sql.gz, .dump, or .backup artifact before migration preflight.');
    }

    private function sqlApplyArtifactHelp(): string
    {
        return (string)__('Use a reviewed .sql or .sql.gz artifact before SQL apply execution.');
    }

    private function driverSupportDetail(string $action, string $driver): string
    {
        if ($this->supportsBackupExecutionDriver($driver) && $action === self::ACTION_BACKUP_DATABASE) {
            return (string)__('%{1} backup execution is available through the %{2} adapter.', [$this->driverLabel($driver), $this->adapterBinary($driver)]);
        }

        if ($this->supportsSqlApplyDriver($driver) && $action === self::ACTION_SQL_APPLY) {
            return (string)__('%{1} SQL apply execution is available through PDO after pre-apply backup.', [$this->driverLabel($driver)]);
        }

        if ($this->supportsMigrationExecutionDriver($driver) && $action === self::ACTION_MIGRATION_DRY_RUN) {
            return (string)__('MySQL/MariaDB migration import is available through the mysql client after preflight and pre-migration backup.');
        }

        if ($this->supportsDriver($driver)) {
            return (string)__('This driver remains preview-only until a matching execution adapter is added.');
        }

        return (string)__('This driver is not supported for database backup, restore, migration, or SQL apply planning.');
    }

    private function adapterBinary(string $driver): string
    {
        return $driver === self::DRIVER_MYSQL ? 'mysqldump' : 'pg_dump';
    }

    private function adapterLabel(string $driver): string
    {
        return $driver === self::DRIVER_MYSQL
            ? (string)__('MySQL mysqldump')
            : (string)__('PostgreSQL pg_dump');
    }

    private function restoreAdapterLabel(string $driver, string $artifact = ''): string
    {
        if ($driver === self::DRIVER_MYSQL) {
            return (string)__('MySQL Restore');
        }

        return $this->isPostgreSqlPlainSqlRestoreArtifact($artifact)
            ? (string)__('PostgreSQL psql schema reset')
            : (string)__('PostgreSQL pg_restore');
    }

    private function driverLabel(string $driver): string
    {
        return $driver === self::DRIVER_MYSQL ? 'MySQL' : 'PostgreSQL';
    }
}
