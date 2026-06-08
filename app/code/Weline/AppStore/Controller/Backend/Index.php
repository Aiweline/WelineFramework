<?php
declare(strict_types=1);

namespace Weline\AppStore\Controller\Backend;

use Weline\AppStore\Model\AppStoreInstalledModule;
use Weline\AppStore\Service\AccountBindService;
use Weline\AppStore\Service\ModuleInstallerService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;

#[Acl('Weline_AppStore::index', '商城首页', 'bi-bag', '应用商城首页', 'Weline_AppStore::appstore')]
class Index extends BackendController
{
    #[Acl('Weline_AppStore::index_view', '查看商城', 'bi-house', '查看应用商城')]
    public function index(): string
    {
        /** @var AccountBindService $accountService */
        $accountService = ObjectManager::getInstance(AccountBindService::class);

        $isBound = $accountService->isBound();
        $account = $accountService->getCurrentAccount();
        $searchQuery = trim((string)$this->request->getGet('q', ''));
        $pricingFilter = trim((string)$this->request->getGet('pricing', ''));
        if (!in_array($pricingFilter, ['', 'free', 'paid'], true)) {
            $pricingFilter = '';
        }

        $moduleResult = $isBound
            ? $this->loadPlatformModules($accountService, [
                'q' => $searchQuery,
                'pricing' => $pricingFilter,
            ])
            : ['items' => [], 'error' => ''];

        $this->assign('is_bound', $isBound);
        $this->assign('account', $account);
        $this->assign('modules', $moduleResult['items']);
        $this->assign('store_error', $moduleResult['error']);
        $this->assign('search_query', $searchQuery);
        $this->assign('pricing_filter', $pricingFilter);
        $this->assign('platform_url', $accountService->getPlatformUrl());
        $this->assign('store_domain', $this->getCurrentDomain());
        $this->assign('account_url', $this->request->getUrlBuilder()->getBackendUrl('appstore/backend/account'));
        $this->assign('installed_url', $this->request->getUrlBuilder()->getBackendUrl('appstore/backend/installed'));
        $this->assign('index_url', $this->request->getUrlBuilder()->getBackendUrl('appstore/backend/index'));
        $this->assign('download_action', $this->request->getUrlBuilder()->getBackendUrl('appstore/backend/index/download'));
        $this->assign('install_action', $this->request->getUrlBuilder()->getBackendUrl('appstore/backend/index/install'));
        $this->assign('authorize_install_url', $this->request->getUrlBuilder()->getBackendUrl('appstore/backend/index/authorize-install'));
        $this->assign('title', __('应用商城'));
        $this->assign('page_title', __('应用商城'));

        return $this->fetch('Weline_AppStore::templates/Backend/Index/index.phtml');
    }

    #[Acl('Weline_AppStore::index_download', '下载模块', 'bi-cloud-download', '从官网商城下载模块包')]
    public function download(): string
    {
        if (!$this->request->isPost()) {
            return $this->index();
        }

        try {
            $licenseKey = trim((string)$this->request->getPost('license_key', ''));
            $version = trim((string)$this->request->getPost('version', ''));
            $moduleId = (int)$this->request->getPost('module_id', 0);

            if ($licenseKey === '' || $moduleId <= 0) {
                throw new Exception(__('缺少许可证或模块 ID'));
            }

            /** @var ModuleInstallerService $installer */
            $installer = ObjectManager::getInstance(ModuleInstallerService::class);
            $result = $installer->downloadForDomain($this->getCurrentDomain(), $licenseKey, $moduleId, $version !== '' ? $version : null);
            $result['message'] = __('模块已下载到临时目录');

            $this->assign('download_result', $result);
        } catch (\Throwable $e) {
            $this->assign('download_result', [
                'success' => false,
                'message' => __('模块下载失败：') . $e->getMessage(),
            ]);
        }

        return $this->index();
    }

    #[Acl('Weline_AppStore::index_list', '模块列表', 'bi-list', '获取模块列表')]
    public function list(): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');

        try {
            /** @var AccountBindService $accountService */
            $accountService = ObjectManager::getInstance(AccountBindService::class);

            if (!$accountService->isBound()) {
                return $this->jsonResponse(false, __('请先绑定官网账户'));
            }

            $token = $accountService->getApiToken();
            if (!$token) {
                return $this->jsonResponse(false, __('获取授权令牌失败'));
            }

            $client = new \GuzzleHttp\Client($accountService->getHttpClientOptions());
            $response = $client->post(
                $accountService->getPlatformApiUrl('/api/v1/platform/module/list'),
                [
                    'headers' => ['Authorization' => 'Bearer ' . $token],
                    'json' => array_merge($this->request->getPost(), [
                        'domain' => $this->getCurrentDomain(),
                    ]),
                ]
            );

            return $response->getBody()->getContents();
        } catch (\Throwable $e) {
            return $this->jsonResponse(false, __('获取模块列表失败：') . $e->getMessage());
        }
    }

    #[Acl('Weline_AppStore::index_authorize_install', '安装授权', 'bi-shield-check', '安装前确认应用权限')]
    public function authorizeInstall(): string
    {
        $moduleId = (int)($this->request->getParam('module_id', 0) ?: $this->request->getPost('module_id', 0));
        $version = trim((string)($this->request->getParam('version', '') ?: $this->request->getPost('version', '')));

        try {
            if ($moduleId <= 0) {
                throw new Exception(__('缺少模块 ID'));
            }

            $module = $this->loadLicensedPlatformModule($moduleId);
            $versionCode = $version !== ''
                ? $version
                : (string)($module['current_version'] ?? ($module['version']['version'] ?? ''));
            $installedState = $this->getInstalledState($module, $versionCode);

            $this->assign('module', $module);
            $this->assign('required_permissions', $this->getRequiredPermissions($module));
            $this->assign('license_key', (string)($module['license_key'] ?? ''));
            $this->assign('version', $versionCode);
            $this->assign('installed_state', $installedState);
        } catch (\Throwable $e) {
            $this->assign('authorize_error', $e->getMessage());
            $this->assign('module', []);
            $this->assign('required_permissions', []);
            $this->assign('license_key', '');
            $this->assign('version', $version);
            $this->assign('installed_state', [
                'is_installed' => false,
                'installed_version' => '',
                'update_available' => false,
            ]);
        }

        $this->assign('install_action', $this->request->getUrlBuilder()->getBackendUrl('appstore/backend/index/install'));
        $this->assign('store_url', $this->request->getUrlBuilder()->getBackendUrl('appstore/backend/index'));
        $this->assign('installed_url', $this->request->getUrlBuilder()->getBackendUrl('appstore/backend/installed'));
        $this->assign('store_domain', $this->getCurrentDomain());
        $this->assign('title', __('安装授权'));
        $this->assign('page_title', __('安装授权'));

        return $this->fetch('Weline_AppStore::templates/Backend/Index/authorize-install.phtml');
    }

    #[Acl('Weline_AppStore::index_install', '安装模块', 'bi-download', '下载并安装模块')]
    public function install(): string
    {
        if (!$this->request->isPost()) {
            return $this->index();
        }

        try {
            $licenseKey = trim((string)$this->request->getPost('license_key', ''));
            $version = trim((string)$this->request->getPost('version', ''));
            $moduleId = (int)$this->request->getPost('module_id', 0);

            if ($licenseKey === '') {
                throw new Exception(__('缺少许可证密钥'));
            }
            if ($moduleId <= 0) {
                throw new Exception(__('缺少模块 ID'));
            }

            $module = $this->loadLicensedPlatformModule($moduleId);
            $installedState = $this->getInstalledState($module, $version);
            if (!empty($installedState['is_installed'])) {
                $this->assign('install_result', [
                    'success' => true,
                    'message' => !empty($installedState['update_available'])
                        ? __('该应用已安装旧版本，请进入我的模块执行更新。')
                        : __('该应用已安装，无需重复安装。'),
                ]);
                return $this->index();
            }

            if (!$this->hasPermissionConsent($this->getRequiredPermissions($module))) {
                $this->assign('authorize_error', __('请勾选并授权应用所需权限后再安装。'));
                return $this->authorizeInstall();
            }

            /** @var ModuleInstallerService $installer */
            $installer = ObjectManager::getInstance(ModuleInstallerService::class);
            $downloadResult = $installer->downloadForDomain($this->getCurrentDomain(), $licenseKey, $moduleId, $version !== '' ? $version : null);
            $moduleInfo = $downloadResult['module_info'] ?? [];
            $platformModuleId = (int)($moduleInfo['module_id'] ?? ($moduleInfo['id'] ?? $moduleId));

            $installOptions = [
                'license_key' => $licenseKey,
                'platform_module_id' => $platformModuleId,
                'download_log_id' => (int)($downloadResult['log_id'] ?? 0),
                'download_file_hash' => (string)($downloadResult['file_hash'] ?? ''),
                'download_file_size' => (int)($downloadResult['file_size'] ?? 0),
                'bound_domain' => (string)($downloadResult['download_domain'] ?? $this->getCurrentDomain()),
            ];
            if (!empty($moduleInfo['display_name'])) {
                $installOptions['display_name'] = (string)$moduleInfo['display_name'];
            }
            if (!empty($moduleInfo['description'])) {
                $installOptions['description'] = (string)$moduleInfo['description'];
            }

            $installResult = $installer->install($downloadResult['file_path'], $installOptions);
            if (!empty($installResult['success'])) {
                $installResult['message'] = __('模块已下载安装，功能页面已生成');
                $this->assign('install_result', $installResult);
                return $this->index();
            }

            $this->assign('install_result', [
                'success' => false,
                'message' => __('模块安装失败：') . ($installResult['message'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            $this->assign('install_result', [
                'success' => false,
                'message' => __('安装失败：') . $e->getMessage(),
            ]);
        }

        return $this->index();
    }

    private function jsonResponse(bool $success, string $message, array $data = []): string
    {
        return json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, error: string}
     */
    private function loadPlatformModules(AccountBindService $accountService, array $filters = []): array
    {
        try {
            $token = $accountService->getApiToken();
            if (!$token) {
                return [
                    'items' => [],
                    'error' => (string)__('授权令牌无效，请重新授权官网账户'),
                ];
            }

            $payload = [
                'page_size' => 50,
                'domain' => $this->getCurrentDomain(),
            ];
            if (!empty($filters['q'])) {
                $payload['q'] = (string)$filters['q'];
            }
            if (!empty($filters['pricing'])) {
                $payload['pricing'] = (string)$filters['pricing'];
            }

            $client = new \GuzzleHttp\Client($accountService->getHttpClientOptions(['timeout' => 30]));
            $response = $client->post(
                $accountService->getPlatformApiUrl('/api/v1/platform/module/list'),
                [
                    'headers' => ['Authorization' => 'Bearer ' . $token],
                    'json' => $payload,
                ]
            );

            $data = json_decode($response->getBody()->getContents(), true);
            if (!is_array($data) || empty($data['success'])) {
                return [
                    'items' => [],
                    'error' => (string)($data['message'] ?? __('获取模块列表失败')),
                ];
            }

            $items = $data['data']['items'] ?? [];
            return [
                'items' => is_array($items) ? $this->appendInstalledState($items) : [],
                'error' => '',
            ];
        } catch (\Throwable $e) {
            return [
                'items' => [],
                'error' => __('获取模块列表失败：') . $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadLicensedPlatformModule(int $moduleId): array
    {
        /** @var AccountBindService $accountService */
        $accountService = ObjectManager::getInstance(AccountBindService::class);
        if (!$accountService->isBound()) {
            throw new Exception(__('请先绑定官网账户'));
        }

        $result = $this->loadPlatformModules($accountService);
        if ($result['error'] !== '') {
            throw new Exception((string)$result['error']);
        }

        foreach ($result['items'] as $item) {
            if (!is_array($item) || (int)($item['module_id'] ?? 0) !== $moduleId) {
                continue;
            }
            if (empty($item['license_key'])) {
                throw new Exception(__('当前账户还没有该应用的许可证，请先在官网获取或购买。'));
            }

            return $item;
        }

        throw new Exception(__('未找到可安装的应用或应用尚未发布。'));
    }

    /**
     * @param array<string, mixed> $module
     * @return array<int, array<string, mixed>>
     */
    private function getRequiredPermissions(array $module): array
    {
        $items = $module['required_permissions'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $permissions = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                $code = trim($item);
                if ($code !== '') {
                    $permissions[] = [
                        'code' => $code,
                        'label' => $code,
                        'description' => '',
                        'required' => true,
                    ];
                }
                continue;
            }
            if (!is_array($item)) {
                continue;
            }

            $code = trim((string)($item['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $permissions[] = [
                'code' => $code,
                'label' => trim((string)($item['label'] ?? $code)),
                'description' => trim((string)($item['description'] ?? '')),
                'required' => !array_key_exists('required', $item) || (bool)$item['required'],
            ];
        }

        return $permissions;
    }

    /**
     * @param array<int, array<string, mixed>> $permissions
     */
    private function hasPermissionConsent(array $permissions): bool
    {
        if (!$permissions) {
            return true;
        }
        if ((string)$this->request->getPost('permission_consent', '') !== '1') {
            return false;
        }

        $accepted = $this->request->getPost('permissions', []);
        if (!is_array($accepted)) {
            $accepted = [$accepted];
        }
        $acceptedCodes = array_flip(array_map('strval', $accepted));
        foreach ($permissions as $permission) {
            $code = (string)($permission['code'] ?? '');
            $required = !array_key_exists('required', $permission) || (bool)$permission['required'];
            if ($required && ($code === '' || !isset($acceptedCodes[$code]))) {
                return false;
            }
        }

        return true;
    }

    private function getCurrentDomain(): string
    {
        $requestHost = $this->normalizeDomain((string)$this->request->getServer('HTTP_HOST', ''));
        if ($requestHost !== '' && !$this->isLoopbackDomain($requestHost)) {
            return $requestHost;
        }

        $serverHost = $this->normalizeDomain((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($serverHost !== '' && !$this->isLoopbackDomain($serverHost)) {
            return $serverHost;
        }

        $boundDomain = $this->getBoundAccountDomain();
        if ($boundDomain !== '') {
            return $boundDomain;
        }

        if ($requestHost !== '') {
            return $requestHost;
        }

        if ($serverHost !== '') {
            return $serverHost;
        }

        $envHost = $this->normalizeDomain((string)\w_env('server.http_host', ''));
        return $envHost !== '' ? $envHost : 'localhost';
    }

    private function getBoundAccountDomain(): string
    {
        try {
            /** @var AccountBindService $accountService */
            $accountService = ObjectManager::getInstance(AccountBindService::class);
            $account = $accountService->getCurrentAccount();

            return $this->normalizeDomain((string)($account?->getBoundDomain() ?? ''));
        } catch (\Throwable) {
            return '';
        }
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = trim($domain);
        if ($domain === '') {
            return '';
        }

        $parsedHost = parse_url($domain, PHP_URL_HOST);
        if (is_string($parsedHost) && $parsedHost !== '') {
            $port = parse_url($domain, PHP_URL_PORT);
            return $port ? $parsedHost . ':' . $port : $parsedHost;
        }

        $pathStart = strpos($domain, '/');
        if ($pathStart !== false) {
            $domain = substr($domain, 0, $pathStart);
        }

        return trim($domain);
    }

    private function isLoopbackDomain(string $domain): bool
    {
        $host = strtolower($domain);
        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');
            if ($end !== false) {
                $host = substr($host, 1, $end - 1);
            }
        } elseif (substr_count($host, ':') === 1) {
            $host = strstr($host, ':', true) ?: $host;
        }

        return in_array($host, ['127.0.0.1', 'localhost', '::1', '0.0.0.0'], true);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function appendInstalledState(array $items): array
    {
        /** @var AppStoreInstalledModule $installedModule */
        $installedModule = ObjectManager::getInstance(AppStoreInstalledModule::class);
        $rows = (array)$installedModule->clear()
            ->select()
            ->fetchArray();

        $byPlatformId = [];
        $byModuleName = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $moduleName = (string)($row[AppStoreInstalledModule::schema_fields_module_name] ?? '');
            if ($moduleName === '' || !$this->isInstalledModulePresent($moduleName)) {
                continue;
            }

            $platformModuleId = (int)($row[AppStoreInstalledModule::schema_fields_platform_module_id] ?? 0);
            if ($platformModuleId > 0) {
                $byPlatformId[$platformModuleId] = $row;
            }
            $byModuleName[$moduleName] = $row;
        }

        foreach ($items as &$item) {
            if (!is_array($item)) {
                $item = [];
                continue;
            }

            $platformModuleId = (int)($item['module_id'] ?? 0);
            $moduleName = (string)($item['name'] ?? '');
            $installed = $byPlatformId[$platformModuleId] ?? ($byModuleName[$moduleName] ?? null);
            $item['is_installed'] = is_array($installed);
            if (!is_array($installed)) {
                continue;
            }

            $item['installed_version'] = (string)($installed[AppStoreInstalledModule::schema_fields_version] ?? '');
            $item['installed_at'] = (string)($installed[AppStoreInstalledModule::schema_fields_installed_at] ?? '');
            $item['install_id'] = (int)($installed[AppStoreInstalledModule::schema_fields_ID] ?? 0);
            $currentVersion = (string)($item['current_version'] ?? ($item['version']['version'] ?? ''));
            $item['update_available'] = $currentVersion !== ''
                && $item['installed_version'] !== ''
                && version_compare($item['installed_version'], $currentVersion, '<');
        }
        unset($item);

        return $items;
    }

    /**
     * @param array<string, mixed> $module
     * @return array{is_installed: bool, installed_version: string, update_available: bool}
     */
    private function getInstalledState(array $module, string $targetVersion = ''): array
    {
        $installedVersion = (string)($module['installed_version'] ?? '');
        $isInstalled = !empty($module['is_installed']);
        $targetVersion = trim($targetVersion);
        if ($targetVersion === '') {
            $targetVersion = (string)($module['current_version'] ?? ($module['version']['version'] ?? ''));
        }

        return [
            'is_installed' => $isInstalled,
            'installed_version' => $installedVersion,
            'update_available' => $isInstalled
                && $targetVersion !== ''
                && version_compare($installedVersion, $targetVersion, '<'),
        ];
    }

    private function isInstalledModulePresent(string $moduleName): bool
    {
        if (!str_contains($moduleName, '_')) {
            return false;
        }

        [$vendor, $moduleDir] = explode('_', $moduleName, 2);
        return is_dir(rtrim(APP_CODE_PATH, DS) . DS . $vendor . DS . $moduleDir);
    }
}
