<?php
declare(strict_types=1);

namespace Weline\DbManager\Controller\Backend;

use Weline\DbManager\Service\WlsDatabaseBackupPlanService;
use Weline\DbManager\Service\WlsDatabaseBackupExecutionService;
use Weline\DbManager\Service\WlsDatabaseConnectionProbeService;
use Weline\DbManager\Service\WlsDatabaseMigrationExecutionService;
use Weline\DbManager\Service\WlsDatabaseMigrationPreflightService;
use Weline\DbManager\Service\WlsDatabaseProjectHealthService;
use Weline\DbManager\Service\WlsDatabaseRestoreExecutionService;
use Weline\DbManager\Service\WlsDatabaseRestorePreflightService;
use Weline\DbManager\Service\WlsDatabaseSqlApplyExecutionService;
use Weline\DbManager\Service\WlsDatabaseEnvApplyService;
use Weline\DbManager\Service\WlsDatabaseLifecycleExecutionService;
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
        private readonly WlsDatabaseEnvApplyService $envApplyService,
        private readonly WlsDatabaseConnectionProbeService $connectionProbeService
    ) {
    }

    #[Acl('Weline_DbManager::wls_db_manager_index', 'View WLS Database Manager', 'mdi mdi-database-search-outline', 'View WLS Panel database profiles')]
    public function getIndex(): string
    {
        $this->useStandaloneLayout();

        $context = $this->requestContext();
        $pageKey = $this->currentPageKey((string)($context['operation'] ?? ''));
        $rawProfiles = $this->rawDatabaseProfiles();
        $profiles = $this->databaseProfiles($rawProfiles);
        $selectedKey = $this->selectedConnectionKey($profiles);
        $requestedConnectionKey = $this->safeConnectionKey((string)$this->request->getGet('connection_key', ''));
        $selectedProfile = is_array($profiles[$selectedKey] ?? null) ? $profiles[$selectedKey] : [];
        $projectProfile = $this->profileService->getFormData($context, $rawProfiles, $selectedKey);
        $envPlan = $this->envApplyService->getEnvPlan($context);
        $slavePlan = $this->envApplyService->getSlavePlan($context);
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
        $restoreRollbackPlan = (new WlsDatabaseRestoreExecutionService($this->profileService))->getRollbackPlan(
            $context,
            $projectProfile
        );
        $healthPlan = (new WlsDatabaseProjectHealthService())->buildPlan(
            $context,
            array_values($profiles),
            $selectedKey,
            $projectProfile,
            $envPlan,
            $slavePlan,
            $backupPlan
        );

        $this->assign('title', __('WLS Database Manager'));
        $this->assign('page_title', __('WLS Database Manager'));
        $this->assign('wlsDbManagerContext', $context);
        $this->assign('wlsDbManagerPageKey', $pageKey);
        $this->assign('wlsDbManagerPageUrls', $this->pageUrls($context, $requestedConnectionKey));
        $this->assign('wlsDbManagerProfiles', array_values($profiles));
        $this->assign('wlsDbManagerSelectedKey', $selectedKey);
        $this->assign('wlsDbManagerRequestedConnectionKey', $requestedConnectionKey);
        $this->assign('wlsDbManagerProjectProfile', $projectProfile);
        $this->assign('wlsDbManagerEnvPlan', $envPlan);
        $this->assign('wlsDbManagerSlavePlan', $slavePlan);
        $this->assign('wlsDbManagerLifecyclePlan', $lifecyclePlan);
        $this->assign('wlsDbManagerBackupPlan', $backupPlan);
        $this->assign('wlsDbManagerRestoreRollbackPlan', $restoreRollbackPlan);
        $this->assign('wlsDbManagerHealthPlan', $healthPlan);
        $this->assign('wlsDbManagerAuditRecords', $this->profileService->getRecentAuditRecords());
        $this->assign('wlsDbManagerSource', $this->databaseConfigSource());
        $this->assign('wlsDbManagerCapabilities', $this->capabilityCards($profiles, $projectProfile));
        $this->assign('wlsDbManagerNotice', $this->resolveNotice((string)$this->request->getGet('dbm_notice', '')));
        $this->assign('wlsDbManagerError', mb_substr(trim((string)$this->request->getGet('dbm_error', '')), 0, 220));
        $this->assign('wlsDbManagerEmbedded', $this->isEmbeddedPanelRequest());
        $embeddedParams = $this->embeddedUrlParams();
        $this->assign('wlsDbManagerTestConnectionUrl', $this->_url->getBackendUrl('weline_dbmanager/backend/wls-db-manager/test-connection', $embeddedParams));
        $this->assign('wlsDbManagerHealthProbeUrl', $this->_url->getBackendUrl('weline_dbmanager/backend/wls-db-manager/health-probe', $embeddedParams));
        $this->assign('wlsDbManagerProfileSaveUrl', $this->_url->getBackendUrl('weline_dbmanager/backend/wls-db-manager/profile-save', $embeddedParams));
        $this->assign('wlsDbManagerEnvApplyUrl', $this->_url->getBackendUrl('weline_dbmanager/backend/wls-db-manager/env-apply', $embeddedParams));
        $this->assign('wlsDbManagerEnvRollbackUrl', $this->_url->getBackendUrl('weline_dbmanager/backend/wls-db-manager/env-rollback', $embeddedParams));
        $this->assign('wlsDbManagerSlaveCreateUrl', $this->_url->getBackendUrl('weline_dbmanager/backend/wls-db-manager/slave-create', $embeddedParams));
        $this->assign('wlsDbManagerSlaveRemoveUrl', $this->_url->getBackendUrl('weline_dbmanager/backend/wls-db-manager/slave-remove', $embeddedParams));
        $this->assign('wlsDbManagerLifecycleExecuteUrl', $this->_url->getBackendUrl('weline_dbmanager/backend/wls-db-manager/lifecycle-execute', $embeddedParams));
        $this->assign('wlsDbManagerBackupExecuteUrl', $this->_url->getBackendUrl('weline_dbmanager/backend/wls-db-manager/backup-execute', $embeddedParams));

        return $this->fetch('index');
    }

    #[Acl('Weline_DbManager::wls_db_manager_summary', 'View WLS Database Summary', 'mdi mdi-view-dashboard-outline', 'View WLS Panel database summary')]
    public function getSummary(): string
    {
        return $this->openPage('summary');
    }

    #[Acl('Weline_DbManager::wls_db_manager_profiles', 'View WLS Database Profiles', 'mdi mdi-database-search-outline', 'View WLS Panel database profiles')]
    public function getProfiles(): string
    {
        return $this->openPage('profiles');
    }

    #[Acl('Weline_DbManager::wls_db_manager_health', 'View WLS Database Health', 'mdi mdi-heart-pulse', 'View WLS Panel database health')]
    public function getHealth(): string
    {
        return $this->openPage('health');
    }

    #[Acl('Weline_DbManager::wls_db_manager_project_profile', 'View WLS Database Project Profile', 'mdi mdi-database-edit-outline', 'View WLS Panel project database profile')]
    public function getProjectProfile(): string
    {
        return $this->openPage('project-profile');
    }

    #[Acl('Weline_DbManager::wls_db_manager_lifecycle', 'View WLS Database Lifecycle', 'mdi mdi-database-plus-outline', 'View WLS Panel database lifecycle')]
    public function getLifecycle(): string
    {
        return $this->openPage('lifecycle');
    }

    #[Acl('Weline_DbManager::wls_db_manager_backup_plan', 'View WLS Database Backup Plan', 'mdi mdi-database-arrow-down-outline', 'View WLS Panel database backup plan')]
    public function getBackupPlan(): string
    {
        return $this->openPage('backup-plan');
    }

    #[Acl('Weline_DbManager::wls_db_manager_env_page', 'View WLS Database Env Apply', 'mdi mdi-file-cog-outline', 'View WLS Panel database env apply')]
    public function getEnvPage(): string
    {
        return $this->openPage('env-apply');
    }

    #[Acl('Weline_DbManager::wls_db_manager_slaves', 'View WLS Database Slaves', 'mdi mdi-database-cog-outline', 'View WLS Panel database slaves')]
    public function getSlaves(): string
    {
        return $this->openPage('slaves');
    }

    #[Acl('Weline_DbManager::wls_db_manager_test_page', 'View WLS Database Test', 'mdi mdi-database-check-outline', 'View WLS Panel database connection test')]
    public function getTestPage(): string
    {
        return $this->openPage('test');
    }

    private function openPage(string $pageKey): string
    {
        $pageKey = $this->normalizePageKey($pageKey);
        $this->request->setGet('page_key', $pageKey);
        $this->request->setGet('operation', $this->operationForPageKey($pageKey));
        return $this->getIndex();
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
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    private function lifecyclePlanInputFromArray(array $input): array
    {
        return [
            'lifecycle_action' => trim((string)($input['lifecycle_action'] ?? '')),
            'lifecycle_database' => trim((string)($input['lifecycle_database'] ?? '')),
            'lifecycle_username' => trim((string)($input['lifecycle_username'] ?? '')),
            'lifecycle_host' => trim((string)($input['lifecycle_host'] ?? '')),
            'lifecycle_grant_mode' => trim((string)($input['lifecycle_grant_mode'] ?? 'read_write')),
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

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    private function backupPlanInputFromArray(array $input): array
    {
        return [
            'backup_action' => trim((string)($input['backup_action'] ?? '')),
            'backup_scope' => trim((string)($input['backup_scope'] ?? 'schema_and_data')),
            'backup_artifact' => trim((string)($input['backup_artifact'] ?? '')),
            'migration_target' => trim((string)($input['migration_target'] ?? '')),
        ];
    }

    #[Acl('Weline_DbManager::wls_db_manager_lifecycle_execute', 'Execute WLS Database Lifecycle SQL', 'mdi mdi-database-arrow-up-outline', 'Execute guarded WLS Panel database lifecycle SQL')]
    public function postLifecycleExecute(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToDbManager(['dbm_error' => (string)__('Invalid request method.')], '#lifecycle');
            return '';
        }

        $post = (array)$this->request->getPost();
        $context = $this->contextFromInput($post);
        $lifecycleInput = $this->lifecyclePlanInputFromArray($post);
        $params = $context + $lifecycleInput;
        $connectionKey = $this->safeConnectionKey((string)($post['connection_key'] ?? ''));
        $params['connection_key'] = $connectionKey;
        $rawProfiles = $this->rawDatabaseProfiles();
        if ($connectionKey === '' || !isset($rawProfiles[$connectionKey]) || !is_array($rawProfiles[$connectionKey])) {
            $params['dbm_error'] = (string)__('Source DBA connection profile was not found.');
            $this->redirectToDbManager($params, '#lifecycle');
            return '';
        }

        $sourceProfile = (array)$rawProfiles[$connectionKey];
        $selectedProfile = $this->sanitizeProfile($connectionKey, $sourceProfile);
        $projectProfile = $this->profileService->getFormData($context, $rawProfiles, $connectionKey);
        $lifecyclePlan = (new WlsDatabaseLifecyclePlanService())->buildPlan(
            $lifecycleInput,
            $projectProfile,
            $selectedProfile
        );
        $result = (new WlsDatabaseLifecycleExecutionService($this->profileService))->executeFromPanel(
            $post,
            $context,
            $lifecyclePlan,
            $projectProfile,
            $sourceProfile
        );

        if (!empty($result['success'])) {
            $params['dbm_notice'] = 'lifecycle_executed';
        } else {
            $params['dbm_error'] = mb_substr((string)($result['message'] ?? __('Lifecycle SQL execution failed.')), 0, 220);
        }

        $this->redirectToDbManager($params, '#lifecycle');
        return '';
    }

    #[Acl('Weline_DbManager::wls_db_manager_backup_execute', 'Execute WLS Database Backup', 'mdi mdi-database-export-outline', 'Execute guarded WLS Panel database backup')]
    public function postBackupExecute(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToDbManager(['dbm_error' => (string)__('Invalid request method.')], '#backup-plan');
            return '';
        }

        $post = (array)$this->request->getPost();
        $context = $this->contextFromInput($post);
        $backupInput = $this->backupPlanInputFromArray($post);
        $params = $context + $backupInput;
        $connectionKey = $this->safeConnectionKey((string)($post['connection_key'] ?? ''));
        $params['connection_key'] = $connectionKey;
        $rawProfiles = $this->rawDatabaseProfiles();
        if ($connectionKey === '' || !isset($rawProfiles[$connectionKey]) || !is_array($rawProfiles[$connectionKey])) {
            $params['dbm_error'] = (string)__('Source database connection profile was not found.');
            $this->redirectToDbManager($params, '#backup-plan');
            return '';
        }

        $sourceProfile = (array)$rawProfiles[$connectionKey];
        $selectedProfile = $this->sanitizeProfile($connectionKey, $sourceProfile);
        $projectProfile = $this->profileService->getFormData($context, $rawProfiles, $connectionKey);
        if (($backupInput['backup_action'] ?? '') === 'restore_rollback') {
            $result = (new WlsDatabaseRestoreExecutionService($this->profileService))->rollbackFromPanel(
                $post,
                $context,
                $projectProfile,
                $sourceProfile
            );
            $params['backup_action'] = 'restore_database';
            $params['backup_artifact'] = trim((string)($post['rollback_artifact'] ?? $post['backup_artifact'] ?? ''));

            if (!empty($result['success'])) {
                $params['dbm_notice'] = 'restore_rollback_executed';
            } else {
                $params['dbm_error'] = mb_substr((string)($result['message'] ?? __('Restore rollback failed.')), 0, 220);
            }

            $this->redirectToDbManager($params, '#backup-plan');
            return '';
        }
        $backupPlan = (new WlsDatabaseBackupPlanService())->buildPlan(
            $backupInput,
            $projectProfile,
            $selectedProfile
        );
        if (($backupInput['backup_action'] ?? '') === 'restore_database') {
            if ((string)($post['confirm_restore_execute'] ?? '0') === '1') {
                $result = (new WlsDatabaseRestoreExecutionService($this->profileService))->executeFromPanel(
                    $post,
                    $context,
                    $backupPlan,
                    $projectProfile,
                    $sourceProfile
                );
            } else {
                $result = (new WlsDatabaseRestorePreflightService($this->profileService))->preflightFromPanel(
                    $post,
                    $context,
                    $backupPlan,
                    $projectProfile,
                    $sourceProfile
                );
            }

            if (!empty($result['success'])) {
                $params['dbm_notice'] = (string)($post['confirm_restore_execute'] ?? '0') === '1'
                    ? 'restore_executed'
                    : 'restore_preflight_passed';
            } else {
                $params['dbm_error'] = mb_substr((string)($result['message'] ?? __('Restore action failed.')), 0, 220);
            }

            $this->redirectToDbManager($params, '#backup-plan');
            return '';
        }
        if (($backupInput['backup_action'] ?? '') === 'migration_dry_run') {
            if ((string)($post['confirm_migration_execute'] ?? '0') === '1') {
                $result = (new WlsDatabaseMigrationExecutionService($this->profileService))->executeFromPanel(
                    $post,
                    $context,
                    $backupPlan,
                    $projectProfile,
                    $sourceProfile
                );
            } else {
                $result = (new WlsDatabaseMigrationPreflightService($this->profileService))->preflightFromPanel(
                    $post,
                    $context,
                    $backupPlan,
                    $projectProfile,
                    $sourceProfile
                );
            }

            if (!empty($result['success'])) {
                $params['dbm_notice'] = (string)($post['confirm_migration_execute'] ?? '0') === '1'
                    ? 'migration_executed'
                    : 'migration_preflight_passed';
            } else {
                $params['dbm_error'] = mb_substr((string)($result['message'] ?? __('Migration action failed.')), 0, 220);
            }

            $this->redirectToDbManager($params, '#backup-plan');
            return '';
        }
        if (($backupInput['backup_action'] ?? '') === 'sql_apply') {
            $result = (new WlsDatabaseSqlApplyExecutionService($this->profileService))->executeFromPanel(
                $post,
                $context,
                $backupPlan,
                $projectProfile,
                $sourceProfile
            );

            if (!empty($result['success'])) {
                $params['dbm_notice'] = 'sql_apply_executed';
            } else {
                $params['dbm_error'] = mb_substr((string)($result['message'] ?? __('SQL apply execution failed.')), 0, 220);
            }

            $this->redirectToDbManager($params, '#backup-plan');
            return '';
        }

        $result = (new WlsDatabaseBackupExecutionService($this->profileService))->executeFromPanel(
            $post,
            $context,
            $backupPlan,
            $projectProfile,
            $sourceProfile
        );

        if (!empty($result['success'])) {
            $params['dbm_notice'] = 'backup_executed';
        } else {
            $params['dbm_error'] = mb_substr((string)($result['message'] ?? __('Database backup execution failed.')), 0, 220);
        }

        $this->redirectToDbManager($params, '#backup-plan');
        return '';
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

    #[Acl('Weline_DbManager::wls_db_manager_slave_create', 'Create WLS Database Slave', 'mdi mdi-database-plus-outline', 'Create a guarded WLS Panel database slave env profile')]
    public function postSlaveCreate(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToDbManager(['dbm_error' => (string)__('Invalid request method.')], '#slave-management');
            return '';
        }

        $post = (array)$this->request->getPost();
        $params = $this->contextFromInput($post);
        $params['slave_key'] = $this->safeConnectionKey((string)($post['slave_key'] ?? ''));
        $result = $this->envApplyService->createSlaveFromPanel($post);

        if (!empty($result['success'])) {
            $runtimeAction = (string)($result['runtime_action'] ?? 'none');
            $runtimeSuccess = (bool)($result['runtime_action_success'] ?? false);
            $params['dbm_notice'] = 'env_slave_created';
            if ($runtimeAction === 'reload' && !$runtimeSuccess) {
                $params['dbm_error'] = mb_substr((string)($result['runtime_action_message'] ?? __('WLS reload was not completed.')), 0, 220);
            }
        } else {
            $params['dbm_error'] = mb_substr((string)($result['message'] ?? __('Database slave create failed.')), 0, 220);
        }

        $this->redirectToDbManager($params, '#slave-management');
        return '';
    }

    #[Acl('Weline_DbManager::wls_db_manager_slave_remove', 'Remove WLS Database Slave', 'mdi mdi-database-minus-outline', 'Remove a guarded WLS Panel database slave env profile')]
    public function postSlaveRemove(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToDbManager(['dbm_error' => (string)__('Invalid request method.')], '#slave-management');
            return '';
        }

        $post = (array)$this->request->getPost();
        $params = $this->contextFromInput($post);
        $params['slave_key'] = $this->safeConnectionKey((string)($post['slave_key'] ?? ''));
        $result = $this->envApplyService->removeSlaveFromPanel($post);

        if (!empty($result['success'])) {
            $runtimeAction = (string)($result['runtime_action'] ?? 'none');
            $runtimeSuccess = (bool)($result['runtime_action_success'] ?? false);
            $params['dbm_notice'] = 'env_slave_removed';
            if ($runtimeAction === 'reload' && !$runtimeSuccess) {
                $params['dbm_error'] = mb_substr((string)($result['runtime_action_message'] ?? __('WLS reload was not completed.')), 0, 220);
            }
        } else {
            $params['dbm_error'] = mb_substr((string)($result['message'] ?? __('Database slave remove failed.')), 0, 220);
        }

        $this->redirectToDbManager($params, '#slave-management');
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

            $result = $this->connectionProbeService->probe($profileConfig);
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

        $result = $this->connectionProbeService->probe($rawProfiles[$connectionKey]);
        if (!empty($result['success'])) {
            $params['dbm_notice'] = 'connection_ok';
        } else {
            $params['dbm_error'] = mb_substr((string)($result['message'] ?? __('Database connection test failed.')), 0, 220);
        }

        $this->redirectToDbManager($params, '#connection-test');
        return '';
    }

    #[Acl('Weline_DbManager::wls_db_manager_health_probe', 'Run WLS Database Health Probe', 'mdi mdi-heart-pulse', 'Run a guarded read-only WLS Panel database health probe')]
    public function postHealthProbe(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToDbManager(['dbm_error' => (string)__('Invalid request method.')], '#project-health');
            return '';
        }

        $post = (array)$this->request->getPost();
        $context = $this->contextFromInput($post);
        $connectionKey = $this->safeConnectionKey((string)($post['connection_key'] ?? ''));
        $params = $context;
        $params['connection_key'] = $connectionKey;

        if ((string)($post['confirm_health_probe'] ?? '0') !== '1'
            || \trim((string)($post['health_probe_phrase'] ?? '')) !== WlsDatabaseConnectionProbeService::HEALTH_PROBE_PHRASE
        ) {
            $params['dbm_error'] = (string)__('Confirm the database health probe with CHECK_DB_HEALTH before submitting.');
            $this->redirectToDbManager($params, '#project-health');
            return '';
        }

        $rawProfiles = $this->rawDatabaseProfiles();
        if ($connectionKey === 'project_profile') {
            $profileConfig = $this->profileService->buildConnectionConfigForContext($context);
            if ($profileConfig === null) {
                $params['dbm_error'] = (string)__('No enabled project database profile was found.');
                $this->redirectToDbManager($params, '#project-health');
                return '';
            }
            $result = $this->connectionProbeService->probe($profileConfig);
        } elseif ($connectionKey !== '' && isset($rawProfiles[$connectionKey]) && \is_array($rawProfiles[$connectionKey])) {
            $result = $this->connectionProbeService->probe((array)$rawProfiles[$connectionKey]);
        } else {
            $params['dbm_error'] = (string)__('Database profile was not found.');
            $this->redirectToDbManager($params, '#project-health');
            return '';
        }

        $this->profileService->appendAuditEvent('health_probe', [
            'success' => !empty($result['success']),
            'profile_key' => (string)($context['profile_key'] ?? ''),
            'connection_key' => $connectionKey,
            'driver' => (string)($result['driver'] ?? ''),
            'duration_ms' => (int)($result['duration_ms'] ?? 0),
            'message' => \mb_substr((string)($result['message'] ?? ''), 0, 180),
        ]);

        if (!empty($result['success'])) {
            $params['dbm_notice'] = 'health_probe_passed';
        } else {
            $params['dbm_error'] = \mb_substr((string)($result['message'] ?? __('Database health probe failed.')), 0, 220);
        }

        $this->redirectToDbManager($params, '#project-health');
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

    private function currentPageKey(string $operation): string
    {
        $explicit = trim((string)$this->request->getGet('page_key', ''));
        if ($explicit !== '') {
            return $this->normalizePageKey($explicit);
        }

        return $this->pageKeyFromOperation($operation);
    }

    private function normalizePageKey(string $pageKey): string
    {
        $pageKey = strtolower(trim($pageKey));
        return match ($pageKey) {
            'summary' => 'summary',
            'profiles', 'database-profile' => 'profiles',
            'health', 'database-health', 'project-health' => 'health',
            'project-profile' => 'project-profile',
            'lifecycle' => 'lifecycle',
            'backup-plan' => 'backup-plan',
            'env-apply', 'env-page' => 'env-apply',
            'slaves', 'slave-management' => 'slaves',
            'test', 'test-page', 'connection-test' => 'test',
            default => 'profiles',
        };
    }

    private function pageKeyFromOperation(string $operation): string
    {
        return $this->normalizePageKey($operation);
    }

    private function operationForPageKey(string $pageKey): string
    {
        return match ($this->normalizePageKey($pageKey)) {
            'summary' => 'summary',
            'profiles' => 'database-profile',
            'health' => 'database-health',
            'project-profile' => 'project-profile',
            'lifecycle' => 'lifecycle',
            'backup-plan' => 'backup-plan',
            'env-apply' => 'env-apply',
            'slaves' => 'slave-management',
            'test' => 'connection-test',
            default => 'database-profile',
        };
    }

    private function routeForPageKey(string $pageKey): string
    {
        return match ($this->normalizePageKey($pageKey)) {
            'summary' => 'weline_dbmanager/backend/wls-db-manager/summary',
            'profiles' => 'weline_dbmanager/backend/wls-db-manager/profiles',
            'health' => 'weline_dbmanager/backend/wls-db-manager/health',
            'project-profile' => 'weline_dbmanager/backend/wls-db-manager/project-profile',
            'lifecycle' => 'weline_dbmanager/backend/wls-db-manager/lifecycle',
            'backup-plan' => 'weline_dbmanager/backend/wls-db-manager/backup-plan',
            'env-apply' => 'weline_dbmanager/backend/wls-db-manager/env-page',
            'slaves' => 'weline_dbmanager/backend/wls-db-manager/slaves',
            'test' => 'weline_dbmanager/backend/wls-db-manager/test-page',
            default => 'weline_dbmanager/backend/wls-db-manager/profiles',
        };
    }

    /**
     * @param array<string, string> $context
     * @return array<string, string>
     */
    private function routeParamsForPage(array $context, string $pageKey, string $connectionKey = ''): array
    {
        $params = [
            'operation' => $this->operationForPageKey($pageKey),
            'profile_key' => (string)($context['profile_key'] ?? ''),
            'project_id' => (string)($context['project_id'] ?? ''),
            'domain' => (string)($context['domain'] ?? ''),
            'project_type' => (string)($context['project_type'] ?? ''),
            'connection_key' => $connectionKey,
            'embedded' => $this->isEmbeddedPanelRequest() ? '1' : '',
        ];

        return $this->cleanUrlParams($params);
    }

    /**
     * @param array<string, string> $context
     * @return array<string, string>
     */
    private function pageUrls(array $context, string $connectionKey = ''): array
    {
        $urls = [];
        foreach (['summary', 'profiles', 'health', 'project-profile', 'lifecycle', 'backup-plan', 'env-apply', 'slaves', 'test'] as $pageKey) {
            $urls[$pageKey] = $this->_url->getBackendUrl(
                $this->routeForPageKey($pageKey),
                $this->routeParamsForPage($context, $pageKey, $connectionKey)
            );
        }

        return $urls;
    }

    private function isEmbeddedPanelRequest(): bool
    {
        $value = strtolower(trim((string)$this->request->getGet('embedded', '')));
        return in_array($value, ['1', 'true', 'yes', 'wls_panel'], true);
    }

    /**
     * @return array<string, string>
     */
    private function embeddedUrlParams(): array
    {
        return $this->isEmbeddedPanelRequest() ? ['embedded' => '1'] : [];
    }

    private function shouldKeepEmbeddedMode(): bool
    {
        if ($this->isEmbeddedPanelRequest()) {
            return true;
        }

        $post = $this->request->isPost() ? (array)$this->request->getPost() : [];
        $value = strtolower(trim((string)($post['embedded'] ?? '')));
        return in_array($value, ['1', 'true', 'yes', 'wls_panel'], true);
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
            'env_slave_created' => (string)__('Database slave profile created and env backup created.'),
            'env_slave_removed' => (string)__('Database slave profile removed and env backup created.'),
            'lifecycle_executed' => (string)__('Lifecycle SQL executed successfully.'),
            'backup_executed' => (string)__('Database backup completed successfully.'),
            'restore_preflight_passed' => (string)__('Restore preflight passed.'),
            'restore_executed' => (string)__('Database restore completed successfully.'),
            'restore_rollback_executed' => (string)__('Database restore rollback completed successfully.'),
            'migration_preflight_passed' => (string)__('Migration preflight passed; guarded MySQL migration import requires separate RUN_DB_MIGRATION confirmation.'),
            'migration_executed' => (string)__('Database migration import completed successfully after pre-migration backup.'),
            'sql_apply_executed' => (string)__('SQL apply completed successfully after pre-apply backup.'),
            'health_probe_passed' => (string)__('Database health probe passed.'),
            default => '',
        };
    }

    /**
     * @param array<string, string> $params
     */
    private function redirectToDbManager(array $params, string $targetPage = ''): void
    {
        $pageKey = $this->normalizePageKey($targetPage !== '' ? ltrim($targetPage, '#') : (string)($params['operation'] ?? 'database-profile'));
        $cleanParams = $this->cleanUrlParams($params);
        $cleanParams['operation'] = $this->operationForPageKey($pageKey);
        if ($this->shouldKeepEmbeddedMode()) {
            $cleanParams['embedded'] = '1';
        }

        $url = $this->_url->getBackendUrl($this->routeForPageKey($pageKey), $cleanParams);
        $this->redirect($url);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, string>
     */
    private function cleanUrlParams(array $params): array
    {
        $cleanParams = [];
        foreach ($params as $key => $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $cleanParams[$key] = $value;
            }
        }

        return $cleanParams;
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
