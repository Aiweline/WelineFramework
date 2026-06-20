<?php
declare(strict_types=1);

namespace Weline\PhpManager\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\PhpManager\Service\WlsPhpExtensionPlanService;
use Weline\PhpManager\Service\WlsPhpIniService;
use Weline\PhpManager\Service\WlsPhpProfileService;

#[Acl('Weline_PhpManager::wls_php_manager', 'WLS PHP Manager', 'mdi mdi-language-php', 'WLS Panel PHP profile entry', 'Weline_Backend::system_maintenance')]
class WlsPhpManager extends BackendController
{
    public function __construct(
        private readonly WlsPhpProfileService $profileService,
        private readonly WlsPhpIniService $iniService,
        private readonly WlsPhpExtensionPlanService $extensionPlanService
    ) {
    }

    #[Acl('Weline_PhpManager::wls_php_manager_index', 'View WLS PHP Manager', 'mdi mdi-language-php', 'View WLS Panel PHP profiles')]
    public function getIndex(): string
    {
        $this->useStandaloneLayout();

        $context = $this->requestContext();
        $runtime = $this->profileService->getRuntimeInfo();
        $projectProfile = $this->profileService->getFormData($context);
        $inheritanceMap = $this->profileService->getInheritanceMap($context);
        $iniPlan = $this->iniService->getIniPlan($context);
        $extensionPlan = $this->extensionPlanService->buildPlan($this->extensionPlanInput(), $runtime, $projectProfile);

        $this->assign('title', __('WLS PHP Manager'));
        $this->assign('page_title', __('WLS PHP Manager'));
        $this->assign('wlsPhpManagerContext', $context);
        $this->assign('wlsPhpManagerRuntime', $runtime);
        $this->assign('wlsPhpManagerProjectProfile', $projectProfile);
        $this->assign('wlsPhpManagerInheritanceMap', $inheritanceMap);
        $this->assign('wlsPhpManagerIniPlan', $iniPlan);
        $this->assign('wlsPhpManagerExtensionPlan', $extensionPlan);
        $this->assign('wlsPhpManagerAuditRecords', $this->profileService->getRecentAuditRecords());
        $this->assign('wlsPhpManagerCapabilities', $this->capabilityCards($runtime, $projectProfile));
        $this->assign('wlsPhpManagerNotice', $this->resolveNotice((string)$this->request->getGet('phpm_notice', '')));
        $this->assign('wlsPhpManagerError', \mb_substr(\trim((string)$this->request->getGet('phpm_error', '')), 0, 220));
        $this->assign('wlsPhpManagerProfileSaveUrl', $this->_url->getBackendUrl('weline_phpmanager/backend/wls-php-manager/profile-save'));
        $this->assign('wlsPhpManagerIniApplyUrl', $this->_url->getBackendUrl('weline_phpmanager/backend/wls-php-manager/ini-apply'));
        $this->assign('wlsPhpManagerIniRollbackUrl', $this->_url->getBackendUrl('weline_phpmanager/backend/wls-php-manager/ini-rollback'));

        return $this->fetch('index');
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
            default => '',
        };
    }

    /**
     * @param array<string, string> $params
     */
    private function redirectToPhpManager(array $params, string $fragment = ''): void
    {
        $cleanParams = [];
        foreach ($params as $key => $value) {
            $value = \trim((string)$value);
            if ($value !== '') {
                $cleanParams[$key] = $value;
            }
        }

        $url = $this->_url->getBackendUrl('weline_phpmanager/backend/wls-php-manager', $cleanParams);
        if ($fragment !== '') {
            $url .= $fragment;
        }
        $this->redirect($url);
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
