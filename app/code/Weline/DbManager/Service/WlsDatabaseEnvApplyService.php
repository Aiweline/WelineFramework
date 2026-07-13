<?php
declare(strict_types=1);

namespace Weline\DbManager\Service;

use Weline\DbManager\Model\WlsDatabaseProfile;
use Weline\Framework\App\Env;
use Weline\Server\Api\Control\RuntimeReloadGateway;

class WlsDatabaseEnvApplyService
{
    private const AUDIT_FILE = 'db-manager-audit.jsonl';
    private const APPLY_PHRASE = 'APPLY_DB_ENV';
    private const ROLLBACK_PHRASE = 'ROLLBACK_DB_ENV';
    private const SLAVE_CREATE_PHRASE = 'CREATE_DB_SLAVE';
    private const SLAVE_REMOVE_PHRASE = 'REMOVE_DB_SLAVE';

    public function __construct(
        private readonly WlsDatabaseProfileService $profileService,
        private readonly RuntimeReloadGateway $runtimeReloadGateway
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function getEnvPlan(array $context): array
    {
        return $this->publicPlan($this->buildEnvPlan($context, false));
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function getSlavePlan(array $context): array
    {
        return $this->publicSlavePlan($this->buildSlavePlan($context, false));
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool,message:string,backup_path:string,slave_key:string,runtime_action:string,runtime_action_success:bool,runtime_action_message:string}
     */
    public function createSlaveFromPanel(array $input): array
    {
        $profileKey = '';
        $targetPath = '';
        $slaveKey = '';
        try {
            if ((string)($input['confirm_slave_create'] ?? '0') !== '1') {
                throw new \InvalidArgumentException((string)__('Confirm the database slave create operation before submitting.'));
            }
            if (\trim((string)($input['confirm_phrase'] ?? '')) !== self::SLAVE_CREATE_PHRASE) {
                throw new \InvalidArgumentException((string)__('Type CREATE_DB_SLAVE to create a database slave profile.'));
            }

            $rawSlaveKey = \trim((string)($input['slave_key'] ?? ''));
            $slaveKey = $this->safeConnectionKey($rawSlaveKey);
            $this->assertCreatableSlaveKey($rawSlaveKey, $slaveKey);

            $context = $this->contextFromInput($input);
            $plan = $this->buildSlavePlan($context, true);
            if (empty($plan['can_create'])) {
                throw new \InvalidArgumentException((string)($plan['reason'] ?? __('Database slave create is not available.')));
            }

            $profileKey = (string)($plan['profile_key'] ?? '');
            $targetPath = (string)($plan['target_path'] ?? '');
            $dbConfig = \is_array($plan['db_config'] ?? null) ? $plan['db_config'] : [];
            $profileConfig = \is_array($plan['profile_config'] ?? null) ? $plan['profile_config'] : [];
            if ($profileConfig === []) {
                throw new \RuntimeException((string)__('No enabled project database profile was found.'));
            }
            if (!empty($this->resolveSlaveTargetConnection($dbConfig, $slaveKey)['success'])) {
                throw new \InvalidArgumentException((string)__('The selected database slave key already exists in app/etc/env.php.'));
            }

            $original = \file_get_contents($targetPath);
            if (!\is_string($original)) {
                throw new \RuntimeException((string)__('Unable to read app/etc/env.php before applying database changes.'));
            }

            $changes = [[
                'name' => 'slave',
                'label' => (string)__('Slave Profile'),
                'before' => (string)__('Missing'),
                'after' => 'db.slaves.' . $slaveKey,
            ]];
            $backup = $this->createBackup($targetPath, $original, $profileKey, $changes);
            $nextDb = $this->withCreatedSlave($dbConfig, $slaveKey, $profileConfig);
            if (!Env::getInstance()->setConfig('db', $nextDb)) {
                throw new \RuntimeException((string)__('Unable to write database config into app/etc/env.php.'));
            }

            $runtimeResult = $this->applyRuntimeFromInput($input);
            $this->appendAudit('env_slave_created', [
                'success' => true,
                'profile_key' => $profileKey,
                'slave_key' => $slaveKey,
                'target_label' => 'db.slaves.' . $slaveKey,
                'target_path' => $targetPath,
                'backup_path' => $backup['path'],
                'runtime_action' => $runtimeResult['action'],
                'runtime_action_success' => $runtimeResult['success'],
                'runtime_action_message' => $runtimeResult['message'],
            ]);

            return [
                'success' => true,
                'message' => (string)__('Database slave profile created and env backup created.'),
                'backup_path' => $backup['path'],
                'slave_key' => $slaveKey,
                'runtime_action' => $runtimeResult['action'],
                'runtime_action_success' => $runtimeResult['success'],
                'runtime_action_message' => $runtimeResult['message'],
            ];
        } catch (\Throwable $throwable) {
            $message = \mb_substr($throwable->getMessage(), 0, 220);
            $this->appendAudit('env_slave_create_failed', [
                'success' => false,
                'profile_key' => $profileKey,
                'slave_key' => $slaveKey,
                'target_path' => $targetPath,
                'message' => $message,
            ]);

            return [
                'success' => false,
                'message' => $message,
                'backup_path' => '',
                'slave_key' => $slaveKey,
                'runtime_action' => WlsDatabaseProfile::RUNTIME_ACTION_NONE,
                'runtime_action_success' => false,
                'runtime_action_message' => '',
            ];
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool,message:string,backup_path:string,slave_key:string,runtime_action:string,runtime_action_success:bool,runtime_action_message:string}
     */
    public function removeSlaveFromPanel(array $input): array
    {
        $profileKey = '';
        $targetPath = '';
        $slaveKey = '';
        try {
            if ((string)($input['confirm_slave_remove'] ?? '0') !== '1') {
                throw new \InvalidArgumentException((string)__('Confirm the database slave remove operation before submitting.'));
            }
            if (\trim((string)($input['confirm_phrase'] ?? '')) !== self::SLAVE_REMOVE_PHRASE) {
                throw new \InvalidArgumentException((string)__('Type REMOVE_DB_SLAVE to remove a database slave profile.'));
            }

            $slaveKey = $this->safeConnectionKey((string)($input['slave_key'] ?? ''));
            if ($slaveKey === '') {
                throw new \InvalidArgumentException((string)__('Select an existing database slave profile before removing it.'));
            }

            $context = $this->contextFromInput($input);
            $plan = $this->buildSlavePlan($context, true);
            if (empty($plan['can_remove'])) {
                throw new \InvalidArgumentException((string)($plan['reason'] ?? __('No database slave profiles are available to remove.')));
            }

            $profileKey = (string)($plan['profile_key'] ?? '');
            $targetPath = (string)($plan['target_path'] ?? '');
            $dbConfig = \is_array($plan['db_config'] ?? null) ? $plan['db_config'] : [];
            $targetConnection = $this->resolveSlaveTargetConnection($dbConfig, $slaveKey);
            if (empty($targetConnection['success'])) {
                throw new \InvalidArgumentException((string)($targetConnection['message'] ?? __('The selected database slave profile was not found in app/etc/env.php.')));
            }

            $original = \file_get_contents($targetPath);
            if (!\is_string($original)) {
                throw new \RuntimeException((string)__('Unable to read app/etc/env.php before applying database changes.'));
            }

            $label = (string)($targetConnection['label'] ?? ('db.slaves.' . $slaveKey));
            $changes = [[
                'name' => 'slave',
                'label' => (string)__('Slave Profile'),
                'before' => $label,
                'after' => (string)__('Removed'),
            ]];
            $backup = $this->createBackup($targetPath, $original, $profileKey, $changes);
            $nextDb = $this->withRemovedSlave($dbConfig, $targetConnection['slave_key'] ?? null);
            if (!Env::getInstance()->setConfig('db', $nextDb)) {
                throw new \RuntimeException((string)__('Unable to write database config into app/etc/env.php.'));
            }

            $runtimeResult = $this->applyRuntimeFromInput($input);
            $this->appendAudit('env_slave_removed', [
                'success' => true,
                'profile_key' => $profileKey,
                'slave_key' => $slaveKey,
                'target_label' => $label,
                'target_path' => $targetPath,
                'backup_path' => $backup['path'],
                'runtime_action' => $runtimeResult['action'],
                'runtime_action_success' => $runtimeResult['success'],
                'runtime_action_message' => $runtimeResult['message'],
            ]);

            return [
                'success' => true,
                'message' => (string)__('Database slave profile removed and env backup created.'),
                'backup_path' => $backup['path'],
                'slave_key' => $slaveKey,
                'runtime_action' => $runtimeResult['action'],
                'runtime_action_success' => $runtimeResult['success'],
                'runtime_action_message' => $runtimeResult['message'],
            ];
        } catch (\Throwable $throwable) {
            $message = \mb_substr($throwable->getMessage(), 0, 220);
            $this->appendAudit('env_slave_remove_failed', [
                'success' => false,
                'profile_key' => $profileKey,
                'slave_key' => $slaveKey,
                'target_path' => $targetPath,
                'message' => $message,
            ]);

            return [
                'success' => false,
                'message' => $message,
                'backup_path' => '',
                'slave_key' => $slaveKey,
                'runtime_action' => WlsDatabaseProfile::RUNTIME_ACTION_NONE,
                'runtime_action_success' => false,
                'runtime_action_message' => '',
            ];
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool,message:string,backup_path:string,change_count:int,runtime_action:string,runtime_action_success:bool,runtime_action_message:string}
     */
    public function applyEnvFromPanel(array $input): array
    {
        $profileKey = '';
        $targetPath = '';
        try {
            if ((string)($input['confirm_env_apply'] ?? '0') !== '1') {
                throw new \InvalidArgumentException((string)__('Confirm the database env apply operation before submitting.'));
            }
            if (\trim((string)($input['confirm_phrase'] ?? '')) !== self::APPLY_PHRASE) {
                throw new \InvalidArgumentException((string)__('Type APPLY_DB_ENV to apply database env changes.'));
            }

            $context = $this->contextFromInput($input);
            $plan = $this->buildEnvPlan($context, true);
            if (empty($plan['can_apply'])) {
                throw new \InvalidArgumentException((string)($plan['reason'] ?? __('Database env apply is not available.')));
            }

            $profileKey = (string)($plan['profile_key'] ?? '');
            $targetPath = (string)($plan['target_path'] ?? '');
            $changes = \is_array($plan['changes'] ?? null) ? $plan['changes'] : [];
            if ($changes === []) {
                $runtimeResult = $this->applyRuntimeFromInput($input);
                $this->appendAudit('env_apply_noop', [
                    'success' => true,
                    'profile_key' => $profileKey,
                    'target_label' => (string)($plan['target_label'] ?? ''),
                    'target_path' => $targetPath,
                    'message' => 'no_changes',
                    'runtime_action' => $runtimeResult['action'],
                    'runtime_action_success' => $runtimeResult['success'],
                    'runtime_action_message' => $runtimeResult['message'],
                ]);

                return [
                    'success' => true,
                    'message' => (string)__('No database env changes were needed.'),
                    'backup_path' => '',
                    'change_count' => 0,
                    'runtime_action' => $runtimeResult['action'],
                    'runtime_action_success' => $runtimeResult['success'],
                    'runtime_action_message' => $runtimeResult['message'],
                ];
            }

            $original = \file_get_contents($targetPath);
            if (!\is_string($original)) {
                throw new \RuntimeException((string)__('Unable to read app/etc/env.php before applying database changes.'));
            }

            $backup = $this->createBackup($targetPath, $original, $profileKey, $changes);
            $nextDb = \is_array($plan['next_db'] ?? null) ? $plan['next_db'] : [];
            if (!Env::getInstance()->setConfig('db', $nextDb)) {
                throw new \RuntimeException((string)__('Unable to write database config into app/etc/env.php.'));
            }

            $runtimeResult = $this->applyRuntimeFromInput($input);
            $this->appendAudit('env_applied', [
                'success' => true,
                'profile_key' => $profileKey,
                'target_label' => (string)($plan['target_label'] ?? ''),
                'target_path' => $targetPath,
                'backup_path' => $backup['path'],
                'change_count' => \count($changes),
                'runtime_action' => $runtimeResult['action'],
                'runtime_action_success' => $runtimeResult['success'],
                'runtime_action_message' => $runtimeResult['message'],
            ]);

            return [
                'success' => true,
                'message' => (string)__('Database env applied and backup created.'),
                'backup_path' => $backup['path'],
                'change_count' => \count($changes),
                'runtime_action' => $runtimeResult['action'],
                'runtime_action_success' => $runtimeResult['success'],
                'runtime_action_message' => $runtimeResult['message'],
            ];
        } catch (\Throwable $throwable) {
            $message = \mb_substr($throwable->getMessage(), 0, 220);
            $this->appendAudit('env_apply_failed', [
                'success' => false,
                'profile_key' => $profileKey,
                'target_path' => $targetPath,
                'message' => $message,
            ]);

            return [
                'success' => false,
                'message' => $message,
                'backup_path' => '',
                'change_count' => 0,
                'runtime_action' => WlsDatabaseProfile::RUNTIME_ACTION_NONE,
                'runtime_action_success' => false,
                'runtime_action_message' => '',
            ];
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool,message:string,target_path:string,runtime_action:string,runtime_action_success:bool,runtime_action_message:string}
     */
    public function rollbackEnvFromPanel(array $input): array
    {
        $backupPath = '';
        $targetPath = '';
        $profileKey = '';
        try {
            if ((string)($input['confirm_env_rollback'] ?? '0') !== '1') {
                throw new \InvalidArgumentException((string)__('Confirm the database env rollback operation before submitting.'));
            }
            if (\trim((string)($input['confirm_phrase'] ?? '')) !== self::ROLLBACK_PHRASE) {
                throw new \InvalidArgumentException((string)__('Type ROLLBACK_DB_ENV to restore the selected database env backup.'));
            }

            $backupPath = (string)($input['backup_path'] ?? '');
            $backup = $this->resolveBackupPath($backupPath);
            $meta = $this->loadBackupMeta($backup['path']);
            $profileKey = (string)($meta['profile_key'] ?? '');
            $targetPath = (string)($meta['target_path'] ?? '');
            $target = $this->resolveEnvFilePath($targetPath);
            if (empty($target['success'])) {
                throw new \RuntimeException((string)$target['message']);
            }
            if ((string)($meta['target_hash'] ?? '') !== \hash('sha256', (string)$target['path'])) {
                throw new \RuntimeException((string)__('The selected backup does not belong to the current env.php target.'));
            }

            $content = \file_get_contents((string)$backup['path']);
            if (!\is_string($content)) {
                throw new \RuntimeException((string)__('Unable to read the selected database env backup.'));
            }

            $this->writeEnvFile((string)$target['path'], $content);
            Env::getInstance()->reload();
            $runtimeResult = $this->applyRuntimeFromInput($input);
            $this->appendAudit('env_rolled_back', [
                'success' => true,
                'profile_key' => $profileKey,
                'target_path' => (string)$target['path'],
                'backup_path' => (string)$backup['path'],
                'runtime_action' => $runtimeResult['action'],
                'runtime_action_success' => $runtimeResult['success'],
                'runtime_action_message' => $runtimeResult['message'],
            ]);

            return [
                'success' => true,
                'message' => (string)__('Database env restored from backup.'),
                'target_path' => (string)$target['path'],
                'runtime_action' => $runtimeResult['action'],
                'runtime_action_success' => $runtimeResult['success'],
                'runtime_action_message' => $runtimeResult['message'],
            ];
        } catch (\Throwable $throwable) {
            $message = \mb_substr($throwable->getMessage(), 0, 220);
            $this->appendAudit('env_rollback_failed', [
                'success' => false,
                'profile_key' => $profileKey,
                'target_path' => $targetPath,
                'backup_path' => $backupPath,
                'message' => $message,
            ]);

            return [
                'success' => false,
                'message' => $message,
                'target_path' => $targetPath,
                'runtime_action' => WlsDatabaseProfile::RUNTIME_ACTION_NONE,
                'runtime_action_success' => false,
                'runtime_action_message' => '',
            ];
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function buildEnvPlan(array $context, bool $includeSensitive): array
    {
        $latestBackup = null;
        try {
            $profile = $this->profileService->loadForContext($context);
            if (!$profile instanceof WlsDatabaseProfile || (int)$profile->getData(WlsDatabaseProfile::schema_fields_ID) <= 0) {
                return $this->emptyPlan((string)__('Save a Project Database Profile before applying app/etc/env.php.'));
            }
            $profileKey = (string)$profile->getData(WlsDatabaseProfile::schema_fields_PROFILE_KEY);
            if ((int)$profile->getData(WlsDatabaseProfile::schema_fields_ENABLED) !== 1) {
                return $this->emptyPlan((string)__('Enable the Project Database Profile before applying app/etc/env.php.'), $profileKey);
            }

            $target = $this->resolveEnvFilePath(Env::path_ENV_FILE);
            if (empty($target['success'])) {
                return $this->emptyPlan((string)$target['message'], $profileKey);
            }
            $targetPath = (string)$target['path'];
            $latestBackup = $this->latestBackupFor($profileKey, $targetPath);
            $dbConfig = (array)Env::getInstance()->getConfig('db', []);
            $targetConnection = $this->resolveTargetConnection(
                $dbConfig,
                (string)$profile->getData(WlsDatabaseProfile::schema_fields_SOURCE_CONNECTION_KEY)
            );
            if (empty($targetConnection['success'])) {
                return $this->emptyPlan((string)$targetConnection['message'], $profileKey, $latestBackup);
            }

            $existing = \is_array($targetConnection['existing'] ?? null) ? $targetConnection['existing'] : [];
            $profileConfig = $this->profileService->buildConnectionConfigForContextWithSource($context, $existing);
            if ($profileConfig === null) {
                return $this->emptyPlan((string)__('No enabled project database profile was found.'), $profileKey, $latestBackup);
            }

            $nextConnection = \array_replace($existing, $profileConfig);
            $nextDb = $this->buildNextDbConfig(
                $dbConfig,
                $targetConnection,
                $nextConnection
            );
            $changes = $this->diffConnection($existing, $nextConnection);
            $passwordSource = \trim((string)$profile->getData(WlsDatabaseProfile::schema_fields_PASSWORD_SECRET)) !== ''
                ? 'profile'
                : (\trim((string)($existing['password'] ?? '')) !== '' ? 'env' : 'empty');

            $plan = [
                'can_apply' => true,
                'reason' => '',
                'target_label' => (string)$targetConnection['label'],
                'target_mode' => (string)$targetConnection['mode'],
                'target_path' => $targetPath,
                'profile_key' => $profileKey,
                'source_connection_key' => (string)$profile->getData(WlsDatabaseProfile::schema_fields_SOURCE_CONNECTION_KEY),
                'password_source' => $passwordSource,
                'changes' => $changes,
                'change_count' => \count($changes),
                'latest_backup' => $latestBackup,
                'apply_phrase' => self::APPLY_PHRASE,
                'rollback_phrase' => self::ROLLBACK_PHRASE,
            ];
            if ($includeSensitive) {
                $plan['next_db'] = $nextDb;
            }

            return $plan;
        } catch (\Throwable $throwable) {
            return $this->emptyPlan(\mb_substr($throwable->getMessage(), 0, 220), '', $latestBackup);
        }
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function publicPlan(array $plan): array
    {
        unset($plan['next_db']);
        return $plan;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function buildSlavePlan(array $context, bool $includeSensitive): array
    {
        $latestBackup = null;
        try {
            $profile = $this->profileService->loadForContext($context);
            if (!$profile instanceof WlsDatabaseProfile || (int)$profile->getData(WlsDatabaseProfile::schema_fields_ID) <= 0) {
                return $this->emptySlavePlan((string)__('Save a Project Database Profile before creating or removing database slave profiles.'));
            }
            $profileKey = (string)$profile->getData(WlsDatabaseProfile::schema_fields_PROFILE_KEY);
            if ((int)$profile->getData(WlsDatabaseProfile::schema_fields_ENABLED) !== 1) {
                return $this->emptySlavePlan((string)__('Enable the Project Database Profile before creating or removing database slave profiles.'), $profileKey);
            }

            $target = $this->resolveEnvFilePath(Env::path_ENV_FILE);
            if (empty($target['success'])) {
                return $this->emptySlavePlan((string)$target['message'], $profileKey);
            }
            $targetPath = (string)$target['path'];
            $latestBackup = $this->latestBackupFor($profileKey, $targetPath);
            $dbConfig = (array)Env::getInstance()->getConfig('db', []);
            $sourceKey = (string)$profile->getData(WlsDatabaseProfile::schema_fields_SOURCE_CONNECTION_KEY);
            $sourceConnection = $this->resolveTargetConnection($dbConfig, $sourceKey);
            $sourceProfile = !empty($sourceConnection['success']) && \is_array($sourceConnection['existing'] ?? null)
                ? (array)$sourceConnection['existing']
                : [];
            $profileConfig = $this->profileService->buildConnectionConfigForContextWithSource($context, $sourceProfile);
            if ($profileConfig === null) {
                return $this->emptySlavePlan((string)__('No enabled project database profile was found.'), $profileKey, $latestBackup);
            }

            $slaves = $this->slaveSummaries($dbConfig);
            $passwordSource = \trim((string)$profile->getData(WlsDatabaseProfile::schema_fields_PASSWORD_SECRET)) !== ''
                ? 'profile'
                : (\trim((string)($sourceProfile['password'] ?? '')) !== '' ? 'env' : 'empty');

            $plan = [
                'can_create' => true,
                'can_remove' => $slaves !== [],
                'reason' => '',
                'target_path' => $targetPath,
                'profile_key' => $profileKey,
                'source_connection_key' => $this->safeConnectionKey($sourceKey),
                'password_source' => $passwordSource,
                'slaves' => $slaves,
                'latest_backup' => $latestBackup,
                'create_phrase' => self::SLAVE_CREATE_PHRASE,
                'remove_phrase' => self::SLAVE_REMOVE_PHRASE,
            ];
            if ($includeSensitive) {
                $plan['db_config'] = $dbConfig;
                $plan['profile_config'] = $profileConfig;
            }

            return $plan;
        } catch (\Throwable $throwable) {
            return $this->emptySlavePlan(\mb_substr($throwable->getMessage(), 0, 220), '', $latestBackup);
        }
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function publicSlavePlan(array $plan): array
    {
        unset($plan['db_config'], $plan['profile_config']);
        return $plan;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    private function contextFromInput(array $input): array
    {
        return [
            'profile_key' => \trim((string)($input['profile_key'] ?? '')),
            'project_id' => \trim((string)($input['project_id'] ?? '')),
            'domain' => \trim((string)($input['domain'] ?? '')),
            'project_type' => \trim((string)($input['project_type'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $dbConfig
     * @return array{success:bool,mode?:string,label?:string,existing?:array<string, mixed>,slave_key?:int|string,message?:string}
     */
    private function resolveTargetConnection(array $dbConfig, string $sourceKey): array
    {
        $sourceKey = $this->safeConnectionKey($sourceKey);
        if ($sourceKey !== '' && !\in_array($sourceKey, ['master', 'default'], true)) {
            return $this->resolveSlaveTargetConnection($dbConfig, $sourceKey);
        }

        if (\is_array($dbConfig['master'] ?? null)) {
            return [
                'success' => true,
                'mode' => 'master',
                'label' => 'db.master',
                'existing' => (array)$dbConfig['master'],
            ];
        }

        return [
            'success' => true,
            'mode' => 'direct',
            'label' => 'db',
            'existing' => $dbConfig,
        ];
    }

    /**
     * @param array<string, mixed> $dbConfig
     * @return array{success:bool,mode?:string,label?:string,existing?:array<string, mixed>,slave_key?:int|string,message?:string}
     */
    private function resolveSlaveTargetConnection(array $dbConfig, string $sourceKey): array
    {
        $slaves = \is_array($dbConfig['slaves'] ?? null) ? (array)$dbConfig['slaves'] : [];
        if ($slaves === []) {
            return [
                'success' => false,
                'message' => (string)__('The selected slave database profile was not found in app/etc/env.php. Save or select an existing env slave before applying.'),
            ];
        }

        $index = 1;
        $seenKeys = [];
        foreach ($slaves as $key => $slaveConfig) {
            if (!\is_array($slaveConfig)) {
                continue;
            }
            $profileKey = \is_string($key) && \trim($key) !== ''
                ? $this->safeConnectionKey($key)
                : 'slave_' . $index;
            if ($profileKey === '' || isset($seenKeys[$profileKey])) {
                $profileKey = 'slave_' . $index;
            }
            $seenKeys[$profileKey] = true;

            if ($profileKey === $sourceKey) {
                return [
                    'success' => true,
                    'mode' => 'slave',
                    'label' => $this->slaveTargetLabel($key),
                    'existing' => (array)$slaveConfig,
                    'slave_key' => $key,
                ];
            }
            $index++;
        }

        return [
            'success' => false,
            'message' => (string)__('The selected slave database profile was not found in app/etc/env.php. Save or select an existing env slave before applying.'),
        ];
    }

    /**
     * @param array<string, mixed> $dbConfig
     * @param array<string, mixed> $targetConnection
     * @param array<string, mixed> $nextConnection
     * @return array<string, mixed>
     */
    private function buildNextDbConfig(array $dbConfig, array $targetConnection, array $nextConnection): array
    {
        $mode = (string)($targetConnection['mode'] ?? '');
        if ($mode === 'master') {
            $dbConfig['default'] = \trim((string)($dbConfig['default'] ?? '')) !== '' ? (string)$dbConfig['default'] : 'master';
            $dbConfig['master'] = $nextConnection;
            if (!\is_array($dbConfig['slaves'] ?? null)) {
                $dbConfig['slaves'] = [];
            }
            return $dbConfig;
        }

        if ($mode === 'slave') {
            $slaveKey = $targetConnection['slave_key'] ?? null;
            if (!\is_int($slaveKey) && !\is_string($slaveKey)) {
                throw new \RuntimeException((string)__('The selected slave database profile was not found in app/etc/env.php. Save or select an existing env slave before applying.'));
            }
            if (!\is_array($dbConfig['slaves'] ?? null) || !\array_key_exists($slaveKey, (array)$dbConfig['slaves'])) {
                throw new \RuntimeException((string)__('The selected slave database profile was not found in app/etc/env.php. Save or select an existing env slave before applying.'));
            }
            $slaves = (array)$dbConfig['slaves'];
            $slaves[$slaveKey] = $nextConnection;
            $dbConfig['slaves'] = $slaves;
            return $dbConfig;
        }

        return \array_replace($dbConfig, $nextConnection);
    }

    /**
     * @param array<string, mixed> $dbConfig
     * @param array<string, mixed> $slaveConnection
     * @return array<string, mixed>
     */
    private function withCreatedSlave(array $dbConfig, string $slaveKey, array $slaveConnection): array
    {
        $slaves = \is_array($dbConfig['slaves'] ?? null) ? (array)$dbConfig['slaves'] : [];
        $slaves[$slaveKey] = $slaveConnection;
        $dbConfig['slaves'] = $slaves;
        return $dbConfig;
    }

    /**
     * @param array<string, mixed> $dbConfig
     * @return array<string, mixed>
     */
    private function withRemovedSlave(array $dbConfig, int|string|null $slaveKey): array
    {
        if (!\is_int($slaveKey) && !\is_string($slaveKey)) {
            throw new \RuntimeException((string)__('The selected database slave profile was not found in app/etc/env.php.'));
        }
        $slaves = \is_array($dbConfig['slaves'] ?? null) ? (array)$dbConfig['slaves'] : [];
        if (!\array_key_exists($slaveKey, $slaves)) {
            throw new \RuntimeException((string)__('The selected database slave profile was not found in app/etc/env.php.'));
        }

        unset($slaves[$slaveKey]);
        $dbConfig['slaves'] = $slaves;
        return $dbConfig;
    }

    private function assertCreatableSlaveKey(string $rawSlaveKey, string $slaveKey): void
    {
        if ($slaveKey === '' || $rawSlaveKey !== $slaveKey || !\preg_match('/^[a-z][a-z0-9_.-]{0,79}$/', $slaveKey)) {
            throw new \InvalidArgumentException((string)__('Use a lowercase slave key that starts with a letter and contains only letters, numbers, dot, underscore, or hyphen.'));
        }
        if (\in_array($slaveKey, ['master', 'default', 'db', 'slaves', 'project_profile'], true)) {
            throw new \InvalidArgumentException((string)__('The selected database slave key is reserved.'));
        }
    }

    /**
     * @param array<string, mixed> $dbConfig
     * @return array<int, array<string, string>>
     */
    private function slaveSummaries(array $dbConfig): array
    {
        $slaves = \is_array($dbConfig['slaves'] ?? null) ? (array)$dbConfig['slaves'] : [];
        $summaries = [];
        $index = 1;
        $seenKeys = [];
        foreach ($slaves as $key => $slaveConfig) {
            if (!\is_array($slaveConfig)) {
                continue;
            }
            $profileKey = \is_string($key) && \trim($key) !== ''
                ? $this->safeConnectionKey($key)
                : 'slave_' . $index;
            if ($profileKey === '' || isset($seenKeys[$profileKey])) {
                $profileKey = 'slave_' . $index;
            }
            $seenKeys[$profileKey] = true;

            $type = \strtolower(\trim((string)($slaveConfig['type'] ?? $slaveConfig['driver'] ?? '')));
            if ($type === '') {
                $type = \trim((string)($slaveConfig['path'] ?? '')) !== '' ? 'sqlite' : 'mysql';
            }
            $summaries[] = [
                'key' => $profileKey,
                'label' => $this->slaveTargetLabel($key),
                'type' => $type,
                'database' => \mb_substr(\trim((string)($slaveConfig['database'] ?? $slaveConfig['dbname'] ?? $slaveConfig['name'] ?? $slaveConfig['path'] ?? '')), 0, 120),
                'username' => $this->maskValue((string)($slaveConfig['username'] ?? $slaveConfig['user'] ?? '')),
                'password_state' => \trim((string)($slaveConfig['password'] ?? '')) !== '' ? (string)__('Configured') : (string)__('Empty'),
            ];
            $index++;
        }

        return $summaries;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<int, array{name:string,label:string,before:string,after:string}>
     */
    private function diffConnection(array $before, array $after): array
    {
        $fields = [
            'type' => (string)__('Driver'),
            'hostname' => (string)__('Host'),
            'hostport' => (string)__('Port'),
            'database' => (string)__('Database'),
            'path' => (string)__('SQLite path'),
            'username' => (string)__('Username'),
            'password' => (string)__('Password'),
            'prefix' => (string)__('Prefix'),
            'charset' => (string)__('Charset'),
            'collate' => (string)__('Collation'),
            'persistent' => (string)__('Persistent connection'),
            'pre_sql' => (string)__('Pre SQL'),
        ];
        $changes = [];
        foreach ($fields as $field => $label) {
            $beforeValue = $before[$field] ?? '';
            $afterValue = $after[$field] ?? '';
            if ($field === 'password') {
                if ($this->passwordState($beforeValue) === $this->passwordState($afterValue)
                    && \hash('sha256', (string)$beforeValue) === \hash('sha256', (string)$afterValue)) {
                    continue;
                }
                $changes[] = [
                    'name' => $field,
                    'label' => $label,
                    'before' => $this->passwordState($beforeValue),
                    'after' => $this->passwordState($afterValue) === $this->passwordState($beforeValue)
                        ? (string)__('Configured (updated)')
                        : $this->passwordState($afterValue),
                ];
                continue;
            }

            if ($this->normalizeCompareValue($beforeValue) === $this->normalizeCompareValue($afterValue)) {
                continue;
            }
            $changes[] = [
                'name' => $field,
                'label' => $label,
                'before' => $this->displayValue($field, $beforeValue),
                'after' => $this->displayValue($field, $afterValue),
            ];
        }

        return $changes;
    }

    private function normalizeCompareValue(mixed $value): string
    {
        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (\is_int($value) || \is_float($value)) {
            return (string)$value;
        }

        return \trim((string)$value);
    }

    private function displayValue(string $field, mixed $value): string
    {
        if (\is_bool($value)) {
            return $value ? (string)__('Enabled') : (string)__('Disabled');
        }
        if ($field === 'persistent') {
            return !empty($value) ? (string)__('Enabled') : (string)__('Disabled');
        }
        if ($field === 'username') {
            return $this->maskValue((string)$value) ?: '-';
        }

        $value = \trim((string)$value);
        return $value !== '' ? \mb_substr($value, 0, 180) : '-';
    }

    private function passwordState(mixed $value): string
    {
        return \trim((string)$value) !== '' ? (string)__('Configured') : (string)__('Empty');
    }

    /**
     * @return array{success:bool,path?:string,message:string}
     */
    private function resolveEnvFilePath(string $path): array
    {
        $expected = \realpath(Env::path_ENV_FILE);
        $real = \realpath(\trim($path));
        if ($expected === false || $real === false || $real !== $expected) {
            return ['success' => false, 'message' => (string)__('Only the current app/etc/env.php file can be managed by Database Manager.')];
        }
        if (!\is_file($real) || !\is_readable($real) || !\is_writable($real)) {
            return ['success' => false, 'message' => (string)__('app/etc/env.php must be readable and writable before apply or rollback.')];
        }

        return ['success' => true, 'path' => $real, 'message' => ''];
    }

    /**
     * @param array<int, array<string, string>> $changes
     * @return array{path:string,meta_path:string}
     */
    private function createBackup(string $targetPath, string $content, string $profileKey, array $changes): array
    {
        $dir = $this->backupDir();
        if (!\is_dir($dir) && !\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            throw new \RuntimeException((string)__('Unable to create the Database Manager backup directory.'));
        }

        $safeProfile = \preg_replace('/[^a-zA-Z0-9_.-]+/', '-', $profileKey) ?: 'local';
        $stamp = \date('Ymd-His');
        $suffix = \substr(\hash('sha256', $targetPath . \microtime(true)), 0, 10);
        $backupPath = $dir . \DIRECTORY_SEPARATOR . $safeProfile . '-' . $stamp . '-' . $suffix . '.env.php.bak';
        if (\file_put_contents($backupPath, $content, \LOCK_EX) === false) {
            throw new \RuntimeException((string)__('Unable to write the database env backup file.'));
        }

        $meta = [
            'time' => \date('c'),
            'profile_key' => $profileKey,
            'target_path' => $targetPath,
            'target_hash' => \hash('sha256', $targetPath),
            'backup_path' => $backupPath,
            'changes' => $changes,
        ];
        $metaPath = $backupPath . '.json';
        if (\file_put_contents($metaPath, \json_encode($meta, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), \LOCK_EX) === false) {
            throw new \RuntimeException((string)__('Unable to write the database env backup metadata.'));
        }

        return ['path' => $backupPath, 'meta_path' => $metaPath];
    }

    /**
     * @return array{path:string,meta_path:string,time:string,profile_key:string,target_path:string}|null
     */
    private function latestBackupFor(string $profileKey, string $targetPath): ?array
    {
        $dir = $this->backupDir();
        if (!\is_dir($dir)) {
            return null;
        }
        $targetHash = \hash('sha256', $targetPath);
        $matches = [];
        foreach (\glob($dir . \DIRECTORY_SEPARATOR . '*.env.php.bak.json') ?: [] as $metaPath) {
            $decoded = \json_decode((string)\file_get_contents($metaPath), true);
            if (!\is_array($decoded)) {
                continue;
            }
            if ((string)($decoded['profile_key'] ?? '') !== $profileKey || (string)($decoded['target_hash'] ?? '') !== $targetHash) {
                continue;
            }
            $backupPath = (string)($decoded['backup_path'] ?? '');
            if (!\is_file($backupPath)) {
                continue;
            }
            $matches[] = [
                'path' => $backupPath,
                'meta_path' => (string)$metaPath,
                'time' => (string)($decoded['time'] ?? ''),
                'profile_key' => $profileKey,
                'target_path' => $targetPath,
            ];
        }
        \usort($matches, static fn (array $a, array $b): int => \strcmp((string)$b['time'], (string)$a['time']));

        return $matches[0] ?? null;
    }

    /**
     * @return array{path:string}
     */
    private function resolveBackupPath(string $backupPath): array
    {
        $real = \realpath(\trim($backupPath));
        if ($real === false || !\is_file($real) || !\is_readable($real)) {
            throw new \RuntimeException((string)__('The selected database env backup is not readable.'));
        }
        if (!$this->pathWithin($real, $this->backupDir())) {
            throw new \RuntimeException((string)__('The selected backup is outside the Database Manager backup directory.'));
        }
        if (!\str_ends_with($real, '.env.php.bak')) {
            throw new \RuntimeException((string)__('The selected backup file is not a Database Manager env.php backup.'));
        }

        return ['path' => $real];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadBackupMeta(string $backupPath): array
    {
        $metaPath = $backupPath . '.json';
        $decoded = \json_decode((string)@\file_get_contents($metaPath), true);
        if (!\is_array($decoded) || (string)($decoded['backup_path'] ?? '') === '' || (string)($decoded['target_path'] ?? '') === '') {
            throw new \RuntimeException((string)__('The selected database env backup metadata is missing or invalid.'));
        }

        return $decoded;
    }

    private function writeEnvFile(string $targetPath, string $content): void
    {
        if (\file_put_contents($targetPath, $content, \LOCK_EX) === false) {
            throw new \RuntimeException((string)__('Unable to write app/etc/env.php from the selected backup.'));
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{action:string,success:bool,message:string}
     */
    private function applyRuntimeFromInput(array $input): array
    {
        $action = \strtolower(\trim((string)($input['runtime_action'] ?? WlsDatabaseProfile::RUNTIME_ACTION_NONE)));
        if (!\in_array($action, [WlsDatabaseProfile::RUNTIME_ACTION_NONE, WlsDatabaseProfile::RUNTIME_ACTION_RELOAD], true)) {
            $action = WlsDatabaseProfile::RUNTIME_ACTION_NONE;
        }
        if ($action === WlsDatabaseProfile::RUNTIME_ACTION_NONE) {
            return [
                'action' => $action,
                'success' => true,
                'message' => (string)__('Runtime reload skipped.'),
            ];
        }

        $instance = $this->normalizeInstanceName((string)($input['runtime_instance'] ?? ''));
        if ($instance === '') {
            return [
                'action' => $action,
                'success' => false,
                'message' => (string)__('Select a running WLS instance before requesting reload.'),
            ];
        }

        $result = $this->runtimeReloadGateway->forceReloadAsync($instance, 8.0);
        return [
            'action' => $action,
            'success' => $result->success,
            'message' => \mb_substr($result->message, 0, 220),
        ];
    }

    private function normalizeInstanceName(string $instanceName): string
    {
        $instanceName = \trim($instanceName);
        $instanceName = \preg_replace('/[^a-zA-Z0-9_.:-]/', '', $instanceName) ?? '';
        return \substr($instanceName, 0, 120);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPlan(string $reason, string $profileKey = '', ?array $latestBackup = null): array
    {
        return [
            'can_apply' => false,
            'reason' => $reason,
            'target_label' => 'db.master',
            'target_mode' => '',
            'target_path' => '',
            'profile_key' => $profileKey,
            'source_connection_key' => '',
            'password_source' => 'empty',
            'changes' => [],
            'change_count' => 0,
            'latest_backup' => $latestBackup,
            'apply_phrase' => self::APPLY_PHRASE,
            'rollback_phrase' => self::ROLLBACK_PHRASE,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySlavePlan(string $reason, string $profileKey = '', ?array $latestBackup = null): array
    {
        return [
            'can_create' => false,
            'can_remove' => false,
            'reason' => $reason,
            'target_path' => '',
            'profile_key' => $profileKey,
            'source_connection_key' => '',
            'password_source' => 'empty',
            'slaves' => [],
            'latest_backup' => $latestBackup,
            'create_phrase' => self::SLAVE_CREATE_PHRASE,
            'remove_phrase' => self::SLAVE_REMOVE_PHRASE,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function appendAudit(string $event, array $payload): void
    {
        $dir = \dirname($this->auditPath());
        if (!\is_dir($dir)) {
            \mkdir($dir, 0775, true);
        }
        $record = [
            'time' => \date('c'),
            'event' => $event,
            'payload' => $payload,
        ];
        \file_put_contents(
            $this->auditPath(),
            \json_encode($record, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . \PHP_EOL,
            \FILE_APPEND | \LOCK_EX
        );
    }

    private function auditPath(): string
    {
        return $this->bpPath('var' . \DIRECTORY_SEPARATOR . 'log' . \DIRECTORY_SEPARATOR . 'wls' . \DIRECTORY_SEPARATOR . self::AUDIT_FILE);
    }

    private function backupDir(): string
    {
        return $this->bpPath('var' . \DIRECTORY_SEPARATOR . 'backups' . \DIRECTORY_SEPARATOR . 'wls' . \DIRECTORY_SEPARATOR . 'db-manager');
    }

    private function bpPath(string $relative): string
    {
        return \rtrim((string)BP, '\\/') . \DIRECTORY_SEPARATOR . \ltrim($relative, '\\/');
    }

    private function pathWithin(string $path, string $root): bool
    {
        $path = \strtolower(\str_replace('\\', '/', \rtrim($path, '\\/')));
        $root = \strtolower(\str_replace('\\', '/', \rtrim($root, '\\/')));
        return $path === $root || \str_starts_with($path, $root . '/');
    }

    private function safeConnectionKey(string $key): string
    {
        $key = \strtolower(\trim($key));
        $key = \preg_replace('/[^a-z0-9_.-]+/', '_', $key) ?? '';
        return \trim($key, '_.-');
    }

    private function slaveTargetLabel(int|string $key): string
    {
        if (\is_string($key) && \trim($key) !== '') {
            return 'db.slaves.' . \trim($key);
        }

        return 'db.slaves[' . (string)$key . ']';
    }

    private function maskValue(string $value): string
    {
        $value = \trim($value);
        $length = \strlen($value);
        if ($value === '') {
            return '';
        }
        if ($length <= 2) {
            return \str_repeat('*', $length);
        }

        return \substr($value, 0, 1) . \str_repeat('*', \max(1, $length - 2)) . \substr($value, -1);
    }
}
