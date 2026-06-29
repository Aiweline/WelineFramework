<?php
declare(strict_types=1);

namespace Weline\DbManager\Service;

use Weline\DbManager\Service\Adapter\WlsDatabaseLifecycleSqlPlanAdapter;

class WlsDatabaseLifecyclePlanService
{
    private const ACTION_CREATE_DATABASE = 'create_database';
    private const ACTION_CREATE_USER = 'create_user';
    private const ACTION_GRANT_USER = 'grant_user';
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
        $adapter = new WlsDatabaseLifecycleSqlPlanAdapter();
        $action = $this->normalizeAction((string)($input['lifecycle_action'] ?? ''));
        $driver = $this->normalizeDriver((string)($projectProfile['type'] ?? ($selectedProfile['type'] ?? '')));
        $database = $this->normalizeIdentifier((string)($input['lifecycle_database'] ?? ($projectProfile['database'] ?? '')));
        $username = $this->normalizeUsername((string)($input['lifecycle_username'] ?? ($projectProfile['username'] ?? '')));
        $host = $this->normalizeHost((string)($input['lifecycle_host'] ?? ($projectProfile['hostname'] ?? ($selectedProfile['hostname'] ?? ''))));
        $grantMode = $this->normalizeGrantMode((string)($input['lifecycle_grant_mode'] ?? 'read_write'));
        $hasPasswordSecret = !empty($projectProfile['password_configured']) || !empty($projectProfile['env_password_configured']);

        $errors = [];
        $warnings = [];
        if ($action === '') {
            return $this->plan([
                'action' => '',
                'action_label' => (string)__('No Action Selected'),
                'database' => $database,
                'username' => $username,
                'host' => $host,
                'driver' => $driver,
                'grant_mode' => $grantMode,
                'state' => 'idle',
                'state_label' => (string)__('Waiting for Input'),
                'errors' => [],
                'warnings' => [],
                'adapter_plan' => $adapter->emptyPlan(),
            ]);
        }

        if (!\in_array($driver, [self::DRIVER_MYSQL, self::DRIVER_PGSQL], true)) {
            $errors[] = (string)__('Database lifecycle actions currently require mysql or pgsql profile drivers.');
        }

        if ($this->actionNeedsDatabase($action) && $database === '') {
            $errors[] = (string)__('Database name is required for this lifecycle action.');
        }

        if ($this->actionNeedsUser($action) && $username === '') {
            $errors[] = (string)__('Username is required for this lifecycle action.');
        }

        if ($database !== '' && !$this->isSafeIdentifier($database)) {
            $errors[] = (string)__('Database name must start with a letter or underscore and contain only letters, numbers, and underscores.');
        }

        if ($username !== '' && !$this->isSafeUsername($username)) {
            $errors[] = (string)__('Username must start with a letter or underscore and contain only letters, numbers, underscores, dots, and dashes.');
        }

        if ($action === self::ACTION_CREATE_USER && empty($projectProfile['password_configured']) && empty($projectProfile['env_password_configured'])) {
            $errors[] = (string)__('Create user requires a password stored in the Project Profile or copied from the selected env profile.');
        }

        if ($host === '' && $driver === self::DRIVER_MYSQL && $this->actionNeedsUser($action)) {
            $warnings[] = (string)__('No host was provided; a future MySQL adapter must choose a safe host scope before execution.');
        }

        if ($action === self::ACTION_GRANT_USER) {
            $warnings[] = (string)__('Grant preview assumes the database and user already exist; future execution must verify both before applying privileges.');
        }

        $adapterPlan = $adapter->build($action, $driver, $database, $username, $host, $grantMode, $hasPasswordSecret);
        foreach ((array)($adapterPlan['errors'] ?? []) as $adapterError) {
            if ($adapterError !== '' && !\in_array($adapterError, $errors, true)) {
                $errors[] = (string)$adapterError;
            }
        }
        foreach ((array)($adapterPlan['warnings'] ?? []) as $adapterWarning) {
            if ($adapterWarning !== '' && !\in_array($adapterWarning, $warnings, true)) {
                $warnings[] = (string)$adapterWarning;
            }
        }

        $blocked = $errors !== [];
        $adapterReady = (string)($adapterPlan['state'] ?? '') === 'planned';
        $profileExecutionReady = !empty($projectProfile['has_profile']) && !empty($projectProfile['enabled']);
        $canExecute = !$blocked && $adapterReady && $profileExecutionReady;
        if ($blocked) {
            $state = 'blocked';
            $stateLabel = (string)__('Blocked');
            $executionLabel = (string)__('Execution blocked until the lifecycle plan is valid.');
        } elseif (!$adapterReady) {
            $state = 'dry_run_only';
            $stateLabel = (string)__('Dry Run Only');
            $executionLabel = (string)__('Execution is unavailable until the adapter SQL plan is ready.');
        } elseif (!$profileExecutionReady) {
            $state = 'dry_run_only';
            $stateLabel = (string)__('Dry Run Only');
            $executionLabel = (string)__('Save and enable the Project Profile before lifecycle execution.');
        } else {
            $state = 'ready_to_execute';
            $stateLabel = (string)__('Ready To Execute');
            $executionLabel = (string)__('Guarded POST execution is available after confirmation.');
        }

        return $this->plan([
            'action' => $action,
            'action_label' => $this->actionLabel($action),
            'database' => $database,
            'username' => $username,
            'host' => $host,
            'driver' => $driver,
            'grant_mode' => $grantMode,
            'grant_label' => $this->grantModeLabel($grantMode),
            'state' => $state,
            'state_label' => $stateLabel,
            'execution_label' => $executionLabel,
            'can_execute' => $canExecute,
            'profile_execution_ready' => $profileExecutionReady,
            'errors' => $errors,
            'warnings' => $warnings,
            'adapter_plan' => $adapterPlan,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function plan(array $data): array
    {
        $action = (string)($data['action'] ?? '');
        $driver = (string)($data['driver'] ?? self::DRIVER_MYSQL);
        $state = (string)($data['state'] ?? 'idle');
        $database = (string)($data['database'] ?? '');
        $username = (string)($data['username'] ?? '');
        $host = (string)($data['host'] ?? '');
        $grantMode = (string)($data['grant_mode'] ?? 'read_write');
        $canExecute = (bool)($data['can_execute'] ?? false);
        $profileExecutionReady = (bool)($data['profile_execution_ready'] ?? false);

        return [
            'action' => $action,
            'action_label' => (string)($data['action_label'] ?? $this->actionLabel($action)),
            'database' => $database,
            'username' => $username,
            'host' => $host,
            'driver' => $driver,
            'grant_mode' => $grantMode,
            'grant_label' => (string)($data['grant_label'] ?? $this->grantModeLabel($grantMode)),
            'state' => $state,
            'state_label' => (string)($data['state_label'] ?? __('Waiting for Input')),
            'execution_label' => (string)($data['execution_label'] ?? __('Execution disabled in this slice')),
            'can_execute' => $canExecute,
            'profile_execution_ready' => $profileExecutionReady,
            'errors' => \array_values((array)($data['errors'] ?? [])),
            'warnings' => \array_values((array)($data['warnings'] ?? [])),
            'checks' => $this->checks($action, $driver, $database, $username, $host, $grantMode, $state, $canExecute, $profileExecutionReady),
            'steps' => $this->steps($action, $driver),
            'adapter_plan' => (array)($data['adapter_plan'] ?? []),
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function checks(
        string $action,
        string $driver,
        string $database,
        string $username,
        string $host,
        string $grantMode,
        string $state,
        bool $canExecute,
        bool $profileExecutionReady
    ): array
    {
        $executionState = $canExecute ? 'ready' : ($state === 'blocked' ? 'blocked' : 'attention');
        $executionValue = $canExecute
            ? (string)__('Ready')
            : ($profileExecutionReady ? (string)__('Guarded') : (string)__('Profile Required'));
        $executionDetail = match (true) {
            $canExecute => (string)__('POST execution will rebuild this plan, require RUN_DB_LIFECYCLE, write an audit record, and run verification queries.'),
            !$profileExecutionReady => (string)__('Save and enable the Project Profile before lifecycle SQL can execute.'),
            $state === 'blocked' => (string)__('Fix blocked plan inputs before execution can run.'),
            default => (string)__('Execution remains unavailable until the adapter SQL plan is ready.'),
        };

        return [
            [
                'label' => (string)__('Lifecycle Action'),
                'value' => $this->actionLabel($action),
                'detail' => $action === '' ? (string)__('Select an action to build a guarded DBA plan.') : (string)__('The action is normalized before the execution adapter can run.'),
                'state' => $action === '' ? 'idle' : 'ready',
            ],
            [
                'label' => (string)__('Driver Support'),
                'value' => $driver !== '' ? $driver : '-',
                'detail' => \in_array($driver, [self::DRIVER_MYSQL, self::DRIVER_PGSQL], true)
                    ? (string)__('The adapter maps this driver to vendor-specific SQL.')
                    : (string)__('This driver is not supported for lifecycle DBA actions.'),
                'state' => \in_array($driver, [self::DRIVER_MYSQL, self::DRIVER_PGSQL], true) ? 'ready' : 'blocked',
            ],
            [
                'label' => (string)__('Database Target'),
                'value' => $database !== '' ? $database : '-',
                'detail' => $this->actionNeedsDatabase($action) ? (string)__('Used as the future database/schema identifier.') : (string)__('Not required for this action.'),
                'state' => !$this->actionNeedsDatabase($action) || $this->isSafeIdentifier($database) ? 'ready' : 'blocked',
            ],
            [
                'label' => (string)__('User Target'),
                'value' => $username !== '' ? $username : '-',
                'detail' => $this->actionNeedsUser($action) ? (string)__('Used as the future database user identifier.') : (string)__('Not required for this action.'),
                'state' => !$this->actionNeedsUser($action) || $this->isSafeUsername($username) ? 'ready' : 'blocked',
            ],
            [
                'label' => (string)__('Grant Scope'),
                'value' => $this->grantModeLabel($grantMode),
                'detail' => $host !== '' ? (string)__('Host scope is captured for adapter verification.') : (string)__('Host scope is not selected in this dry run.'),
                'state' => $state === 'blocked' ? 'attention' : 'dry_run_only',
            ],
            [
                'label' => (string)__('Execution Adapter'),
                'value' => $executionValue,
                'detail' => $executionDetail,
                'state' => $executionState,
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
                (string)__('Choose a lifecycle action and preview the plan before any future DBA adapter is allowed to run.'),
            ];
        }

        return [
            (string)__('Reload the enabled Project Database Profile and selected source env profile.'),
            (string)__('Verify driver support, identifier allowlists, credential state, and operator confirmation phrase.'),
            (string)__('%{1} adapter builds vendor-specific SQL with identifier quoting inside the adapter boundary.', [$driver !== '' ? $driver : 'database']),
            (string)__('Execute only through the guarded POST route after the plan is rebuilt server-side and RUN_DB_LIFECYCLE is confirmed.'),
            (string)__('Append an audit record and run adapter verification queries after the database operation succeeds.'),
        ];
    }

    private function normalizeAction(string $action): string
    {
        $action = \strtolower(\trim($action));
        return \in_array($action, [self::ACTION_CREATE_DATABASE, self::ACTION_CREATE_USER, self::ACTION_GRANT_USER], true)
            ? $action
            : '';
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

    private function normalizeUsername(string $username): string
    {
        return \mb_substr(\trim($username), 0, 63);
    }

    private function normalizeHost(string $host): string
    {
        $host = \trim($host);
        $host = \preg_replace('/[\x00-\x1F\x7F]/', '', $host) ?? '';
        return \mb_substr($host, 0, 190);
    }

    private function normalizeGrantMode(string $grantMode): string
    {
        $grantMode = \strtolower(\trim($grantMode));
        return \in_array($grantMode, ['read_write', 'read_only', 'schema_owner'], true) ? $grantMode : 'read_write';
    }

    private function isSafeIdentifier(string $identifier): bool
    {
        return \preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/', $identifier) === 1;
    }

    private function isSafeUsername(string $username): bool
    {
        return \preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,62}$/', $username) === 1;
    }

    private function actionNeedsDatabase(string $action): bool
    {
        return \in_array($action, [self::ACTION_CREATE_DATABASE, self::ACTION_GRANT_USER], true);
    }

    private function actionNeedsUser(string $action): bool
    {
        return \in_array($action, [self::ACTION_CREATE_USER, self::ACTION_GRANT_USER], true);
    }

    private function actionLabel(string $action): string
    {
        return match ($action) {
            self::ACTION_CREATE_DATABASE => (string)__('Create Database'),
            self::ACTION_CREATE_USER => (string)__('Create User'),
            self::ACTION_GRANT_USER => (string)__('Grant User'),
            default => (string)__('No Action Selected'),
        };
    }

    private function grantModeLabel(string $grantMode): string
    {
        return match ($grantMode) {
            'read_only' => (string)__('Read Only'),
            'schema_owner' => (string)__('Schema Owner'),
            default => (string)__('Read Write'),
        };
    }
}
