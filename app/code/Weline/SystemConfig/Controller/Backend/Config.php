<?php
declare(strict_types=1);

namespace Weline\SystemConfig\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\SystemConfig\Service\SystemConfigCenterService;
use Weline\SystemConfig\Service\SystemConfigTemplateService;

#[Acl('Weline_SystemConfig::config_center', '统一配置中心', 'mdi-tune-variant', '统一配置中心', '')]
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
        $guideParams = $this->guideParams('get');

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

        $guideKeys = $this->normalizeGuideKeys((string)($guideParams['guide_key'] ?? ''));
        $guideLocate = trim((string)($guideParams['guide_locate'] ?? ''));
        if ($guideLocate === '' || ($guideKeys !== [] && !\in_array($guideLocate, $guideKeys, true))) {
            $guideLocate = $guideKeys[0] ?? '';
        }
        if ($guideLocate !== '') {
            $guideParams['guide_locate'] = $guideLocate;
        }
        $guideTargets = $this->resolveGuideTargets($guideKeys, $templateService, $selectedArea);

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
        $this->assign('guide_params', $guideParams);
        $this->assign('guide_key', (string)($guideParams['guide_key'] ?? ''));
        $this->assign('guide_keys', $guideKeys);
        $this->assign('guide_locate', $guideLocate);
        $this->assign('guide_targets', $guideTargets);
        $this->assign('guide_title', (string)($guideParams['guide_title'] ?? ''));
        $this->assign('guide_summary', (string)($guideParams['guide_summary'] ?? ''));
        $this->assign('guide_return', (string)($guideParams['guide_return'] ?? ''));
        $this->assign('guide_step', (string)($guideParams['guide_step'] ?? ''));
        $this->assign('guide', [
            'key' => (string)($guideParams['guide_key'] ?? ''),
            'keys' => $guideKeys,
            'locate' => $guideLocate,
            'targets' => $guideTargets,
            'title' => (string)($guideParams['guide_title'] ?? ''),
            'summary' => (string)($guideParams['guide_summary'] ?? ''),
            'return' => (string)($guideParams['guide_return'] ?? ''),
            'step' => (string)($guideParams['guide_step'] ?? ''),
        ]);

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
        $guideParams = $this->guideParams('post');

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

        $this->redirect($this->request->getUrlBuilder()->getBackendUrl('weline_systemconfig/backend/config', array_merge([
            'module' => $module,
            'area' => $area,
            'scope' => $scope,
            'locale' => $locale,
            'search' => $search,
        ], $guideParams)));

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
        $backendUser = $this->session->getUser();
        $actorId = $backendUser && \method_exists($backendUser, 'getId') && (int)$backendUser->getId()
            ? (string)$backendUser->getId()
            : '';
        $username = $backendUser && \method_exists($backendUser, 'getUsername')
            ? (string)$backendUser->getUsername()
            : '';
        $email = $backendUser && \method_exists($backendUser, 'getEmail')
            ? (string)$backendUser->getEmail()
            : '';
        $actorName = $username !== '' ? $username : ($email !== '' ? $email : $actorId);
        $reason = trim((string)$this->request->getPost('reason', ''));

        return [
            'actor_id' => $actorId,
            'actor_name' => $actorName,
            'reason' => $reason,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function guideParams(string $source): array
    {
        $params = [];
        foreach ([
            'guide_key' => 2000,
            'guide_keys' => 2000,
            'guide_locate' => 240,
            'guide_title' => 120,
            'guide_summary' => 360,
            'guide_return' => 800,
            'guide_step' => 32,
        ] as $name => $limit) {
            $value = trim((string)($source === 'post'
                ? $this->request->getPost($name, '')
                : $this->request->getGet($name, '')));
            if ($value === '') {
                continue;
            }
            // 登录 return_url / 多次跳转可能反复 encode，先还原再截断
            $value = $this->decodeGuideValue($value);
            $value = mb_substr($value, 0, max(1, (int)$limit));
            if ($name === 'guide_return') {
                $value = $this->safeGuideReturnUrl($value);
            }
            if ($value !== '') {
                $params[$name] = $value;
            }
        }

        if (empty($params['guide_key'])) {
            $highlight = trim((string)($source === 'post'
                ? $this->request->getPost('highlight', '')
                : $this->request->getGet('highlight', '')));
            if ($highlight !== '') {
                $params['guide_key'] = mb_substr($this->decodeGuideValue($highlight), 0, 2000);
            }
        }

        // 合并 guide_keys 与 guide_key，统一为逗号分隔的多目标列表
        $mergedKeys = $this->normalizeGuideKeys(
            (string)($params['guide_key'] ?? ''),
            (string)($params['guide_keys'] ?? '')
        );
        unset($params['guide_keys']);
        if ($mergedKeys !== []) {
            $params['guide_key'] = \implode(',', $mergedKeys);
            $locate = $this->decodeGuideValue(trim((string)($params['guide_locate'] ?? '')));
            if ($locate === '' || !\in_array($locate, $mergedKeys, true)) {
                $params['guide_locate'] = $mergedKeys[0];
            } else {
                $params['guide_locate'] = $locate;
            }
        } else {
            unset($params['guide_key'], $params['guide_locate']);
        }

        return $params;
    }

    /**
     * 还原被多次 urlencode 的引导参数（最多 5 次），避免页面显示 %2F / %E9... 乱码。
     */
    private function decodeGuideValue(string $value): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }

        for ($i = 0; $i < 5; $i++) {
            if (!\preg_match('/%[0-9A-Fa-f]{2}/', $value)) {
                break;
            }
            $decoded = \rawurldecode($value);
            if ($decoded === $value) {
                $decoded = \urldecode($value);
            }
            if ($decoded === $value) {
                break;
            }
            $value = $decoded;
        }

        return \trim($value);
    }

    /**
     * @return list<string>
     */
    private function normalizeGuideKeys(string ...$rawParts): array
    {
        $keys = [];
        foreach ($rawParts as $raw) {
            $raw = $this->decodeGuideValue($raw);
            if ($raw === '') {
                continue;
            }
            foreach (\preg_split('/[\s,|;]+/', $raw) ?: [] as $part) {
                $part = $this->decodeGuideValue(\trim((string)$part));
                if ($part === '' || \in_array($part, $keys, true)) {
                    continue;
                }
                $keys[] = mb_substr($part, 0, 240);
            }
        }

        return $keys;
    }

    /**
     * @param list<string> $keys
     * @return list<array{index:int,key:string,label:string,module:string,area:string,code:string,found:bool}>
     */
    private function resolveGuideTargets(
        array $keys,
        SystemConfigTemplateService $templateService,
        string $preferredArea
    ): array {
        if ($keys === []) {
            return [];
        }

        $lookup = [];
        $areas = \array_values(\array_unique(\array_filter([
            $preferredArea,
            SystemConfig::area_BACKEND,
            SystemConfig::area_FRONTEND,
        ], static fn(string $area): bool => $area !== '')));

        foreach ($areas as $area) {
            $tree = $templateService->getTree(null, $area, null);
            foreach (($tree['modules'] ?? []) as $moduleRow) {
                $moduleName = (string)($moduleRow['module'] ?? '');
                foreach (($moduleRow['areas'] ?? []) as $areaRow) {
                    $areaName = (string)($areaRow['area'] ?? $area);
                    foreach (($areaRow['templates'] ?? []) as $template) {
                        $code = (string)($template['code'] ?? '');
                        foreach (($template['fields'] ?? []) as $field) {
                            $fieldKey = (string)($field['key'] ?? '');
                            if ($fieldKey === '' || isset($lookup[$fieldKey])) {
                                continue;
                            }
                            $lookup[$fieldKey] = [
                                'key' => $fieldKey,
                                'label' => (string)($field['label'] ?? $fieldKey),
                                'module' => $moduleName,
                                'area' => $areaName,
                                'code' => $code,
                                'found' => true,
                            ];
                        }
                    }
                }
            }
        }

        $targets = [];
        foreach ($keys as $index => $key) {
            if (isset($lookup[$key])) {
                $targets[] = \array_merge($lookup[$key], ['index' => (int)$index]);
                continue;
            }
            $targets[] = [
                'index' => (int)$index,
                'key' => $key,
                'label' => $key,
                'module' => '',
                'area' => $preferredArea,
                'code' => '',
                'found' => false,
            ];
        }

        return $targets;
    }

    private function safeGuideReturnUrl(string $url): string
    {
        $url = trim($url);
        if ($url === ''
            || preg_match('/[\x00-\x1F\x7F]/', $url)
            || preg_match('/^\s*(javascript|data|vbscript):/i', $url)
            || str_starts_with($url, '//')
        ) {
            return '';
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($scheme === '' && $host === '') {
            return preg_match('#^(/(?!/)|[?#])#', $url) ? $url : '';
        }
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return '';
        }

        $currentHost = (string)($this->request->getServer('HTTP_HOST') ?: $this->request->getServer('SERVER_NAME') ?: '');
        $currentParts = parse_url('http://' . ltrim($currentHost, '/'));
        if (!is_array($currentParts)) {
            return '';
        }

        $expectedHost = strtolower((string)($currentParts['host'] ?? ''));
        if ($expectedHost === '' || $host !== $expectedHost) {
            return '';
        }

        $expectedPort = (int)($currentParts['port'] ?? 0);
        $actualPort = (int)($parts['port'] ?? 0);
        if ($expectedPort > 0 && $actualPort > 0 && $expectedPort !== $actualPort) {
            return '';
        }

        return $url;
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
