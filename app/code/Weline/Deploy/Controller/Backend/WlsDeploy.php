<?php

declare(strict_types=1);

namespace Weline\Deploy\Controller\Backend;

use Weline\Deploy\Model\DeployRelease;
use Weline\Deploy\Service\DeployConfigService;
use Weline\Deploy\Service\DeployOrchestratorService;
use Weline\Deploy\Service\DeployProjectCommandPolicyService;
use Weline\Deploy\Service\DeployProjectProfileService;
use Weline\Deploy\Service\DeployReleaseHistoryService;
use Weline\Deploy\Service\DeployReleaseRuntimeService;
use Weline\Deploy\Service\DeployWebhookRefResolver;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;

#[Acl('Weline_Deploy::wls_deploy', 'WLS 部署发布', 'mdi mdi-rocket-launch-outline', 'WLS 面板部署发布入口', 'Weline_Backend::system_maintenance')]
class WlsDeploy extends BackendController
{
    public function __construct(
        private readonly DeployConfigService $deployConfigService,
        private readonly DeployProjectCommandPolicyService $commandPolicyService,
        private readonly DeployProjectProfileService $profileService,
        private readonly DeployReleaseHistoryService $historyService,
        private readonly DeployOrchestratorService $orchestrator,
        private readonly DeployReleaseRuntimeService $runtimeService,
        private readonly DeployWebhookRefResolver $webhookRefResolver,
    ) {
    }

    #[Acl('Weline_Deploy::wls_deploy_index', '查看 WLS 部署发布', 'mdi mdi-rocket-launch', '查看 WLS 面板部署发布')]
    public function getIndex(): string
    {
        $this->useStandaloneLayout();

        $pageKey = $this->normalizePageKey(
            trim((string)$this->request->getGet('page_key', '')),
            trim((string)$this->request->getGet('operation', ''))
        );
        $context = $this->requestContext();
        $settings = $this->deployConfigService->getSettings();
        $profile = $this->profileService->getFormData($context, $settings);
        $effectiveSettings = $this->applyProfileSettings($settings, $profile);
        $preflight = $this->profileService->buildPanelPreflight($profile, $effectiveSettings);
        $webhookReplay = $this->webhookReplaySummary($effectiveSettings);
        $manualPlan = $this->manualReleasePlanSummary($effectiveSettings, $profile);
        $runtimeRoot = trim((string)($effectiveSettings['deploy_root'] ?? ''));
        $currentRuntime = $this->runtimeService->getCurrent($runtimeRoot !== '' ? $runtimeRoot : null);
        $releaseHistoryError = '';
        $releaseRecords = [];
        $historyScope = $this->historyScopeSummary($context);
        try {
            $releaseRecords = $this->releaseRecords($this->historyService->getRecentForContext($context, 12));
        } catch (\Throwable $throwable) {
            $releaseHistoryError = $throwable->getMessage();
        }

        $this->assign('title', __('WLS 部署发布'));
        $this->assign('page_title', __('WLS 部署发布'));
        $this->assign('wlsDeployPageKey', $pageKey);
        $this->assign('wlsDeployContext', $context);
        $this->assign('wlsDeploySettings', $this->settingsSummary($effectiveSettings));
        $this->assign('wlsDeployProfile', $profile);
        $this->assign('wlsDeployPreflight', $preflight);
        $this->assign('wlsDeployCommandPolicy', $this->commandPolicyService->getPanelSummary());
        $embeddedParams = $this->embeddedUrlParams();
        $this->assign('wlsDeployProfileSaveUrl', $this->_url->getBackendUrl('deploy/backend/wls-deploy/profile-save', $embeddedParams));
        $this->assign('wlsDeployPreflightRunUrl', $this->_url->getBackendUrl('deploy/backend/wls-deploy/preflight-run', $embeddedParams));
        $this->assign('wlsDeployWebhookReplayUrl', $this->_url->getBackendUrl('deploy/backend/wls-deploy/webhook-replay', $embeddedParams));
        $this->assign('wlsDeployManualPlanUrl', $this->_url->getBackendUrl('deploy/backend/wls-deploy/manual-plan-run', $embeddedParams));
        $this->assign('wlsDeployRollbackRunUrl', $this->_url->getBackendUrl('deploy/backend/wls-deploy/rollback-run', $embeddedParams));
        $this->assign('wlsDeployNotice', $this->resolveNotice((string)$this->request->getGet('deploy_notice', '')));
        $this->assign('wlsDeployError', trim((string)$this->request->getGet('deploy_error', '')));
        $this->assign('wlsDeployRuntime', is_array($currentRuntime) ? $currentRuntime : []);
        $this->assign('wlsDeployRecords', $releaseRecords);
        $this->assign('wlsDeployHistoryScope', $historyScope);
        $this->assign('wlsDeployHistoryError', $releaseHistoryError);
        $this->assign('wlsDeployCapabilities', $this->capabilityCards($effectiveSettings, $profile));
        $this->assign('wlsDeployWebhookReplay', $webhookReplay);
        $this->assign('wlsDeployManualPlan', $manualPlan);
        $this->assign('wlsDeployRollbackResult', $this->rollbackResultSummary());
        $this->assign('wlsDeployEmbedded', $this->isEmbeddedPanelRequest());

        return $this->fetch('index');
    }

    #[Acl('Weline_Deploy::wls_deploy_overview', 'View WLS Deploy Overview', 'mdi mdi-view-dashboard-outline', 'View WLS Panel deploy overview')]
    public function getOverview(): string
    {
        return $this->openPage('overview', 'deploy');
    }

    #[Acl('Weline_Deploy::wls_deploy_release_path', 'View WLS Deploy Release Path', 'mdi mdi-source-branch-sync', 'View WLS Panel deploy release path')]
    public function getReleasePath(): string
    {
        return $this->openPage('release-path', 'release-path');
    }

    #[Acl('Weline_Deploy::wls_deploy_configuration', 'View WLS Deploy Configuration', 'mdi mdi-tune-variant', 'View WLS Panel deploy configuration')]
    public function getConfiguration(): string
    {
        return $this->openPage('configuration', 'configuration');
    }

    #[Acl('Weline_Deploy::wls_deploy_project_profile', 'View WLS Deploy Project Profile', 'mdi mdi-clipboard-edit-outline', 'View WLS Panel deploy project profile')]
    public function getProjectProfile(): string
    {
        return $this->openPage('project-profile', 'project-profile');
    }

    #[Acl('Weline_Deploy::wls_deploy_preflight', 'View WLS Deploy Preflight', 'mdi mdi-shield-check-outline', 'View WLS Panel deploy preflight')]
    public function getPreflight(): string
    {
        return $this->openPage('preflight', 'preflight');
    }

    #[Acl('Weline_Deploy::wls_deploy_webhooks', 'View WLS Deploy Webhooks', 'mdi mdi-replay', 'View WLS Panel deploy webhooks')]
    public function getWebhooks(): string
    {
        return $this->openPage('webhooks', 'webhook');
    }

    #[Acl('Weline_Deploy::wls_deploy_releases', 'View WLS Deploy Releases', 'mdi mdi-history', 'View WLS Panel deploy releases')]
    public function getReleases(): string
    {
        return $this->openPage('releases', 'release-history');
    }

    #[Acl('Weline_Deploy::wls_deploy_manual_plan', 'View WLS Deploy Manual Plan', 'mdi mdi-clipboard-list-outline', 'View WLS Panel deploy manual plan')]
    public function getManualPlan(): string
    {
        return $this->openPage('manual-plan', 'manual-release-plan');
    }

    private function openPage(string $pageKey, string $operation): string
    {
        $this->request->setGet('page_key', $pageKey);
        $this->request->setGet('operation', $operation);
        return $this->getIndex();
    }

    private function normalizePageKey(string $pageKey, string $operation): string
    {
        $pageKey = trim($pageKey);
        if (in_array($pageKey, ['overview', 'release-path', 'configuration', 'project-profile', 'preflight', 'webhooks', 'releases', 'manual-plan'], true)) {
            return $pageKey;
        }

        return match (trim($operation)) {
            'release-path' => 'release-path',
            'configuration' => 'configuration',
            'project-profile' => 'project-profile',
            'preflight' => 'preflight',
            'webhook', 'webhook-replay' => 'webhooks',
            'release-history', 'releases' => 'releases',
            'manual-release-plan' => 'manual-plan',
            default => 'overview',
        };
    }

    #[Acl('Weline_Deploy::wls_deploy_profile_save', '保存 WLS 项目发布 Profile', 'mdi mdi-content-save', '保存 WLS 面板项目发布 Profile')]
    public function postProfileSave(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToDeployPanel(['deploy_error' => (string)__('请求方式错误。')]);
            return '';
        }

        $post = (array)$this->request->getPost();
        $result = $this->profileService->saveFromPanel($post);
        $params = [
            'operation' => trim((string)($post['operation'] ?? 'deploy')),
            'project_id' => trim((string)($post['project_id'] ?? '')),
            'domain' => trim((string)($post['domain'] ?? '')),
            'project_type' => trim((string)($post['project_type'] ?? '')),
        ];

        if (!empty($result['success'])) {
            $params['deploy_notice'] = 'profile_saved';
        } else {
            $params['deploy_error'] = mb_substr((string)($result['message'] ?? __('项目发布 Profile 保存失败。')), 0, 220);
        }

        $this->redirectToDeployPanel($params, '#project-profile');
        return '';
    }

    #[Acl('Weline_Deploy::wls_deploy_preflight_run', '执行 WLS 项目发布预检', 'mdi mdi-shield-check', '执行 WLS 面板项目发布预检')]
    public function postPreflightRun(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToDeployPanel(['deploy_error' => (string)__('请求方式错误。')], '#preflight');
            return '';
        }

        $post = (array)$this->request->getPost();
        $context = [
            'operation' => trim((string)($post['operation'] ?? 'deploy')),
            'project_id' => trim((string)($post['project_id'] ?? '')),
            'domain' => trim((string)($post['domain'] ?? '')),
            'project_type' => trim((string)($post['project_type'] ?? '')),
        ];
        $settings = $this->deployConfigService->getSettings();
        $profile = $this->profileService->getFormData($context, $settings);
        $effectiveSettings = $this->applyProfileSettings($settings, $profile);
        $preflight = $this->profileService->buildPanelPreflight($profile, $effectiveSettings);

        $params = $context;
        if (($preflight['status'] ?? 'danger') === 'danger') {
            $params['deploy_error'] = (string)__('发布预检存在阻断项，请先修正标红项。');
        } else {
            $params['deploy_notice'] = 'preflight_checked';
        }

        $this->redirectToDeployPanel($params, '#preflight');
        return '';
    }

    #[Acl('Weline_Deploy::wls_deploy_webhook_replay', '回放 WLS 发布 Webhook', 'mdi mdi-replay', '回放 WLS 面板 Webhook 触发策略')]
    public function postWebhookReplay(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToDeployPanel(['deploy_error' => (string)__('请求方式错误。')], '#webhook-replay');
            return '';
        }

        $post = (array)$this->request->getPost();
        $context = $this->contextFromInput($post);
        $ref = mb_substr(trim((string)($post['webhook_ref'] ?? '')), 0, 180);
        $params = $context;

        if ($ref === '') {
            $params['deploy_error'] = (string)__('请输入需要回放的 Webhook Ref。');
            $this->redirectToDeployPanel($params, '#webhook-replay');
            return '';
        }

        $settings = $this->deployConfigService->getSettings();
        $profile = $this->profileService->getFormData($context, $settings);
        $effectiveSettings = $this->applyProfileSettings($settings, $profile);
        $resolved = $this->webhookRefResolver->resolve($ref, $effectiveSettings);

        $params['deploy_notice'] = 'webhook_replay_checked';
        $params['replay_ref'] = mb_substr((string)($resolved['ref'] ?? $ref), 0, 180);
        $params['replay_type'] = mb_substr((string)($resolved['type'] ?? ''), 0, 40);
        $params['replay_version'] = mb_substr((string)($resolved['deploy_version_hint'] ?? ''), 0, 120);
        $params['replay_checkout'] = mb_substr((string)($resolved['git_checkout'] ?? ''), 0, 120);
        $params['replay_reason'] = mb_substr((string)($resolved['reason'] ?? ''), 0, 80);
        $params['replay_status'] = !empty($resolved['skipped']) ? 'skipped' : 'ready';

        $this->redirectToDeployPanel($params, '#webhook-replay');
        return '';
    }

    /*
    #[Acl('Weline_Deploy::wls_deploy_rollback_run', '执行 WLS 项目发布回滚', 'mdi mdi-restore', '执行 WLS 面板项目发布回滚')]
    */
    #[Acl('Weline_Deploy::wls_deploy_manual_plan', 'Build WLS manual release plan', 'mdi mdi-clipboard-list-outline', 'Build read-only WLS Panel manual release execution plan')]
    public function postManualPlanRun(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToDeployPanel(['deploy_error' => (string)__('Invalid request method.')], '#manual-release-plan');
            return '';
        }

        $post = (array)$this->request->getPost();
        $context = $this->contextFromInput($post);
        $manualRef = mb_substr(trim((string)($post['manual_ref'] ?? '')), 0, 180);
        $isManualReleaseRun = (string)($post['manual_action'] ?? '') === 'run_release';
        $params = $context;

        if ($manualRef === '') {
            $params['deploy_error'] = $isManualReleaseRun
                ? (string)__('Please enter a Git ref for the manual release.')
                : (string)__('Please enter a Git ref for the manual release plan.');
            $this->redirectToDeployPanel($params, '#manual-release-plan');
            return '';
        }

        $settings = $this->deployConfigService->getSettings();
        $profile = $this->profileService->getFormData($context, $settings);
        $effectiveSettings = $this->applyProfileSettings($settings, $profile);
        $preflight = $this->profileService->buildPanelPreflight($profile, $effectiveSettings);

        if (($preflight['status'] ?? 'danger') === 'danger') {
            $params['deploy_error'] = $isManualReleaseRun
                ? (string)__('Release preflight has blocking items, so the manual release was not executed.')
                : (string)__('Release preflight has blocking items, so the manual plan was not built.');
            $this->redirectToDeployPanel($params, '#manual-release-plan');
            return '';
        }

        if ($isManualReleaseRun && (string)($post['confirm_manual_release'] ?? '0') !== '1') {
            $params['deploy_error'] = (string)__('Confirm the manual release before running it.');
            $this->redirectToDeployPanel($params, '#manual-release-plan');
            return '';
        }

        try {
            $resolved = $this->resolveManualReleaseRef($manualRef, $effectiveSettings);
        } catch (\Throwable $throwable) {
            $params['deploy_error'] = mb_substr($throwable->getMessage(), 0, 220);
            $this->redirectToDeployPanel($params, '#manual-release-plan');
            return '';
        }

        if ($isManualReleaseRun) {
            return $this->runManualRelease($context, $manualRef, $params, $resolved);
        }

        $params['deploy_notice'] = !empty($resolved['skipped']) ? 'manual_plan_skipped' : 'manual_plan_ready';
        $params['manual_status'] = !empty($resolved['skipped']) ? 'skipped' : 'ready';
        $params['manual_ref'] = mb_substr((string)($resolved['ref'] ?? $manualRef), 0, 180);
        $params['manual_type'] = mb_substr((string)($resolved['type'] ?? ''), 0, 40);
        $params['manual_version'] = mb_substr((string)($resolved['deploy_version_hint'] ?? ''), 0, 120);
        $params['manual_checkout'] = mb_substr((string)($resolved['git_checkout'] ?? ''), 0, 120);
        $params['manual_reason'] = mb_substr((string)($resolved['reason'] ?? ''), 0, 80);

        $this->redirectToDeployPanel($params, '#manual-release-plan');
        return '';
    }

    private function runManualRelease(array $context, string $manualRef, array $params, array $resolved): string
    {
        $params['manual_ref'] = mb_substr((string)($resolved['ref'] ?? $manualRef), 0, 180);
        $params['manual_type'] = mb_substr((string)($resolved['type'] ?? ''), 0, 40);
        $params['manual_version'] = mb_substr((string)($resolved['deploy_version_hint'] ?? ''), 0, 120);
        $params['manual_checkout'] = mb_substr((string)($resolved['git_checkout'] ?? ''), 0, 120);
        $params['manual_reason'] = mb_substr((string)($resolved['reason'] ?? ''), 0, 80);

        if (!empty($resolved['skipped'])) {
            $params['deploy_notice'] = 'manual_release_skipped';
            $params['manual_status'] = 'skipped';
            $this->redirectToDeployPanel($params, '#manual-release-plan');
            return '';
        }

        $releaseContext = $this->profileService->getReleaseContext($context);
        $releaseConfig = $this->profileService->buildReleaseConfigForContext(
            $releaseContext,
            $this->deployConfigService->getWebhookShellConfig()
        );
        $force = (string)($releaseConfig['DEPLOY_FORCE_RESET'] ?? '0') === '1';
        $result = $this->orchestrator->release([
            'trigger' => 'manual',
            'ref_type' => (string)($resolved['type'] ?? DeployWebhookRefResolver::TYPE_TAG),
            'ref' => (string)($resolved['ref'] ?? $manualRef),
            'deploy_version_hint' => $resolved['deploy_version_hint'] ?? null,
            'git_checkout' => $resolved['git_checkout'] ?? null,
            'git_tag' => ((string)($resolved['type'] ?? '') === DeployWebhookRefResolver::TYPE_TAG)
                ? ($resolved['deploy_version_hint'] ?? null)
                : null,
            'force' => $force,
            'no_backup' => false,
            'printer' => null,
            'config' => $releaseConfig,
            'context' => $releaseContext,
        ]);

        if (!empty($result['success'])) {
            $params['deploy_notice'] = 'manual_release_completed';
            $params['manual_status'] = 'ready';
            $params['manual_release_id'] = mb_substr((string)($result['release_id'] ?? ''), 0, 120);
            $params['manual_version'] = mb_substr((string)($result['deploy_version'] ?? ($params['manual_version'] ?? '')), 0, 120);
            $this->redirectToDeployPanel($params, '#releases');
            return '';
        }

        $params['manual_status'] = 'ready';
        $params['deploy_error'] = mb_substr((string)($result['message'] ?? __('Manual release failed.')), 0, 220);
        $this->redirectToDeployPanel($params, '#manual-release-plan');
        return '';
    }

    #[Acl('Weline_Deploy::wls_deploy_rollback_run', 'Run WLS project rollback', 'mdi mdi-restore', 'Run WLS Panel project rollback')]
    public function postRollbackRun(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToDeployPanel(['deploy_error' => (string)__('请求方式错误。')], '#preflight');
            return '';
        }

        $post = (array)$this->request->getPost();
        $context = $this->contextFromInput($post);
        $params = $context;

        if ((string)($post['confirm_rollback'] ?? '0') !== '1') {
            $params['deploy_error'] = (string)__('请先勾选确认框后再执行回滚。');
            $this->redirectToDeployPanel($params, '#preflight');
            return '';
        }

        $settings = $this->deployConfigService->getSettings();
        $profile = $this->profileService->getFormData($context, $settings);
        $effectiveSettings = $this->applyProfileSettings($settings, $profile);
        $preflight = $this->profileService->buildPanelPreflight($profile, $effectiveSettings);
        $rollbackRef = trim((string)($profile['rollback_ref'] ?? ''));

        if (($preflight['status'] ?? 'danger') === 'danger') {
            $params['deploy_error'] = (string)__('发布预检存在阻断项，不能执行回滚。');
            $this->redirectToDeployPanel($params, '#preflight');
            return '';
        }

        if ($rollbackRef === '') {
            $params['deploy_error'] = (string)__('当前项目 Profile 未配置回滚参考。');
            $this->redirectToDeployPanel($params, '#preflight');
            return '';
        }

        $releaseContext = $this->profileService->getReleaseContext($context);
        $releaseConfig = $this->profileService->buildReleaseConfigForContext($releaseContext, $settings);
        $result = $this->orchestrator->rollback([
            'rollback_ref' => $rollbackRef,
            'context' => $releaseContext,
            'config' => $releaseConfig,
        ]);

        if (!empty($result['success'])) {
            $params['deploy_notice'] = 'rollback_completed';
            $params['rollback_ref'] = mb_substr($rollbackRef, 0, 180);
            $params['rollback_version'] = mb_substr((string)($result['deploy_version'] ?? ''), 0, 120);
            $params['rollback_release_id'] = mb_substr((string)($result['release_id'] ?? ''), 0, 120);
            $this->redirectToDeployPanel($params, '#releases');
            return '';
        }

        $params['deploy_error'] = mb_substr((string)($result['message'] ?? __('回滚失败。')), 0, 220);
        $this->redirectToDeployPanel($params, '#preflight');
        return '';
    }

    /**
     * @return array<string, string>
     */
    private function requestContext(): array
    {
        return [
            'operation' => trim((string)$this->request->getGet('operation', '')),
            'project_id' => trim((string)$this->request->getGet('project_id', '')),
            'domain' => trim((string)$this->request->getGet('domain', '')),
            'project_type' => trim((string)$this->request->getGet('project_type', '')),
        ];
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
            'operation' => trim((string)($input['operation'] ?? 'deploy')),
            'project_id' => trim((string)($input['project_id'] ?? '')),
            'domain' => trim((string)($input['domain'] ?? '')),
            'project_type' => trim((string)($input['project_type'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function settingsSummary(array $settings): array
    {
        $triggerMode = trim((string)($settings['deploy_trigger_mode'] ?? ''));
        if (!in_array($triggerMode, DeployConfigService::TRIGGER_MODES, true)) {
            $triggerMode = $this->deployConfigService->getEffectiveTriggerMode();
        }
        $webhookPath = trim((string)($settings['webhook_path'] ?? ''));

        return [
            'project_repo_url' => $this->sanitizeUrl((string)($settings['project_repo_url'] ?? '')),
            'project_repo_configured' => trim((string)($settings['project_repo_url'] ?? '')) !== '',
            'project_branch' => trim((string)($settings['project_branch'] ?? '')),
            'project_remote' => trim((string)($settings['project_remote'] ?? 'origin')) ?: 'origin',
            'deploy_root' => trim((string)($settings['deploy_root'] ?? '')),
            'git_update_mode' => trim((string)($settings['git_update_mode'] ?? 'reset')) ?: 'reset',
            'deploy_trigger_mode' => $triggerMode,
            'deploy_trigger_mode_label' => $this->triggerModeLabel($triggerMode),
            'webhook_path' => $webhookPath,
            'webhook_path_configured' => $webhookPath !== '',
            'webhook_branch' => trim((string)($settings['webhook_branch'] ?? '')),
            'webhook_tag_prefix' => trim((string)($settings['webhook_tag_prefix'] ?? '')),
            'run_composer_install' => (string)($settings['run_composer_install'] ?? '0') === '1',
            'backup_before_deploy' => trim((string)($settings['backup_before_deploy'] ?? '')) !== '',
            'clean_before_deploy' => trim((string)($settings['clean_before_deploy'] ?? '')) !== '',
            'post_deploy_command_configured' => trim((string)($settings['post_deploy_command'] ?? '')) !== '',
            'webhook_secret_configured' => $this->secretConfigured($settings, 'webhook_secret'),
            'webhook_secret_source' => trim((string)($settings['webhook_secret_source'] ?? 'global')) === 'project' ? 'project' : 'global',
            'project_token_configured' => $this->secretConfigured($settings, 'project_token'),
            'deploy_probe_token_configured' => $this->secretConfigured($settings, 'deploy_probe_token'),
            'cloudflare_enabled' => (string)($settings['cloudflare_enabled'] ?? '0') === '1',
            'cloudflare_token_configured' => $this->secretConfigured($settings, 'cloudflare_api_token'),
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function applyProfileSettings(array $settings, array $profile): array
    {
        if (empty($profile['has_profile']) || empty($profile['enabled'])) {
            return $settings;
        }

        $map = [
            'project_repo_url',
            'project_branch',
            'project_remote',
            'deploy_root',
            'deploy_trigger_mode',
            'webhook_branch',
            'webhook_tag_prefix',
            'git_update_mode',
            'composer_command',
            'post_deploy_command',
        ];
        foreach ($map as $key) {
            $value = trim((string)($profile[$key] ?? ''));
            if ($value !== '') {
                $settings[$key] = $value;
            }
        }

        $settings['backup_before_deploy'] = !empty($profile['backup_before_deploy']) ? '1' : '';
        $settings['run_composer_install'] = !empty($profile['run_composer_install']) ? '1' : '0';
        if (!empty($profile['webhook_secret_configured'])) {
            $settings['webhook_secret'] = '__project_profile_secret__';
            $settings['webhook_secret_source'] = 'project';
        } else {
            $settings['webhook_secret_source'] = $this->secretConfigured($settings, 'webhook_secret') ? 'global' : '';
        }

        return $settings;
    }

    /**
     * @param DeployRelease[] $records
     * @return array<int, array<string, mixed>>
     */
    private function releaseRecords(array $records): array
    {
        $result = [];
        foreach ($records as $record) {
            if (!$record instanceof DeployRelease) {
                continue;
            }

            $status = (string)$record->getData(DeployRelease::schema_fields_STATUS);
            $startedAt = (int)$record->getData(DeployRelease::schema_fields_STARTED_AT);
            $finishedAt = (int)$record->getData(DeployRelease::schema_fields_FINISHED_AT);
            $duration = $record->getData(DeployRelease::schema_fields_DURATION_MS);

            $result[] = [
                'release_id' => (string)$record->getData(DeployRelease::schema_fields_ID),
                'deploy_version' => (string)$record->getData(DeployRelease::schema_fields_DEPLOY_VERSION),
                'worker_build_id' => (string)$record->getData(DeployRelease::schema_fields_WORKER_BUILD_ID),
                'git_ref_type' => (string)$record->getData(DeployRelease::schema_fields_GIT_REF_TYPE),
                'git_ref' => (string)$record->getData(DeployRelease::schema_fields_GIT_REF),
                'git_tag' => (string)$record->getData(DeployRelease::schema_fields_GIT_TAG),
                'git_branch' => (string)$record->getData(DeployRelease::schema_fields_GIT_BRANCH),
                'git_commit_short' => substr((string)$record->getData(DeployRelease::schema_fields_GIT_COMMIT), 0, 8),
                'trigger_type' => (string)$record->getData(DeployRelease::schema_fields_TRIGGER_TYPE),
                'profile_key' => (string)$record->getData(DeployRelease::schema_fields_PROFILE_KEY),
                'project_id' => (string)$record->getData(DeployRelease::schema_fields_PROJECT_ID),
                'domain' => (string)$record->getData(DeployRelease::schema_fields_DOMAIN),
                'project_type' => (string)$record->getData(DeployRelease::schema_fields_PROJECT_TYPE),
                'status' => $status,
                'status_label' => $this->statusLabel($status),
                'status_tone' => $this->statusTone($status),
                'started_at' => $startedAt > 0 ? date('Y-m-d H:i:s', $startedAt) : '',
                'finished_at' => $finishedAt > 0 ? date('Y-m-d H:i:s', $finishedAt) : '',
                'duration' => is_numeric($duration) && (int)$duration > 0 ? round(((int)$duration) / 1000, 1) . 's' : '',
                'is_current' => (int)$record->getData(DeployRelease::schema_fields_IS_CURRENT) === 1,
                'error_message' => (string)$record->getData(DeployRelease::schema_fields_ERROR_MESSAGE),
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $context
     * @return array{is_scoped:bool,label:string,detail:string}
     */
    private function historyScopeSummary(array $context): array
    {
        $projectId = trim((string)($context['project_id'] ?? ''));
        $domain = $this->normalizeDomain((string)($context['domain'] ?? ''));
        $projectType = trim((string)($context['project_type'] ?? ''));

        if ($projectId !== '') {
            return [
                'is_scoped' => true,
                'label' => (string)__('当前项目发布记录'),
                'detail' => $projectType !== '' ? $projectId . ' / ' . $projectType : $projectId,
            ];
        }

        if ($domain !== '') {
            return [
                'is_scoped' => true,
                'label' => (string)__('当前域名发布记录'),
                'detail' => $projectType !== '' ? $domain . ' / ' . $projectType : $domain,
            ];
        }

        return [
            'is_scoped' => false,
            'label' => (string)__('全局发布记录'),
            'detail' => (string)__('未选择子项目，显示 WLS 主项目发布历史。'),
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<int, array<string, string>>
     */
    private function capabilityCards(array $settings, array $profile): array
    {
        $triggerMode = trim((string)($settings['deploy_trigger_mode'] ?? ''));
        if (!in_array($triggerMode, DeployConfigService::TRIGGER_MODES, true)) {
            $triggerMode = $this->deployConfigService->getEffectiveTriggerMode();
        }
        $tagMode = $triggerMode === DeployConfigService::TRIGGER_MODE_TAG;
        $hasProfile = !empty($profile['has_profile']);
        $profileEnabled = !empty($profile['enabled']);

        return [
            [
                'title' => (string)__('Tag 发布策略'),
                'state' => $tagMode ? (string)__('默认启用') : (string)__('已调整'),
                'tone' => $tagMode ? 'ok' : 'warning',
                'description' => (string)__('默认仅响应 tag push；需要分支发布时必须在 Deploy 配置中显式开启。'),
            ],
            [
                'title' => (string)__('Webhook 随机路径'),
                'state' => trim((string)($settings['webhook_path'] ?? '')) !== '' ? (string)__('已生成') : (string)__('待生成'),
                'tone' => trim((string)($settings['webhook_path'] ?? '')) !== '' ? 'ok' : 'warning',
                'description' => (string)__('公网入口使用 ~wh~ 随机路径和密钥鉴权，由 ModuleRouter 转入发布控制器。'),
            ],
            [
                'title' => (string)__('子项目发布档案'),
                'state' => $hasProfile ? ($profileEnabled ? (string)__('已启用') : (string)__('已保存')) : (string)__('继承全局'),
                'tone' => $hasProfile && $profileEnabled ? 'ok' : 'muted',
                'description' => $hasProfile
                    ? (string)__('当前项目已有独立发布 Profile，后续 webhook/manual release 会按项目上下文读取。')
                    : (string)__('当前项目暂未保存独立 Profile，发布能力继续读取全局 Deploy 配置。'),
            ],
            [
                'title' => (string)__('发布日志'),
                'state' => (string)__('已接入'),
                'tone' => 'ok',
                'description' => (string)__('Webhook 与命令发布共用发布历史服务，面板可直接查看最近发布状态。'),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function secretConfigured(array $settings, string $key): bool
    {
        return trim((string)($settings[$key] ?? '')) !== '';
    }

    private function sanitizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return preg_replace('/\/\/[^\/\s@]+@/', '//***@', $url) ?? $url;
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host = (string)$parts['host'];
        $port = isset($parts['port']) ? ':' . (string)$parts['port'] : '';
        $path = (string)($parts['path'] ?? '');
        $query = isset($parts['query']) ? '?' . (string)$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . (string)$parts['fragment'] : '';

        return $scheme . $host . $port . $path . $query . $fragment;
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#i', '', $domain) ?? $domain;
        $domain = explode('/', $domain, 2)[0] ?? $domain;
        return trim($domain);
    }

    private function triggerModeLabel(string $mode): string
    {
        return match ($mode) {
            DeployConfigService::TRIGGER_MODE_BRANCH => (string)__('仅分支 Push'),
            DeployConfigService::TRIGGER_MODE_BOTH => (string)__('分支 + Tag 都生效'),
            default => (string)__('仅 Tag Push'),
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'success' => (string)__('成功'),
            'failed' => (string)__('失败'),
            'running' => (string)__('运行中'),
            'pending' => (string)__('等待中'),
            default => $status !== '' ? $status : (string)__('未知'),
        };
    }

    private function statusTone(string $status): string
    {
        return match ($status) {
            'success' => 'ok',
            'failed' => 'danger',
            'running' => 'warning',
            default => 'muted',
        };
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function webhookReplaySummary(array $settings): array
    {
        $status = trim((string)$this->request->getGet('replay_status', ''));
        $ref = trim((string)$this->request->getGet('replay_ref', ''));
        $type = trim((string)$this->request->getGet('replay_type', ''));
        $version = trim((string)$this->request->getGet('replay_version', ''));
        $checkout = trim((string)$this->request->getGet('replay_checkout', ''));
        $reason = trim((string)$this->request->getGet('replay_reason', ''));
        $hasResult = $status !== '' || $ref !== '';
        $status = in_array($status, ['ready', 'skipped'], true) ? $status : ($hasResult ? 'skipped' : 'idle');
        $branch = trim((string)($settings['webhook_branch'] ?? $settings['project_branch'] ?? ''));
        $tagPrefix = trim((string)($settings['webhook_tag_prefix'] ?? ''));
        $sampleBranch = $branch !== '' ? $branch : 'main';
        $sampleTag = ($tagPrefix !== '' ? $tagPrefix : 'v') . '1.0.0';

        return [
            'has_result' => $hasResult,
            'status' => $status,
            'tone' => $status === 'ready' ? 'ok' : ($status === 'skipped' ? 'warning' : 'muted'),
            'status_label' => match ($status) {
                'ready' => (string)__('会触发发布'),
                'skipped' => (string)__('不会触发'),
                default => (string)__('待回放'),
            },
            'ref' => $ref,
            'type' => $type !== '' ? $type : '-',
            'version' => $version !== '' ? $version : '-',
            'checkout' => $checkout !== '' ? $checkout : '-',
            'reason' => $reason,
            'reason_label' => $this->webhookReplayReasonLabel($reason, $status),
            'sample_tag_ref' => 'refs/tags/' . $sampleTag,
            'sample_branch_ref' => 'refs/heads/' . $sampleBranch,
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function manualReleasePlanSummary(array $settings, array $profile): array
    {
        $status = trim((string)$this->request->getGet('manual_status', ''));
        $ref = trim((string)$this->request->getGet('manual_ref', ''));
        $type = trim((string)$this->request->getGet('manual_type', ''));
        $version = trim((string)$this->request->getGet('manual_version', ''));
        $checkout = trim((string)$this->request->getGet('manual_checkout', ''));
        $reason = trim((string)$this->request->getGet('manual_reason', ''));
        $hasResult = $status !== '' || $ref !== '';
        $status = in_array($status, ['ready', 'skipped'], true) ? $status : ($hasResult ? 'skipped' : 'idle');
        $branch = trim((string)($settings['webhook_branch'] ?? $settings['project_branch'] ?? ''));
        $tagPrefix = trim((string)($settings['webhook_tag_prefix'] ?? ''));
        $sampleBranch = $branch !== '' ? $branch : 'main';
        $sampleTag = ($tagPrefix !== '' ? $tagPrefix : 'v') . '1.0.0';
        $remote = trim((string)($settings['project_remote'] ?? 'origin')) ?: 'origin';
        $deployRoot = trim((string)($settings['deploy_root'] ?? ''));
        $updateMode = trim((string)($settings['git_update_mode'] ?? 'reset')) ?: 'reset';
        $runComposer = (string)($settings['run_composer_install'] ?? '0') === '1' || ($settings['run_composer_install'] ?? null) === true;
        $composerCommand = trim((string)($settings['composer_command'] ?? ''));
        $postDeployCommand = trim((string)($settings['post_deploy_command'] ?? ''));
        $backupValue = strtolower(trim((string)($settings['backup_before_deploy'] ?? '')));
        $backupEnabled = !in_array($backupValue, ['', '0', 'false', 'no', 'off'], true);
        $profileKey = trim((string)($profile['profile_key'] ?? ''));
        $checkoutTarget = $checkout !== '' ? $checkout : ($type === DeployWebhookRefResolver::TYPE_BRANCH ? ($branch !== '' ? $branch : '-') : '-');

        return [
            'has_result' => $hasResult,
            'status' => $status,
            'tone' => $status === 'ready' ? 'ok' : ($status === 'skipped' ? 'warning' : 'muted'),
            'status_label' => match ($status) {
                'ready' => (string)__('Plan ready'),
                'skipped' => (string)__('Policy skipped'),
                default => (string)__('Waiting for ref'),
            },
            'ref' => $ref,
            'type' => $type !== '' ? $type : '-',
            'version' => $version !== '' ? $version : ($type === DeployWebhookRefResolver::TYPE_BRANCH ? 'next commit' : '-'),
            'checkout' => $checkoutTarget,
            'reason' => $reason,
            'reason_label' => $this->webhookReplayReasonLabel($reason, $status),
            'sample_tag_ref' => 'refs/tags/' . $sampleTag,
            'sample_branch_ref' => 'refs/heads/' . $sampleBranch,
            'profile_key' => $profileKey !== '' ? $profileKey : 'local',
            'deploy_root' => $deployRoot !== '' ? $deployRoot : (string)__('Current WLS project root'),
            'remote' => $remote,
            'branch' => $branch !== '' ? $branch : 'main',
            'update_mode' => $updateMode,
            'backup_label' => $backupEnabled ? (string)__('Enabled') : (string)__('Disabled'),
            'composer_label' => $runComposer
                ? ($composerCommand !== '' ? $composerCommand : (string)__('Enabled, command not configured'))
                : (string)__('Disabled'),
            'post_deploy_label' => $postDeployCommand !== '' ? $postDeployCommand : (string)__('Disabled'),
            'steps' => $this->manualReleasePlanSteps(
                $status,
                $type,
                $checkoutTarget,
                $remote,
                $branch !== '' ? $branch : 'main',
                $updateMode,
                $backupEnabled,
                $runComposer,
                $composerCommand,
                $postDeployCommand
            ),
        ];
    }

    /**
     * @return array<int, array{title:string,detail:string}>
     */
    private function manualReleasePlanSteps(
        string $status,
        string $type,
        string $checkoutTarget,
        string $remote,
        string $branch,
        string $updateMode,
        bool $backupEnabled,
        bool $runComposer,
        string $composerCommand,
        string $postDeployCommand
    ): array {
        if ($status !== 'ready') {
            return [
                [
                    'title' => (string)__('No execution plan yet'),
                    'detail' => (string)__('Enter a matching tag or branch ref to preview the manual release path.'),
                ],
            ];
        }

        $gitDetail = $type === DeployWebhookRefResolver::TYPE_TAG
            ? (string)__('Fetch tags from %{1}, then checkout tag %{2}.', [$remote, $checkoutTarget])
            : (string)__('Fetch %{1}, then %{2} branch %{3}.', [$remote, $updateMode === 'pull_ff_only' ? 'fast-forward pull' : 'reset hard to', $branch]);

        return [
            [
                'title' => (string)__('Preflight gate'),
                'detail' => (string)__('Reuse the selected project Profile, command allowlist, and trigger policy before any release execution.'),
            ],
            [
                'title' => (string)__('Backup'),
                'detail' => $backupEnabled
                    ? (string)__('Create a deploy backup before Git changes.')
                    : (string)__('Skip backup because the Profile disabled it.'),
            ],
            [
                'title' => (string)__('Git update'),
                'detail' => $gitDetail,
            ],
            [
                'title' => (string)__('Composer'),
                'detail' => $runComposer
                    ? ($composerCommand !== '' ? $composerCommand : (string)__('Composer is enabled, but no command is configured yet.'))
                    : (string)__('Composer is disabled for this project.'),
            ],
            [
                'title' => (string)__('Post deploy'),
                'detail' => $postDeployCommand !== ''
                    ? $postDeployCommand
                    : (string)__('No post-deploy command is configured.'),
            ],
            [
                'title' => (string)__('Runtime stamp and reload'),
                'detail' => (string)__('A real release would write current.json, sync env release metadata, record project-scoped history, dispatch release_after, and reload WLS.'),
            ],
            [
                'title' => (string)__('Execution gate'),
                'detail' => (string)__('Build Plan stays read-only. Run Release requires explicit confirmation and re-runs the preflight and trigger policy before execution.'),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{type:string, ref:string, deploy_version_hint:string|null, git_checkout:string|null, skipped:bool, reason:string|null}
     */
    private function resolveManualReleaseRef(string $manualRef, array $settings): array
    {
        $ref = $this->normalizeManualReleaseRef($manualRef, $settings);
        if ($ref === '') {
            return [
                'type' => DeployWebhookRefResolver::TYPE_BRANCH,
                'ref' => '',
                'deploy_version_hint' => null,
                'git_checkout' => null,
                'skipped' => true,
                'reason' => 'unknown_ref',
            ];
        }

        return $this->webhookRefResolver->resolve($ref, $settings);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function normalizeManualReleaseRef(string $manualRef, array $settings): string
    {
        $ref = mb_substr(trim($manualRef), 0, 180);
        if ($ref === '') {
            return '';
        }
        if (preg_match('/[\s`|;<>"\'\\\\]/', $ref) === 1) {
            throw new \InvalidArgumentException((string)__('Manual release ref contains unsupported shell control characters.'));
        }
        if (str_starts_with($ref, '-') || str_contains($ref, '..') || str_contains($ref, '@{')) {
            throw new \InvalidArgumentException((string)__('Manual release ref is outside the WLS allowlist.'));
        }
        if (str_starts_with($ref, 'refs/tags/') || str_starts_with($ref, 'refs/heads/')) {
            return $ref;
        }

        $triggerMode = trim((string)($settings['deploy_trigger_mode'] ?? DeployConfigService::TRIGGER_MODE_TAG));
        $branch = trim((string)($settings['webhook_branch'] ?? $settings['project_branch'] ?? ''));
        if ($triggerMode === DeployConfigService::TRIGGER_MODE_BRANCH) {
            return 'refs/heads/' . $ref;
        }
        if ($triggerMode === DeployConfigService::TRIGGER_MODE_BOTH && $branch !== '' && $ref === $branch) {
            return 'refs/heads/' . $ref;
        }

        return 'refs/tags/' . $ref;
    }

    /**
     * @return array<string, mixed>
     */
    private function rollbackResultSummary(): array
    {
        $releaseId = trim((string)$this->request->getGet('rollback_release_id', ''));
        $version = trim((string)$this->request->getGet('rollback_version', ''));
        $ref = trim((string)$this->request->getGet('rollback_ref', ''));

        return [
            'has_result' => $releaseId !== '' || $version !== '' || $ref !== '',
            'release_id' => $releaseId,
            'version' => $version,
            'ref' => $ref,
        ];
    }

    private function webhookReplayReasonLabel(string $reason, string $status): string
    {
        if ($status === 'ready') {
            return (string)__('策略命中，会进入发布流程。');
        }

        return match ($reason) {
            'trigger_mode_tag_only' => (string)__('当前为仅 Tag Push，分支事件会被跳过。'),
            'trigger_mode_branch_only' => (string)__('当前为仅分支 Push，Tag 事件会被跳过。'),
            'branch_mismatch' => (string)__('分支与 Webhook 分支过滤不匹配。'),
            'tag_prefix_mismatch' => (string)__('Tag 不符合配置的前缀过滤。'),
            'unknown_ref' => (string)__('Ref 不是可识别的分支或 Tag。'),
            default => $reason !== '' ? $reason : (string)__('暂无回放结果。'),
        };
    }

    private function resolveNotice(string $code): string
    {
        return match ($code) {
            'profile_saved' => (string)__('项目发布 Profile 已保存。'),
            'preflight_checked' => (string)__('发布预检已完成，未执行真实发布。'),
            'webhook_replay_checked' => (string)__('Webhook 回放预检已完成，未执行真实发布。'),
            'rollback_completed' => (string)__('项目回滚已完成。'),
            'manual_plan_ready' => (string)__('Manual release plan is ready; no release was executed.'),
            'manual_plan_skipped' => (string)__('Manual release plan was skipped by the current trigger policy; no release was executed.'),
            'manual_release_completed' => (string)__('Manual release completed.'),
            'manual_release_skipped' => (string)__('Manual release was skipped by the current trigger policy; no release was executed.'),
            default => '',
        };
    }

    /**
     * @param array<string, string> $params
     */
    private function redirectToDeployPanel(array $params, string $fragment = ''): void
    {
        $cleanParams = [];
        foreach ($params as $key => $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $cleanParams[$key] = $value;
            }
        }
        if ($this->shouldKeepEmbeddedMode()) {
            $cleanParams['embedded'] = '1';
        }

        $this->redirect($this->_url->getBackendUrl($this->deployRouteForFragment($fragment), $cleanParams));
    }

    private function deployRouteForFragment(string $fragment): string
    {
        return match (ltrim(trim($fragment), '#')) {
            'release-path' => 'deploy/backend/wls-deploy/release-path',
            'configuration' => 'deploy/backend/wls-deploy/configuration',
            'project-profile' => 'deploy/backend/wls-deploy/project-profile',
            'preflight' => 'deploy/backend/wls-deploy/preflight',
            'webhook-replay' => 'deploy/backend/wls-deploy/webhooks',
            'releases' => 'deploy/backend/wls-deploy/releases',
            'manual-release-plan' => 'deploy/backend/wls-deploy/manual-plan',
            default => 'deploy/backend/wls-deploy/overview',
        };
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
        $meta['class'] = trim((string)($meta['class'] ?? '') . ' wls-deploy-fullscreen');

        $this->assign('meta', $meta);
        $this->assign('layoutShowPageHeader', false);
        $this->assign('layoutShowMessages', false);
    }
}
