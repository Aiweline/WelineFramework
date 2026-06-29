<?php
declare(strict_types=1);

namespace Weline\DbManager\Service;

class WlsDatabaseProjectHealthService
{
    /**
     * @param array<string, mixed> $context
     * @param array<int, array<string, mixed>> $profiles
     * @param array<string, mixed> $projectProfile
     * @param array<string, mixed> $envPlan
     * @param array<string, mixed> $slavePlan
     * @param array<string, mixed> $backupPlan
     * @return array{state:string,state_label:string,ready:int,attention:int,blocked:int,checks:array<int,array<string,string>>,actions:array<int,string>}
     */
    public function buildPlan(
        array $context,
        array $profiles,
        string $selectedKey,
        array $projectProfile,
        array $envPlan,
        array $slavePlan,
        array $backupPlan
    ): array {
        $checks = [];
        $checks[] = $this->profileCheck($profiles, $selectedKey);
        $checks[] = $this->projectProfileCheck($context, $projectProfile);
        $checks[] = $this->driverCheck($profiles, $selectedKey);
        $checks[] = $this->backupDirectoryCheck();
        $checks[] = $this->envBackupCheck($envPlan);
        $checks[] = $this->backupPlanCheck($backupPlan);
        $checks[] = $this->slaveCheck($slavePlan);

        $ready = 0;
        $attention = 0;
        $blocked = 0;
        foreach ($checks as $check) {
            if (($check['state'] ?? '') === 'blocked') {
                $blocked++;
            } elseif (($check['state'] ?? '') === 'attention') {
                $attention++;
            } else {
                $ready++;
            }
        }

        $state = $blocked > 0 ? 'blocked' : ($attention > 0 ? 'attention' : 'ready');

        return [
            'state' => $state,
            'state_label' => $this->stateLabel($state),
            'ready' => $ready,
            'attention' => $attention,
            'blocked' => $blocked,
            'checks' => $checks,
            'actions' => $this->actions($checks),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $profiles
     * @return array{label:string,value:string,detail:string,state:string}
     */
    private function profileCheck(array $profiles, string $selectedKey): array
    {
        if ($profiles === []) {
            return [
                'label' => (string)__('Selected Profile'),
                'value' => (string)__('Missing'),
                'detail' => (string)__('No database profile is available from env.php.'),
                'state' => 'blocked',
            ];
        }

        $selected = $this->selectedProfile($profiles, $selectedKey);
        if ($selected === []) {
            return [
                'label' => (string)__('Selected Profile'),
                'value' => (string)__('Missing'),
                'detail' => (string)__('The requested profile could not be resolved.'),
                'state' => 'attention',
            ];
        }

        $ready = (string)($selected['status'] ?? '') === 'ready';

        return [
            'label' => (string)__('Selected Profile'),
            'value' => (string)($selected['label'] ?? $selectedKey),
            'detail' => $ready
                ? (string)__('The selected source profile has required fields.')
                : (string)__('Complete the selected source profile before guarded operations.'),
            'state' => $ready ? 'ready' : 'attention',
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $projectProfile
     * @return array{label:string,value:string,detail:string,state:string}
     */
    private function projectProfileCheck(array $context, array $projectProfile): array
    {
        $hasProfile = !empty($projectProfile['has_profile']);
        $enabled = !empty($projectProfile['enabled']);
        $hasContext = \trim((string)($context['project_id'] ?? '')) !== ''
            || \trim((string)($context['domain'] ?? '')) !== ''
            || \trim((string)($context['profile_key'] ?? '')) !== '';

        if ($enabled) {
            return [
                'label' => (string)__('Project Profile'),
                'value' => (string)__('Enabled'),
                'detail' => (string)__('Project-level database operations will prefer the saved Project Profile.'),
                'state' => 'ready',
            ];
        }

        if ($hasProfile) {
            return [
                'label' => (string)__('Project Profile'),
                'value' => (string)__('Disabled'),
                'detail' => (string)__('Enable the saved Project Profile before project-level operations.'),
                'state' => 'attention',
            ];
        }

        return [
            'label' => (string)__('Project Profile'),
            'value' => $hasContext ? (string)__('Inherited') : (string)__('Local'),
            'detail' => $hasContext
                ? (string)__('Save a Project Profile when this WLS project needs isolated database settings.')
                : (string)__('No project context was selected; env profile inheritance is being used.'),
            'state' => 'attention',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $profiles
     * @return array{label:string,value:string,detail:string,state:string}
     */
    private function driverCheck(array $profiles, string $selectedKey): array
    {
        $selected = $this->selectedProfile($profiles, $selectedKey);
        $driverStatus = \is_array($selected['driver_status'] ?? null) ? (array)$selected['driver_status'] : [];
        $ready = !empty($driverStatus['ready']);

        return [
            'label' => (string)__('Driver Runtime'),
            'value' => (string)($driverStatus['extension'] ?? __('Unknown')),
            'detail' => $ready
                ? (string)__('Required PDO driver is available in this runtime.')
                : (string)__('Install or enable the required PDO driver before running DB operations.'),
            'state' => $ready ? 'ready' : 'blocked',
        ];
    }

    /**
     * @return array{label:string,value:string,detail:string,state:string}
     */
    private function backupDirectoryCheck(): array
    {
        $path = $this->backupDirectory();
        if (\is_dir($path) && \is_writable($path)) {
            return [
                'label' => (string)__('Backup Directory'),
                'value' => (string)__('Writable'),
                'detail' => (string)__('Database backup artifacts can be written inside the managed backup directory.'),
                'state' => 'ready',
            ];
        }

        $parent = \dirname($path);
        if (\is_dir($parent) && \is_writable($parent)) {
            return [
                'label' => (string)__('Backup Directory'),
                'value' => (string)__('Creatable'),
                'detail' => (string)__('The backup directory is not present yet, but the parent directory is writable.'),
                'state' => 'attention',
            ];
        }

        return [
            'label' => (string)__('Backup Directory'),
            'value' => (string)__('Blocked'),
            'detail' => (string)__('The managed backup directory or its parent is not writable.'),
            'state' => 'blocked',
        ];
    }

    /**
     * @param array<string, mixed> $envPlan
     * @return array{label:string,value:string,detail:string,state:string}
     */
    private function envBackupCheck(array $envPlan): array
    {
        $latest = \is_array($envPlan['latest_backup'] ?? null) ? (array)$envPlan['latest_backup'] : [];
        if ($latest !== []) {
            return [
                'label' => (string)__('Env Backup'),
                'value' => (string)__('Available'),
                'detail' => (string)__('A recent Database Manager env.php backup is available for rollback.'),
                'state' => 'ready',
            ];
        }

        return [
            'label' => (string)__('Env Backup'),
            'value' => (string)__('None'),
            'detail' => (string)__('Create an env backup before applying or rolling back database profile changes.'),
            'state' => 'attention',
        ];
    }

    /**
     * @param array<string, mixed> $backupPlan
     * @return array{label:string,value:string,detail:string,state:string}
     */
    private function backupPlanCheck(array $backupPlan): array
    {
        $action = \trim((string)($backupPlan['action'] ?? ''));
        $state = \trim((string)($backupPlan['state'] ?? 'idle'));
        if ($action === '') {
            return [
                'label' => (string)__('Backup Plan'),
                'value' => (string)__('Idle'),
                'detail' => (string)__('Select a backup, restore, or migration dry-run action when planning DB changes.'),
                'state' => 'ready',
            ];
        }

        $isReady = \in_array($state, ['ready_to_execute', 'ready_to_preflight', 'ready_to_restore_execute', 'ready_to_migration_preflight', 'ready_to_migration_execute', 'ready_to_sql_apply'], true);

        return [
            'label' => (string)__('Backup Plan'),
            'value' => $state !== '' ? $state : (string)__('Idle'),
            'detail' => $isReady
                ? (string)__('The selected backup plan has reached its guarded readiness state.')
                : (string)__('Resolve backup plan warnings before execution or preflight.'),
            'state' => $isReady ? 'ready' : ($state === 'blocked' ? 'blocked' : 'attention'),
        ];
    }

    /**
     * @param array<string, mixed> $slavePlan
     * @return array{label:string,value:string,detail:string,state:string}
     */
    private function slaveCheck(array $slavePlan): array
    {
        $slaves = \is_array($slavePlan['slaves'] ?? null) ? (array)$slavePlan['slaves'] : [];

        return [
            'label' => (string)__('Slave Profiles'),
            'value' => (string)\count($slaves),
            'detail' => $slaves !== []
                ? (string)__('Configured slave profiles are visible to the panel.')
                : (string)__('No slave profile is configured; this is optional for single-database projects.'),
            'state' => 'ready',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $profiles
     * @return array<string, mixed>
     */
    private function selectedProfile(array $profiles, string $selectedKey): array
    {
        foreach ($profiles as $profile) {
            if ((string)($profile['key'] ?? '') === $selectedKey) {
                return $profile;
            }
        }

        return [];
    }

    /**
     * @param array<int, array<string, string>> $checks
     * @return array<int, string>
     */
    private function actions(array $checks): array
    {
        $actions = [];
        foreach ($checks as $check) {
            if (($check['state'] ?? 'ready') === 'ready') {
                continue;
            }
            $actions[] = (string)($check['detail'] ?? '');
        }

        if ($actions === []) {
            $actions[] = (string)__('No blocking database panel health issues were detected.');
        }

        return \array_values(\array_filter(\array_unique($actions)));
    }

    private function stateLabel(string $state): string
    {
        return match ($state) {
            'blocked' => (string)__('Blocked'),
            'attention' => (string)__('Needs attention'),
            default => (string)__('Ready'),
        };
    }

    private function backupDirectory(): string
    {
        return (\defined('BP') ? BP : \getcwd() . DIRECTORY_SEPARATOR)
            . 'var' . DIRECTORY_SEPARATOR
            . 'backups' . DIRECTORY_SEPARATOR
            . 'wls' . DIRECTORY_SEPARATOR
            . 'db-manager' . DIRECTORY_SEPARATOR
            . 'database';
    }
}
