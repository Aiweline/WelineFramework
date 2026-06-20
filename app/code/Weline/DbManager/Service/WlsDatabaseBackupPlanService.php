<?php
declare(strict_types=1);

namespace Weline\DbManager\Service;

class WlsDatabaseBackupPlanService
{
    private const ACTION_BACKUP_DATABASE = 'backup_database';
    private const ACTION_RESTORE_DATABASE = 'restore_database';
    private const ACTION_MIGRATION_DRY_RUN = 'migration_dry_run';
    private const DRIVER_MYSQL = 'mysql';
    private const DRIVER_PGSQL = 'pgsql';
    private const DRIVER_SQLITE = 'sqlite';

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
            $errors[] = (string)__('Database backup, restore, and migration planning currently require mysql or pgsql profile drivers.');
        }

        if ($database === '' && $driver !== self::DRIVER_SQLITE) {
            $errors[] = (string)__('Database name is required before building a backup, restore, or migration plan.');
        }

        if ($database !== '' && !$this->isSafeIdentifier($database)) {
            $errors[] = (string)__('Database name must start with a letter or underscore and contain only letters, numbers, and underscores.');
        }

        if (($profile['source'] ?? '') !== 'project_profile') {
            $warnings[] = (string)__('This preview is using the selected env profile. Future execution should require an enabled Project Profile or an explicit source confirmation.');
        }

        if ($action === self::ACTION_RESTORE_DATABASE) {
            if (\trim($artifactInput) === '') {
                $errors[] = (string)__('Restore planning requires a backup artifact name.');
            } elseif (!$this->isSafeArtifactName($artifact)) {
                $errors[] = (string)__('Backup artifact must be a safe file name ending in .sql, .sql.gz, .dump, or .backup.');
            }
            $warnings[] = (string)__('Restore is destructive and must stay behind preflight, pre-restore backup, confirmation phrase, and audit logging before execution is enabled.');
        }

        if ($action === self::ACTION_MIGRATION_DRY_RUN) {
            if ($migrationTarget === '') {
                $errors[] = (string)__('Migration dry-run requires a target profile, branch, tag, or migration reference.');
            } elseif (!$this->isSafeMigrationTarget($migrationTarget)) {
                $errors[] = (string)__('Migration target may contain only letters, numbers, underscores, dots, colons, and dashes.');
            }
            $warnings[] = (string)__('Migration dry-run is a planning surface only; it must not execute DDL, DML, or migration scripts.');
        }

        if ($action === self::ACTION_BACKUP_DATABASE && !$this->isSafeArtifactName($artifact)) {
            $errors[] = (string)__('Generated backup artifact name is not safe; adjust the database name or artifact label.');
        }

        $blocked = $errors !== [];
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
            'state' => $blocked ? 'blocked' : 'dry_run_only',
            'state_label' => $blocked ? (string)__('Blocked') : (string)__('Dry Run Only'),
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
            'execution_label' => (string)__('Execution disabled in this slice'),
            'can_execute' => false,
            'errors' => \array_values((array)($data['errors'] ?? [])),
            'warnings' => \array_values((array)($data['warnings'] ?? [])),
            'checks' => $this->checks($action, $scope, $profile, $driver, $database, $path, $artifact, $migrationTarget, $state),
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
        string $state
    ): array {
        $artifactRequired = $action === self::ACTION_BACKUP_DATABASE || $action === self::ACTION_RESTORE_DATABASE;
        $migrationRequired = $action === self::ACTION_MIGRATION_DRY_RUN;

        return [
            [
                'label' => (string)__('Backup Action'),
                'value' => $this->actionLabel($action),
                'detail' => $action === '' ? (string)__('Select a backup, restore, or migration dry-run action to build a guarded plan.') : (string)__('The action is normalized before any future execution adapter can run.'),
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
                'detail' => $this->supportsDriver($driver)
                    ? (string)__('Future adapters may map this driver to vendor backup, restore, and schema-diff commands.')
                    : (string)__('This driver is not supported for database backup, restore, or migration planning.'),
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
                'detail' => $action === self::ACTION_BACKUP_DATABASE ? (string)__('Scope is captured for the future dump adapter.') : (string)__('Scope is ignored unless the selected action is backup.'),
                'state' => $action === self::ACTION_BACKUP_DATABASE ? 'dry_run_only' : 'idle',
            ],
            [
                'label' => (string)__('Artifact Boundary'),
                'value' => $artifactRequired && $artifact !== '' ? $artifact : '-',
                'detail' => $artifactRequired
                    ? (string)__('Future execution may only read or write artifacts inside var/backups/wls/db-manager/database.')
                    : (string)__('Not required for migration dry-run planning.'),
                'state' => !$artifactRequired || ($artifact !== '' && $this->isSafeArtifactName($artifact)) ? 'ready' : 'blocked',
            ],
            [
                'label' => (string)__('Migration Target'),
                'value' => $migrationRequired && $migrationTarget !== '' ? $migrationTarget : '-',
                'detail' => $migrationRequired ? (string)__('Used as the future target profile, branch, tag, or migration reference.') : (string)__('Not required for backup or restore planning.'),
                'state' => !$migrationRequired || ($migrationTarget !== '' && $this->isSafeMigrationTarget($migrationTarget)) ? 'ready' : 'blocked',
            ],
            [
                'label' => (string)__('Execution Adapter'),
                'value' => (string)__('Disabled'),
                'detail' => (string)__('No database dump, restore, migration, file write, SQL, or reload operation is executed by this slice.'),
                'state' => $state === 'blocked' ? 'attention' : 'dry_run_only',
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
                (string)__('Reload the enabled Project Database Profile or explicitly confirmed source env profile.'),
                (string)__('Verify driver support, database identifier safety, credential state, artifact name, and backup directory confinement.'),
                (string)__('%{1} adapter builds a vendor dump command inside the adapter boundary and writes only to the WLS backup directory.', [$driver !== '' ? $driver : 'database']),
                (string)__('Record artifact metadata, checksum, profile scope, operator intent, and backup result in the DbManager audit log.'),
                (string)__('Run a future verification probe without exposing passwords or writing outside the backup directory.'),
            ];
        }

        if ($action === self::ACTION_RESTORE_DATABASE) {
            return [
                (string)__('Resolve the selected artifact by server-side name lookup inside the WLS backup directory.'),
                (string)__('Create a fresh pre-restore backup and verify artifact metadata before destructive restore is allowed.'),
                (string)__('Require an explicit restore confirmation phrase and audit record before the future adapter can run.'),
                (string)__('%{1} adapter restores through an allowlisted command path and then runs verification queries.', [$driver !== '' ? $driver : 'database']),
                (string)__('Only after restore verification may a future slice offer optional WLS reload or project health checks.'),
            ];
        }

        return [
            (string)__('Resolve source and target profiles without executing project migration scripts.'),
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
        return \in_array($action, [self::ACTION_BACKUP_DATABASE, self::ACTION_RESTORE_DATABASE, self::ACTION_MIGRATION_DRY_RUN], true)
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
        return \mb_substr(\trim($safeSeed, '.-') ?: 'database', 0, 80) . '-dry-run.sql.gz';
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
}
