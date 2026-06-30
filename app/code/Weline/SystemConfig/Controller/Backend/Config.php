<?php
declare(strict_types=1);

namespace Weline\SystemConfig\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Backend\Model\BackendUser;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\SystemConfig\Service\SystemConfigCenterService;
use Weline\SystemConfig\Service\SystemConfigTemplateService;

#[Acl('Weline_SystemConfig::config_center', '统一配置中心', 'mdi-tune-variant', '统一配置中心', 'Weline_Backend::system_config_group')]
class Config extends BackendController
{
    #[Acl('Weline_SystemConfig::config_center_index', '查看统一配置中心', 'mdi-tune-variant', '查看统一配置中心')]
    public function getIndex(): string
    {
        $module = trim((string)$this->request->getGet('module', ''));
        $area = trim((string)$this->request->getGet('area', SystemConfig::area_BACKEND));
        $search = trim((string)$this->request->getGet('search', ''));
        $scope = trim((string)$this->request->getGet('scope', SystemConfig::SCOPE_GLOBAL));
        $locale = trim((string)$this->request->getGet('locale', SystemConfig::LOCALE_DEFAULT));

        /** @var SystemConfigTemplateService $templateService */
        $templateService = ObjectManager::getInstance(SystemConfigTemplateService::class);
        /** @var SystemConfig $systemConfig */
        $systemConfig = ObjectManager::getInstance(SystemConfig::class);
        /** @var SystemConfigCenterService $configCenterService */
        $configCenterService = ObjectManager::getInstance(SystemConfigCenterService::class);

        $normalizedScope = $systemConfig->normalizeScope($scope);
        $normalizedLocale = $systemConfig->normalizeLocale($locale);
        $selectedModule = $module !== '' ? $module : null;
        $selectedArea = $area !== '' ? $area : SystemConfig::area_BACKEND;
        $selectedSearch = $search !== '' ? $search : null;

        $modules = $templateService->getModules($selectedArea, $selectedSearch);
        $tree = $configCenterService->enrichTreeWithValues(
            $templateService->getTree($selectedModule, $selectedArea, $selectedSearch),
            $normalizedScope,
            $normalizedLocale
        );

        $this->assign('page_title', __('统一配置中心'));
        $this->assign('modules', $modules);
        $this->assign('tree', $tree);
        $this->assign('selected_module', $module);
        $this->assign('selected_area', $selectedArea);
        $this->assign('search', $search);
        $this->assign('scope', $normalizedScope);
        $this->assign('locale', $normalizedLocale);
        $this->assign('fallback_scopes', $systemConfig->getFallbackScopes($normalizedScope));
        $this->assign('post_url', $this->request->getUrlBuilder()->getBackendUrl('weline_systemconfig/backend/config'));

        return $this->fetch('Weline_SystemConfig::templates/backend/config/index.phtml');
    }

    #[Acl('Weline_SystemConfig::config_center_save', '保存统一配置', 'mdi-content-save-outline', '保存统一配置')]
    public function postIndex(): string
    {
        $action = trim((string)$this->request->getPost('form_action', 'save'));
        $module = trim((string)$this->request->getPost('module', ''));
        $area = trim((string)$this->request->getPost('area', SystemConfig::area_BACKEND));
        $code = trim((string)$this->request->getPost('code', ''));
        $scope = trim((string)$this->request->getPost('scope', SystemConfig::SCOPE_GLOBAL));
        $locale = trim((string)$this->request->getPost('locale', SystemConfig::LOCALE_DEFAULT));
        $search = trim((string)$this->request->getPost('search', ''));

        try {
            /** @var SystemConfigCenterService $configCenterService */
            $configCenterService = ObjectManager::getInstance(SystemConfigCenterService::class);
            if ($action === 'rollback') {
                $versionId = (int)$this->request->getPost('version_id', 0);
                $result = $configCenterService->rollbackTemplateConfigVersion($versionId, array_merge([
                    'module' => $module,
                    'area' => $area,
                    'code' => $code,
                    'scope' => $scope,
                    'locale' => $locale,
                ], $this->actorOptions()));
                if (!empty($result['success'])) {
                    $this->getMessageManager()->addSuccess(__('配置已回滚，回滚批次：%{1}', (string)($result['rollback_version_id'] ?? '')));
                } else {
                    $this->getMessageManager()->addError(__('配置回滚预检失败，当前配置未改变。'));
                }
            } else {
                $values = $this->request->getPost('values', []);
                $inheritKeys = $this->request->getPost('inherit_keys', []);
                $baseVersions = $this->request->getPost('base_versions', []);
                $result = $configCenterService->saveTemplateConfig(
                    module: $module,
                    area: $area,
                    code: $code,
                    values: is_array($values) ? $values : [],
                    inheritKeys: array_values(array_map('strval', is_array($inheritKeys) ? $inheritKeys : [])),
                    baseVersions: is_array($baseVersions) ? $baseVersions : [],
                    scope: $scope,
                    locale: $locale,
                    options: $this->actorOptions()
                );
                if (!empty($result['success'])) {
                    $this->getMessageManager()->addSuccess(__('配置已保存，版本批次：%{1}', (string)($result['version_id'] ?? '')));
                } else {
                    $this->getMessageManager()->addError((string)($result['message'] ?? __('配置保存失败，当前配置未改变。')));
                }
            }
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage());
        }

        $this->redirect($this->request->getUrlBuilder()->getBackendUrl('weline_systemconfig/backend/config', [
            'module' => $module,
            'area' => $area,
            'scope' => $scope,
            'locale' => $locale,
            'search' => $search,
        ]));

        return '';
    }

    #[Acl('Weline_SystemConfig::config_center_rollback_precheck', '配置回滚预检', 'mdi-restore-alert', '配置回滚预检')]
    public function getRollbackPrecheck(): string
    {
        try {
            /** @var SystemConfigCenterService $configCenterService */
            $configCenterService = ObjectManager::getInstance(SystemConfigCenterService::class);
            return $this->jsonResponse([
                'success' => true,
                'precheck' => $configCenterService->precheckTemplateConfigRollback(
                    (int)$this->request->getGet('version_id', 0),
                    [
                        'module' => trim((string)$this->request->getGet('module', '')),
                        'area' => trim((string)$this->request->getGet('area', SystemConfig::area_BACKEND)),
                        'code' => trim((string)$this->request->getGet('code', '')),
                        'scope' => trim((string)$this->request->getGet('scope', SystemConfig::SCOPE_GLOBAL)),
                        'locale' => trim((string)$this->request->getGet('locale', SystemConfig::LOCALE_DEFAULT)),
                    ]
                ),
            ]);
        } catch (\Throwable $throwable) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function actorOptions(): array
    {
        /** @var BackendUser|null $backendUser */
        $backendUser = $this->session->getUser();
        $actorId = $backendUser && (int)$backendUser->getId() ? (string)$backendUser->getId() : '';
        $actorName = $backendUser ? (string)($backendUser->getUsername() ?: $backendUser->getEmail() ?: $actorId) : '';
        $reason = trim((string)$this->request->getPost('reason', ''));

        return [
            'actor_id' => $actorId,
            'actor_name' => $actorName,
            'reason' => $reason,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(array $payload): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '{}';
    }
}
