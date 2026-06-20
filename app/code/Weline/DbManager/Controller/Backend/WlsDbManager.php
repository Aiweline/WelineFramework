<?php
declare(strict_types=1);

namespace Weline\DbManager\Controller\Backend;

use Weline\DbManager\Service\WlsDatabaseBackupPlanService;
use Weline\DbManager\Service\WlsDatabaseEnvApplyService;
use Weline\DbManager\Service\WlsDatabaseLifecyclePlanService;
use Weline\DbManager\Service\WlsDatabaseProfileService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\Env;

#[Acl('Weline_DbManager::wls_db_manager', 'WLS Database Manager', 'mdi mdi-database-cog-outline', 'WLS Panel database profile entry', 'Weline_Backend::system_maintenance')]
class WlsDbManager extends BackendController
{
    public function __construct(
        private readonly WlsDatabaseProfileService $profileService,
        private readonly WlsDatabaseEnvApplyService $envApplyService
    ) {
    }

    #[Acl('Weline_DbManager::wls_db_manager_index', 'View WLS Database Manager', 'mdi mdi-database-search-outline', 'View WLS Panel database profiles')]
    public function getIndex(): string
    {
        $this->useStandaloneLayout();

        $context = $this->requestContext();
        $rawProfiles = $this->rawDatabaseProfiles();
        $profiles = $this->databaseProfiles($rawProfiles);
        $selectedKey = $this->selectedConnectionKey($profiles);
        $selectedProfile = is_array($profiles[$selectedKey] ?? null) ? $profiles[$selectedKey] : [];
        $projectProfile = $this->profileService->getFormData($context, $rawProfiles, $selectedKey);
        $envPlan = $this->envApplyService->getEnvPlan($context);
        $lifecyclePlan = (new WlsDatabaseLifecyclePlanService())->buildPlan(
            $this->lifecyclePlanInput(),
            $projectProfile,
            $selectedProfile
        );
        $backupPlan = (new WlsDatabaseBackupPlanService())->buildPlan(
            $this->backupPlanInput(),
            $projectProfile,
            $selectedProfile
        );

        $this->assign('title', __('WLS Database Manager'));
        $this->assign('page_title', __('WLS Database Manager'));
        $this->assign('wlsDbManagerContext', $context);
        $this->assign('wlsDbManagerProfiles', array_values($profiles));
        $this->assign('wlsDbManagerSelectedKey', $selectedKey);
        $this->assign('wlsDbManagerRequestedConnectionKey', $this->safeConnectionKey((string)$this->request->getGet('connection_key', '')));
        $this->assign('wlsDbManagerProjectProfile', $projectProfile);
        $this->assign('wlsDbManagerEnvPlan', $envPlan);
        $this->assign('wlsDbManagerLifecyclePlan', $lifecyclePlan);
        $this->assign('wlsDbManagerBackupPlan', $backupPlan);
        $this->assign('wlsDbManagerAuditRecords', $this->profileService->getRecentAuditRecords());
        $this->assign('wlsDbManagerSource', $this->databaseConfigSource());
        $this->assign('wlsDbManagerCapabilities', $this->capabilityCards($profiles, $projectProfile));
        $this->assign('wlsDbManagerNotice', $this->resolveNotice((string)$this->request->getGet('dbm_notice', '')));
        $this->assign('wlsDbManagerError', mb_substr(trim((string)$this->request->getGet('dbm_error', '')), 0, 220));
        $this->assign('wlsDbManagerTestConnectionUrl', $this->_url->getBackendUrl('weline_dbmanager/backend/wls-db-manager/test-connection'));
        $this->assign('wlsDbManagerProfileSaveUrl', $this->_url->getBackendUrl('weline_dbmanager/backend/wls-db-manager/profile-save'));
        $this->assign('wlsDbManagerEnvApplyUrl', $this->_url->getBackendUrl('weline_dbmanager/backend/wls-db-manager/env-apply'));
        $this->assign('wlsDbManagerEnvRollbackUrl', $this->_url->getBackendUrl('weline_dbmanager/backend/wls-db-manager/env-rollback'));

        return $this->fetch('index');
    }

    /**
     * @return array<string, string>
     */
    private function lifecyclePlanInput(): array
    {
        return [
            'lifecycle_action' => trim((string)$this->request->getGet('lifecycle_action', '')),
            'lifecycle_database' => trim((string)$this->request->getGet('lifecycle_database', '')),
            'lifecycle_username' => trim((string)$this->request->getGet('lifecycle_username', '')),
            'lifecycle_host' => trim((string)$this->request->getGet('lifecycle_host', '')),
            'lifecycle_grant_mode' => trim((string)$this->request->getGet('lifecycle_grant_mode', 'read_write')),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function backupPlanInput(): array
    {
        return [
            'backup_action' => trim((string)$this->request->getGet('backup_action', '')),
            'backup_scope' => trim((string)$this->request->getGet('backup_scope', 'schema_and_data')),
            'backup_artifact' => trim((string)$this->request->getGet('backup_artifact', '')),
            'migration_target' => trim((string)$this->request->getGet('migration_target', '')),
        ];
    }

    #[Acl('Weline_DbManager::wls_db_manager_profile_save', 'Save WLS Database Profile', 'mdi mdi-content-save-cog-outline', 'Save WLS Panel project database profile')]
    public function postProfileSave(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToDbManager(['dbm_error' => (string)__('Invalid request method.')], '#project-profile');
            return '';
        }

        $post = (array)$this->request->getPost();
        $params = $this->contextFromInput($post);
        $params['connection_key'] = $this->safeConnectionKey((string)($post['source_connection_key'] ?? $post['connection_key'] ?? ''));
        $result = $this->profileService->saveFromPanel($post);

        if (!empty($result['success'])) {
            $runtimeAction = (string)($result['runtime_action'] ?? 'none');
            $runtimeSuccess = (bool)($result['runtime_action_success'] ?? false);
            $params['dbm_notice'] = $runtimeAction === 'reload' && $runtimeSuccess
                ? 'profile_saved_reload_requested'
                : 'profile_saved';
            if ($runtimeAction === 'reload' && !$runtimeSuccess) {
                $params['dbm_error'] = mb_substr((string)($result['runtime_action_message'] ?? __('WLS reload was not completed.')), 0, 220);
            }
        } else {
            $params['dbm_error'] = mb_substr((string)($result['message'] ?? __('Project database profile save failed.')), 0, 220);
        }

        $this->redirectToDbManager($params, '#project-profile');
        return '';
    }

    #[Acl('Weline_DbManager::wls_db_manager_env_apply', 'Apply WLS Database env', 'mdi mdi-file-cog-outline', 'Apply WLS Panel project database profile to env.php')]
    public function postEnvApply(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToDbManager(['dbm_error' => (string)__('Invalid request method.')], '#env-apply');
            return '';
        }

        $post = (array)$this->request->getPost();
        $params = $this->contextFromInput($post);
        $result = $this->envApplyService->applyEnvFromPanel($post);

        if (!empty($result['success'])) {
            $runtimeAction = (string)($result['runtime_action'] ?? 'none');
            $runtimeSuccess = (bool)($result['runtime_action_success'] ?? false);
            $params['dbm_notice'] = (int)($result['change_count'] ?? 0) > 0
                ? 'env_applied'
                : 'env_apply_noop';
            if ($runtimeAction === 'reload' && !$runtimeSuccess) {
                $params['dbm_error'] = mb_substr((string)($result['runtime_action_message'] ?? __('WLS reload was not completed.')), 0, 220);
            }
        } else {
            $params['dbm_error'] = mb_substr((string)($result['message'] ?? __('Database env apply failed.')), 0, 220);
        }

        $this->redirectToDbManager($params, '#env-apply');
        return '';
    }

    #[Acl('Weline_DbManager::wls_db_manager_env_rollback', 'Rollback WLS Database env', 'mdi mdi-file-restore-outline', 'Restore a Database Manager env.php backup')]
    public function postEnvRollback(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToDbManager(['dbm_error' => (string)__('Invalid request method.')], '#env-apply');
            return '';
        }

        $post = (array)$this->request->getPost();
        $params = $this->contextFromInput($post);
        $result = $this->envApplyService->rollbackEnvFromPanel($post);

        if (!empty($result['success'])) {
            $runtimeAction = (string)($result['runtime_action'] ?? 'none');
            $runtimeSuccess = (bool)($result['runtime_action_success'] ?? false);
            $params['dbm_notice'] = 'env_restored';
            if ($runtimeAction === 'reload' && !$runtimeSuccess) {
                $params['dbm_error'] = mb_substr((string)($result['runtime_action_message'] ?? __('WLS reload was not completed.')), 0, 220);
            }
        } else {
            $params['dbm_error'] = mb_substr((string)($result['message'] ?? __('Database env rollback failed.')), 0, 220);
        }

        $this->redirectToDbManager($params, '#env-apply');
        return '';
    }

    #[Acl('Weline_DbManager::wls_db_manager_test', 'Test WLS Database Connection', 'mdi mdi-database-check-outline', 'Run a guarded WLS Panel database connection test')]
    public function postTestConnection(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToDbManager(['dbm_error' => (string)__('Invalid request method.')], '#connection-test');
            return '';
        }

        $post = (array)$this->request->getPost();
        $context = $this->contextFromInput($post);
        $connectionKey = $this->safeConnectionKey((string)($post['connection_key'] ?? ''));
        $rawProfiles = $this->rawDatabaseProfiles();
        $params = $context;
        $params['connection_key'] = $connectionKey;

        if ($connectionKey === 'project_profile') {
            $profileConfig = $this->profileService->buildConnectionConfigForContext($context);
            if ($profileConfig === null) {
                $params['dbm_error'] = (string)__('No enabled project database profile was found.');
                $this->redirectToDbManager($params, '#connection-test');
                return '';
            }

            $result = $this->testConnection($profileConfig);
            $this->profileService->recordConnectionTest(
                $context,
                !empty($result['success']),
                (string)($result['message'] ?? '')
            );
            if (!empty($result['success'])) {
                $params['dbm_notice'] = 'connection_ok';
            } else {
                $params['dbm_error'] = mb_substr((string)($result['message'] ?? __('Database connection test failed.')), 0, 220);
            }

            $this->redirectToDbManager($params, '#connection-test');
            return '';
        }

        if ($connectionKey === '' || !isset($rawProfiles[$connectionKey])) {
            $params['dbm_error'] = (string)__('Database profile was not found.');
            $this->redirectToDbManager($params, '#connection-test');
            return '';
        }

        $result = $this->testConnection($rawProfiles[$connectionKey]);
        if (!empty($result['success'])) {
            $params['dbm_notice'] = 'connection_ok';
        } else {
            $params['dbm_error'] = mb_substr((string)($result['message'] ?? __('Database connection test failed.')), 0, 220);
        }

        $this->redirectToDbManager($params, '#connection-test');
        return '';
    }

    /**
     * @return array<string, string>
     */
    private function requestContext(): array
    {
        return [
            'operation' => trim((string)$this->request->getGet('operation', 'database-profile')),
            'profile_key' => trim((string)$this->request->getGet('profile_key', '')),
            'project_id' => trim((string)$this->request->getGet('project_id', '')),
            'domain' => trim((string)$this->request->getGet('domain', '')),
            'project_type' => trim((string)$this->request->getGet('project_type', '')),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    private function contextFromInput(array $input): array
    {
        return [
            'operation' => trim((string)($input['operation'] ?? 'database-profile')),
            'profile_key' => trim((string)($input['profile_key'] ?? '')),
            'project_id' => trim((string)($input['project_id'] ?? '')),
            'domain' => trim((string)($input['domain'] ?? '')),
            'project_type' => trim((string)($input['project_type'] ?? '')),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function databaseProfiles(?array $rawProfiles = null): array
    {
        $profiles = [];
        foreach (($rawProfiles ?? $this->rawDatabaseProfiles()) as $key => $config) {
            $profiles[$key] = $this->sanitizeProfile($key, $config);
        }

        return $profiles;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rawDatabaseProfiles(): array
    {
        $config = Env::getInstance()->getDbConfig();
        $profiles = [];

        if (is_array($config['master'] ?? null)) {
            $profiles['master'] = (array)$config['master'];
        } elseif ($config !== []) {
            $profiles['default'] = $config;
        }

        $slaves = is_array($config['slaves'] ?? null) ? (array)$config['slaves'] : [];
        $index = 1;
        foreach ($slaves as $key => $slaveConfig) {
            if (!is_array($slaveConfig)) {
                continue;
            }
            $profileKey = is_string($key) && trim($key) !== ''
                ? $this->safeConnectionKey($key)
                : 'slave_' . $index;
            if ($profileKey === '' || isset($profiles[$profileKey])) {
                $profileKey = 'slave_' . $index;
            }
            $profiles[$profileKey] = $slaveConfig + ['role' => 'slave'];
            $index++;
        }

        return $profiles;
    }

    /**
     * @param array<string, mixed> $profiles
     */
    private function selectedConnectionKey(array $profiles): string
    {
        $requested = $this->safeConnectionKey((string)$this->request->getGet('connection_key', ''));
        if ($requested !== '' && isset($profiles[$requested])) {
            return $requested;
        }

        $firstKey = array_key_first($profiles);
        return is_string($firstKey) ? $firstKey : '';
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function sanitizeProfile(string $key, array $config): array
    {
        $normalized = $this->normalizeConnectionConfig($config);
        $missing = $this->missingRequiredFields($normalized);
        $type = (string)$normalized['type'];
        $driverStatus = $this->driverStatus($type);

        return [
            'key' => $key,
            'label' => $key === 'default' ? (string)__('Default') : ucfirst(str_replace('_', ' ', $key)),
            'role' => (string)($config['role'] ?? ($key === 'master' || $key === 'default' ? 'master' : 'slave')),
            'type' => $type,
            'hostname' => (string)$normalized['hostname'],
            'hostport' => (string)$normalized['hostport'],
            'database' => (string)$normalized['database'],
            'path' => (string)$normalized['path'],
            'username' => $this->maskValue((string)$normalized['username']),
            'password_state' => (string)$normalized['password'] !== '' ? (string)__('Configured') : (string)__('Empty'),
            'prefix' => (string)$normalized['prefix'],
            'charset' => (string)$normalized['charset'],
            'collate' => (string)$normalized['collate'],
            'persistent' => !empty($normalized['persistent']),
            'pre_sql_state' => (string)$normalized['pre_sql'] !== '' ? (string)__('Configured') : (string)__('Empty'),
            'options_count' => is_array($config['options'] ?? null) ? count((array)$config['options']) : 0,
            'dsn_display' => $this->safeDsnDisplay($normalized),
            'missing_fields' => $missing,
            'status' => $missing === [] && $driverStatus['ready'] ? 'ready' : 'attention',
            'status_label' => $missing === [] && $driverStatus['ready'] ? (string)__('Ready') : (string)__('Needs attention'),
            'can_test' => $missing === [] && $driverStatus['ready'],
            'driver_status' => $driverStatus,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalizeConnectionConfig(array $config): array
    {
        $type = strtolower(trim((string)($config['type'] ?? $config['driver'] ?? '')));
        if ($type === '') {
            $type = trim((string)($config['path'] ?? '')) !== '' ? 'sqlite' : 'mysql';
        }

        return [
            'type' => $type,
            'hostname' => trim((string)($config['hostname'] ?? $config['host'] ?? '')),
            'hostport' => trim((string)($config['hostport'] ?? $config['port'] ?? $this->defaultPort($type))),
            'database' => trim((string)($config['database'] ?? $config['dbname'] ?? $config['name'] ?? '')),
            'path' => trim((string)($config['path'] ?? '')),
            'username' => trim((string)($config['username'] ?? $config['user'] ?? '')),
            'password' => (string)($config['password'] ?? ''),
            'prefix' => trim((string)($config['prefix'] ?? '')),
            'charset' => trim((string)($config['charset'] ?? ($type === 'mysql' ? 'utf8mb4' : ''))),
            'collate' => trim((string)($config['collate'] ?? '')),
            'persistent' => (bool)($config['persistent'] ?? false),
            'pre_sql' => trim((string)($config['pre_sql'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $normalized
     * @return array<int, string>
     */
    private function missingRequiredFields(array $normalized): array
    {
        $type = (string)$normalized['type'];
        if ($type === 'sqlite') {
            return trim((string)$normalized['path']) === '' ? ['path'] : [];
        }

        $missing = [];
        foreach (['hostname', 'database', 'username'] as $field) {
            if (trim((string)($normalized[$field] ?? '')) === '') {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * @return array{ready: bool, message: string, extension: string}
     */
    private function driverStatus(string $type): array
    {
        $extension = match ($type) {
            'mysql' => 'pdo_mysql',
            'pgsql' => 'pdo_pgsql',
            'sqlite' => 'pdo_sqlite',
            default => 'pdo',
        };

        $ready = extension_loaded('pdo') && extension_loaded($extension);
        if ($type !== 'mysql' && $type !== 'pgsql' && $type !== 'sqlite') {
            $ready = false;
        }

        return [
            'ready' => $ready,
            'message' => $ready ? (string)__('Driver available') : (string)__('PDO driver is not available'),
            'extension' => $extension,
        ];
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function safeDsnDisplay(array $normalized): string
    {
        $type = (string)$normalized['type'];
        if ($type === 'sqlite') {
            return 'sqlite:' . (string)$normalized['path'];
        }

        $host = (string)$normalized['hostname'];
        $port = (string)$normalized['hostport'];
        $database = (string)$normalized['database'];
        $username = $this->maskValue((string)$normalized['username']);
        $address = $host . ($port !== '' ? ':' . $port : '');

        return $type . '://' . $username . '@' . $address . '/' . $database;
    }

    private function maskValue(string $value): string
    {
        $value = trim($value);
        $length = strlen($value);
        if ($value === '') {
            return '';
        }
        if ($length <= 2) {
            return str_repeat('*', $length);
        }

        return substr($value, 0, 1) . str_repeat('*', max(1, $length - 2)) . substr($value, -1);
    }

    private function defaultPort(string $type): string
    {
        return match ($type) {
            'mysql' => '3306',
            'pgsql' => '5432',
            default => '',
        };
    }

    /**
     * @return array{label: string, note: string}
     */
    private function databaseConfigSource(): array
    {
        $env = Env::getInstance();
        $rawDb = (array)$env->getConfig('db', []);
        $sandboxDb = (array)$env->getConfig('sandbox_db', []);

        if ($sandboxDb !== []) {
            return [
                'label' => 'sandbox_db',
                'note' => (string)__('Sandbox database config is available and may be used by the current runtime.'),
            ];
        }

        if ($rawDb !== []) {
            return [
                'label' => 'db',
                'note' => (string)__('Configuration is read from app/etc/env.php.'),
            ];
        }

        return [
            'label' => 'fallback',
            'note' => (string)__('No explicit database config was found; the framework fallback profile is shown.'),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $profiles
     * @return array<int, array<string, string>>
     */
    private function capabilityCards(array $profiles, array $projectProfile = []): array
    {
        $readyCount = 0;
        foreach ($profiles as $profile) {
            if (($profile['status'] ?? '') === 'ready') {
                $readyCount++;
            }
        }

        return [
            [
                'label' => (string)__('Profiles'),
                'value' => (string)count($profiles),
                'tone' => 'primary',
            ],
            [
                'label' => (string)__('Ready'),
                'value' => (string)$readyCount,
                'tone' => 'success',
            ],
            [
                'label' => (string)__('Guard'),
                'value' => !empty($projectProfile['has_profile']) ? (string)__('Writable') : (string)__('Guarded'),
                'tone' => 'neutral',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{success: bool, message: string}
     */
    private function testConnection(array $config): array
    {
        $normalized = $this->normalizeConnectionConfig($config);
        $missing = $this->missingRequiredFields($normalized);
        if ($missing !== []) {
            return [
                'success' => false,
                'message' => (string)__('Database profile is incomplete: %{1}', [implode(', ', $missing)]),
            ];
        }

        $driverStatus = $this->driverStatus((string)$normalized['type']);
        if (!$driverStatus['ready']) {
            return [
                'success' => false,
                'message' => (string)__('%{1} is not available for this runtime.', [$driverStatus['extension']]),
            ];
        }

        try {
            $pdo = $this->openPdo($normalized);
            $statement = $pdo->query('SELECT 1');
            if ($statement === false) {
                return [
                    'success' => false,
                    'message' => (string)__('Database connection test failed.'),
                ];
            }
            $statement->fetchColumn();
            $pdo = null;

            return [
                'success' => true,
                'message' => (string)__('Database connection test passed.'),
            ];
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'message' => $this->sanitizeConnectionError($throwable->getMessage(), $normalized),
            ];
        }
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function openPdo(array $normalized): \PDO
    {
        $type = (string)$normalized['type'];
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT => 3,
        ];

        if ($type === 'sqlite') {
            return new \PDO('sqlite:' . (string)$normalized['path'], null, null, $options);
        }

        if ($type === 'pgsql') {
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                (string)$normalized['hostname'],
                (string)$normalized['hostport'],
                (string)$normalized['database']
            );
            return new \PDO($dsn, (string)$normalized['username'], (string)$normalized['password'], $options);
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            (string)$normalized['hostname'],
            (string)$normalized['hostport'],
            (string)$normalized['database'],
            (string)$normalized['charset']
        );

        return new \PDO($dsn, (string)$normalized['username'], (string)$normalized['password'], $options);
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function sanitizeConnectionError(string $message, array $normalized): string
    {
        $message = trim($message);
        foreach (['password', 'username'] as $field) {
            $value = (string)($normalized[$field] ?? '');
            if ($value !== '') {
                $message = str_replace($value, '[' . $field . ']', $message);
            }
        }

        if ($message === '') {
            $message = (string)__('Database connection test failed.');
        }

        return mb_substr($message, 0, 220);
    }

    private function safeConnectionKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9_.-]+/', '_', $key) ?? '';
        return trim($key, '_.-');
    }

    private function resolveNotice(string $code): string
    {
        return match ($code) {
            'connection_ok' => (string)__('Database connection test passed.'),
            'profile_saved' => (string)__('Project database profile saved.'),
            'profile_saved_reload_requested' => (string)__('Project database profile saved and WLS reload was requested.'),
            'env_applied' => (string)__('Database env applied and backup created.'),
            'env_apply_noop' => (string)__('No database env changes were needed.'),
            'env_restored' => (string)__('Database env restored from backup.'),
            default => '',
        };
    }

    /**
     * @param array<string, string> $params
     */
    private function redirectToDbManager(array $params, string $fragment = ''): void
    {
        $cleanParams = [];
        foreach ($params as $key => $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $cleanParams[$key] = $value;
            }
        }

        $url = $this->_url->getBackendUrl('weline_dbmanager/backend/wls-db-manager', $cleanParams);
        if ($fragment !== '') {
            $url .= $fragment;
        }
        $this->redirect($url);
    }

    private function useStandaloneLayout(): void
    {
        $this->layoutType = 'fullscreen.default';

        $meta = $this->getTemplate()->getData('meta');
        $meta = is_array($meta) ? $meta : [];
        $meta['showHeader'] = false;
        $meta['showSidebar'] = false;
        $meta['showFooter'] = false;
        $meta['showRightSidebar'] = false;
        $meta['showPageHeader'] = false;
        $meta['showMessages'] = false;
        $meta['class'] = trim((string)($meta['class'] ?? '') . ' wls-db-manager-fullscreen');

        $this->assign('meta', $meta);
        $this->assign('layoutShowPageHeader', false);
        $this->assign('layoutShowMessages', false);
    }
}
