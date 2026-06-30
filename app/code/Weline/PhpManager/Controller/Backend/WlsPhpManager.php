<?php
declare(strict_types=1);

namespace Weline\PhpManager\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\PhpManager\Service\WlsPhpExtensionExecutionService;
use Weline\PhpManager\Service\WlsPhpExtensionPlanService;
use Weline\PhpManager\Service\WlsPhpIniService;
use Weline\PhpManager\Service\WlsPhpProfileService;

#[Acl('Weline_PhpManager::wls_php_manager', 'WLS PHP Manager', 'mdi mdi-language-php', 'WLS Panel PHP profile entry', 'Weline_Backend::system_maintenance')]
class WlsPhpManager extends BackendController
{
    private readonly WlsPhpProfileService $profileService;
    private readonly WlsPhpIniService $iniService;
    private readonly WlsPhpExtensionPlanService $extensionPlanService;
    private readonly WlsPhpExtensionExecutionService $extensionExecutionService;

    public function __construct(
        ?WlsPhpProfileService $profileService = null,
        ?WlsPhpIniService $iniService = null,
        ?WlsPhpExtensionPlanService $extensionPlanService = null,
        ?WlsPhpExtensionExecutionService $extensionExecutionService = null
    ) {
        $this->profileService = $profileService ?? ObjectManager::getInstance(WlsPhpProfileService::class);
        $this->iniService = $iniService ?? ObjectManager::getInstance(WlsPhpIniService::class);
        $this->extensionPlanService = $extensionPlanService ?? ObjectManager::getInstance(WlsPhpExtensionPlanService::class);
        $this->extensionExecutionService = $extensionExecutionService ?? ObjectManager::getInstance(WlsPhpExtensionExecutionService::class);
    }

    #[Acl('Weline_PhpManager::wls_php_manager_index', 'View WLS PHP Manager', 'mdi mdi-language-php', 'View WLS Panel PHP profiles')]
    public function getIndex(): string
    {
        $this->useStandaloneLayout();

        $context = $this->requestContext();
        $pageKey = $this->currentPageKey((string)($context['operation'] ?? ''));
        $runtime = $this->profileService->getRuntimeInfo();
        $projectProfile = $this->profileService->getFormData($context);
        $inheritanceMap = $this->profileService->getInheritanceMap($context);
        $iniPlan = $this->iniService->getIniPlan($context);
        $extensionPlan = $this->extensionPlanService->buildPlan($this->extensionPlanInput(), $runtime, $projectProfile);

        $this->assign('title', __('WLS PHP Manager'));
        $this->assign('page_title', __('WLS PHP Manager'));
        $this->assign('wlsPhpManagerContext', $context);
        $this->assign('wlsPhpManagerPageKey', $pageKey);
        $this->assign('wlsPhpManagerPageUrls', $this->pageUrls($context));
        $this->assign('wlsPhpManagerRuntime', $runtime);
        $this->assign('wlsPhpManagerProjectProfile', $projectProfile);
        $this->assign('wlsPhpManagerInheritanceMap', $inheritanceMap);
        $this->assign('wlsPhpManagerIniPlan', $iniPlan);
        $this->assign('wlsPhpManagerExtensionPlan', $extensionPlan);
        $this->assign('wlsPhpManagerAuditRecords', $this->profileService->getRecentAuditRecords());
        $this->assign('wlsPhpManagerCapabilities', $this->capabilityCards($runtime, $projectProfile));
        $this->assign('wlsPhpManagerNotice', $this->resolveNotice((string)$this->request->getGet('phpm_notice', '')));
        $this->assign('wlsPhpManagerError', \mb_substr(\trim((string)$this->request->getGet('phpm_error', '')), 0, 220));
        $this->assign('wlsPhpManagerEmbedded', $this->isEmbeddedPanelRequest());
        $embeddedParams = $this->embeddedUrlParams();
        $this->assign('wlsPhpManagerProfileSaveUrl', $this->_url->getBackendUrl('weline_phpmanager/backend/wls-php-manager/profile-save', $embeddedParams));
        $this->assign('wlsPhpManagerIniApplyUrl', $this->_url->getBackendUrl('weline_phpmanager/backend/wls-php-manager/ini-apply', $embeddedParams));
        $this->assign('wlsPhpManagerIniRollbackUrl', $this->_url->getBackendUrl('weline_phpmanager/backend/wls-php-manager/ini-rollback', $embeddedParams));
        $this->assign('wlsPhpManagerExtensionExecuteUrl', $this->_url->getBackendUrl('weline_phpmanager/backend/wls-php-manager/extension-execute', $embeddedParams));

        return $this->fetch('index');
    }

    #[Acl('Weline_PhpManager::wls_php_manager_summary', 'View WLS PHP Summary', 'mdi mdi-view-dashboard-outline', 'View WLS Panel PHP summary')]
    public function getSummary(): string
    {
        return $this->openPage('summary');
    }

    #[Acl('Weline_PhpManager::wls_php_manager_runtime', 'View WLS PHP Runtime', 'mdi mdi-console', 'View WLS Panel PHP runtime')]
    public function getRuntime(): string
    {
        return $this->openPage('runtime');
    }

    #[Acl('Weline_PhpManager::wls_php_manager_inheritance', 'View WLS PHP Inheritance', 'mdi mdi-source-branch', 'View WLS Panel PHP inheritance')]
    public function getInheritance(): string
    {
        return $this->openPage('inheritance');
    }

    #[Acl('Weline_PhpManager::wls_php_manager_project_profile', 'View WLS PHP Project Profile', 'mdi mdi-tune-variant', 'View WLS Panel PHP project profile')]
    public function getProjectProfile(): string
    {
        return $this->openPage('project-profile');
    }

    #[Acl('Weline_PhpManager::wls_php_manager_ini', 'View WLS PHP ini', 'mdi mdi-file-cog-outline', 'View WLS Panel php.ini apply')]
    public function getIni(): string
    {
        return $this->openPage('ini');
    }

    #[Acl('Weline_PhpManager::wls_php_manager_extensions', 'View WLS PHP Extensions', 'mdi mdi-puzzle-outline', 'View WLS Panel PHP extensions')]
    public function getExtensions(): string
    {
        return $this->openPage('extensions');
    }

    #[Acl('Weline_PhpManager::wls_php_manager_audit', 'View WLS PHP Audit', 'mdi mdi-clipboard-text-clock-outline', 'View WLS Panel PHP audit')]
    public function getAudit(): string
    {
        return $this->openPage('audit');
    }

    private function openPage(string $pageKey): string
    {
        $pageKey = $this->normalizePageKey($pageKey);
        $this->request->setGet('page_key', $pageKey);
        $this->request->setGet('operation', $this->operationForPageKey($pageKey));
        return $this->getIndex();
    }

    #[Acl('Weline_PhpManager::wls_php_manager_profile_save', 'Save WLS PHP Profile', 'mdi mdi-content-save-cog-outline', 'Save WLS Panel project PHP profile')]
    public function postProfileSave(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToPhpManager(['phpm_error' => (string)__('Invalid request method.')], '#project-profile');
            return '';
        }

        $post = (array)$this->request->getPost();
        $params = $this->contextFromInput($post);
        $result = $this->profileService->saveFromPanel($post);

        if (!empty($result['success'])) {
            $runtimeAction = (string)($result['runtime_action'] ?? 'none');
            $runtimeSuccess = (bool)($result['runtime_action_success'] ?? false);
            $params['phpm_notice'] = $runtimeAction === 'reload' && $runtimeSuccess
                ? 'profile_saved_reload_requested'
                : 'profile_saved';
            if ($runtimeAction === 'reload' && !$runtimeSuccess) {
                $params['phpm_error'] = \mb_substr((string)($result['runtime_action_message'] ?? __('WLS reload was not completed.')), 0, 220);
            }
        } else {
            $params['phpm_error'] = \mb_substr((string)($result['message'] ?? __('Project PHP profile save failed.')), 0, 220);
        }

        $this->redirectToPhpManager($params, '#project-profile');
        return '';
    }

    #[Acl('Weline_PhpManager::wls_php_manager_ini_apply', 'Apply WLS PHP ini', 'mdi mdi-file-cog-outline', 'Apply WLS Panel project PHP profile to php.ini')]
    public function postIniApply(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToPhpManager(['phpm_error' => (string)__('Invalid request method.')], '#ini-apply');
            return '';
        }

        $post = (array)$this->request->getPost();
        $params = $this->contextFromInput($post);
        $result = $this->iniService->applyIniFromPanel($post);

        if (!empty($result['success'])) {
            $runtimeAction = (string)($result['runtime_action'] ?? 'none');
            $runtimeSuccess = (bool)($result['runtime_action_success'] ?? false);
            $params['phpm_notice'] = (int)($result['change_count'] ?? 0) > 0
                ? 'ini_applied'
                : 'ini_apply_noop';
            if ($runtimeAction === 'reload' && !$runtimeSuccess) {
                $params['phpm_error'] = \mb_substr((string)($result['runtime_action_message'] ?? __('WLS reload was not completed.')), 0, 220);
            }
        } else {
            $params['phpm_error'] = \mb_substr((string)($result['message'] ?? __('php.ini apply failed.')), 0, 220);
        }

        $this->redirectToPhpManager($params, '#ini-apply');
        return '';
    }

    #[Acl('Weline_PhpManager::wls_php_manager_ini_rollback', 'Rollback WLS PHP ini', 'mdi mdi-file-restore-outline', 'Restore a PHP Manager php.ini backup')]
    public function postIniRollback(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToPhpManager(['phpm_error' => (string)__('Invalid request method.')], '#ini-apply');
            return '';
        }

        $post = (array)$this->request->getPost();
        $params = $this->contextFromInput($post);
        $result = $this->iniService->rollbackIniFromPanel($post);

        if (!empty($result['success'])) {
            $runtimeAction = (string)($result['runtime_action'] ?? 'none');
            $runtimeSuccess = (bool)($result['runtime_action_success'] ?? false);
            $params['phpm_notice'] = 'ini_restored';
            if ($runtimeAction === 'reload' && !$runtimeSuccess) {
                $params['phpm_error'] = \mb_substr((string)($result['runtime_action_message'] ?? __('WLS reload was not completed.')), 0, 220);
            }
        } else {
            $params['phpm_error'] = \mb_substr((string)($result['message'] ?? __('php.ini rollback failed.')), 0, 220);
        }

        $this->redirectToPhpManager($params, '#ini-apply');
        return '';
    }

    #[Acl('Weline_PhpManager::wls_php_manager_extension_execute', 'Run WLS PHP Extension Action', 'mdi mdi-puzzle-check-outline', 'Run a guarded WLS Panel PHP extension adapter action')]
    public function postExtensionExecute(): string
    {
        if (!$this->request->isPost()) {
            $this->redirectToPhpManager(['phpm_error' => (string)__('Invalid request method.')], '#extensions');
            return '';
        }

        $post = (array)$this->request->getPost();
        $params = \array_merge(
            $this->contextFromInput($post),
            [
                'extension_action' => \trim((string)($post['extension_action'] ?? '')),
                'extension_name' => \trim((string)($post['extension_name'] ?? '')),
            ]
        );
        $result = $this->extensionExecutionService->executeFromPanel($post);

        if (!empty($result['success'])) {
            $runtimeAction = (string)($result['runtime_action'] ?? 'none');
            $runtimeSuccess = (bool)($result['runtime_action_success'] ?? false);
            $params['phpm_notice'] = (int)($result['change_count'] ?? 0) > 0
                ? 'extension_executed'
                : 'extension_execute_noop';
            if ($runtimeAction === 'reload' && !$runtimeSuccess) {
                $params['phpm_error'] = \mb_substr((string)($result['runtime_action_message'] ?? __('WLS reload was not completed.')), 0, 220);
            }
        } else {
            $params['phpm_error'] = \mb_substr((string)($result['message'] ?? __('PHP extension operation failed.')), 0, 220);
        }

        $this->redirectToPhpManager($params, '#extensions');
        return '';
    }

    /**
     * @return array<string, string>
     */
    private function requestContext(): array
    {
        return [
            'operation' => \trim((string)$this->request->getGet('operation', 'php-profile')),
            'profile_key' => \trim((string)$this->request->getGet('profile_key', '')),
            'project_id' => \trim((string)$this->request->getGet('project_id', '')),
            'domain' => \trim((string)$this->request->getGet('domain', '')),
            'project_type' => \trim((string)$this->request->getGet('project_type', '')),
        ];
    }

    private function currentPageKey(string $operation): string
    {
        $explicit = \trim((string)$this->request->getGet('page_key', ''));
        if ($explicit !== '') {
            return $this->normalizePageKey($explicit);
        }

        return $this->pageKeyFromOperation($operation);
    }

    private function normalizePageKey(string $pageKey): string
    {
        $pageKey = \strtolower(\trim($pageKey));
        return match ($pageKey) {
            'summary' => 'summary',
            'runtime' => 'runtime',
            'inheritance' => 'inheritance',
            'project-profile', 'profiles', 'php-profile' => 'project-profile',
            'ini', 'ini-apply' => 'ini',
            'extensions', 'php-extension' => 'extensions',
            'audit' => 'audit',
            default => 'project-profile',
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
            'runtime' => 'runtime',
            'inheritance' => 'inheritance',
            'project-profile' => 'php-profile',
            'ini' => 'ini-apply',
            'extensions' => 'php-extension',
            'audit' => 'audit',
            default => 'php-profile',
        };
    }

    private function routeForPageKey(string $pageKey): string
    {
        return match ($this->normalizePageKey($pageKey)) {
            'summary' => 'weline_phpmanager/backend/wls-php-manager/summary',
            'runtime' => 'weline_phpmanager/backend/wls-php-manager/runtime',
            'inheritance' => 'weline_phpmanager/backend/wls-php-manager/inheritance',
            'project-profile' => 'weline_phpmanager/backend/wls-php-manager/project-profile',
            'ini' => 'weline_phpmanager/backend/wls-php-manager/ini',
            'extensions' => 'weline_phpmanager/backend/wls-php-manager/extensions',
            'audit' => 'weline_phpmanager/backend/wls-php-manager/audit',
            default => 'weline_phpmanager/backend/wls-php-manager/project-profile',
        };
    }

    /**
     * @param array<string, string> $context
     * @return array<string, string>
     */
    private function routeParamsForPage(array $context, string $pageKey): array
    {
        return $this->cleanUrlParams([
            'operation' => $this->operationForPageKey($pageKey),
            'profile_key' => (string)($context['profile_key'] ?? ''),
            'project_id' => (string)($context['project_id'] ?? ''),
            'domain' => (string)($context['domain'] ?? ''),
            'project_type' => (string)($context['project_type'] ?? ''),
            'embedded' => $this->isEmbeddedPanelRequest() ? '1' : '',
        ]);
    }

    /**
     * @param array<string, string> $context
     * @return array<string, string>
     */
    private function pageUrls(array $context): array
    {
        $urls = [];
        foreach (['summary', 'runtime', 'inheritance', 'project-profile', 'ini', 'extensions', 'audit'] as $pageKey) {
            $urls[$pageKey] = $this->_url->getBackendUrl(
                $this->routeForPageKey($pageKey),
                $this->routeParamsForPage($context, $pageKey)
            );
        }

        return $urls;
    }

    /**
     * @return array<string, string>
     */
    private function extensionPlanInput(): array
    {
        return [
            'extension_action' => \trim((string)$this->request->getGet('extension_action', '')),
            'extension_name' => \trim((string)$this->request->getGet('extension_name', '')),
        ];
    }

    private function isEmbeddedPanelRequest(): bool
    {
        $value = \strtolower(\trim((string)$this->request->getGet('embedded', '')));
        return \in_array($value, ['1', 'true', 'yes', 'wls_panel'], true);
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
        $value = \strtolower(\trim((string)($post['embedded'] ?? '')));
        return \in_array($value, ['1', 'true', 'yes', 'wls_panel'], true);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    private function contextFromInput(array $input): array
    {
        return [
            'operation' => \trim((string)($input['operation'] ?? 'php-profile')),
            'profile_key' => \trim((string)($input['profile_key'] ?? '')),
            'project_id' => \trim((string)($input['project_id'] ?? '')),
            'domain' => \trim((string)($input['domain'] ?? '')),
            'project_type' => \trim((string)($input['project_type'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $runtime
     * @param array<string, mixed> $projectProfile
     * @return array<int, array<string, string>>
     */
    private function capabilityCards(array $runtime, array $projectProfile): array
    {
        $extensions = \is_array($runtime['extensions'] ?? null) ? $runtime['extensions'] : [];
        return [
            [
                'label' => (string)__('Runtime PHP'),
                'value' => (string)($runtime['version'] ?? \PHP_VERSION),
                'detail' => (string)($runtime['sapi'] ?? \PHP_SAPI),
                'state' => 'ready',
            ],
            [
                'label' => (string)__('Extensions'),
                'value' => (string)\count($extensions),
                'detail' => (string)__('Loaded'),
                'state' => 'ready',
            ],
            [
                'label' => (string)__('Project Profile'),
                'value' => !empty($projectProfile['has_profile']) ? (string)__('Saved') : (string)__('Inherited'),
                'detail' => !empty($projectProfile['enabled']) ? (string)__('Enabled') : (string)__('Disabled'),
                'state' => !empty($projectProfile['has_profile']) ? 'saved' : 'inherited',
            ],
        ];
    }

    private function resolveNotice(string $code): string
    {
        return match ($code) {
            'profile_saved' => (string)__('Project PHP profile saved.'),
            'profile_saved_reload_requested' => (string)__('Project PHP profile saved and WLS reload was requested.'),
            'ini_applied' => (string)__('php.ini applied and backup created.'),
            'ini_apply_noop' => (string)__('No php.ini changes were needed.'),
            'ini_restored' => (string)__('php.ini restored from backup.'),
            'extension_executed' => (string)__('PHP extension operation completed and audit record was written.'),
            'extension_execute_noop' => (string)__('No PHP extension changes were needed.'),
            default => '',
        };
    }

    /**
     * @param array<string, string> $params
     */
    private function redirectToPhpManager(array $params, string $targetPage = ''): void
    {
        $pageKey = $this->normalizePageKey($targetPage !== '' ? \ltrim($targetPage, '#') : (string)($params['operation'] ?? 'php-profile'));
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
            $value = \trim((string)$value);
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
        $meta = \is_array($meta) ? $meta : [];
        $meta['showHeader'] = false;
        $meta['showSidebar'] = false;
        $meta['showFooter'] = false;
        $meta['showRightSidebar'] = false;
        $meta['showPageHeader'] = false;
        $meta['showMessages'] = false;
        $meta['class'] = \trim((string)($meta['class'] ?? '') . ' wls-php-manager-fullscreen');

        $this->assign('meta', $meta);
        $this->assign('layoutShowPageHeader', false);
        $this->assign('layoutShowMessages', false);
    }
}
