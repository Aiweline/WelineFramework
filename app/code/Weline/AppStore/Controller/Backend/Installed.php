<?php
declare(strict_types=1);

namespace Weline\AppStore\Controller\Backend;

use GuzzleHttp\Client;
use Weline\AppStore\Model\AppStoreInstalledModule;
use Weline\AppStore\Service\AccountBindService;
use Weline\AppStore\Service\ModuleUpdateService;
use Weline\AppStore\Service\ModuleUninstallService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;

#[Acl('Weline_AppStore::installed', '我的模块', 'bi-puzzle', 'App 商城安装来源记录', 'Weline_AppStore::appstore')]
class Installed extends BackendController
{
    #[Acl('Weline_AppStore::installed_view', '查看模块', 'bi-list', '查看 App 商城安装来源记录')]
    public function index(): string
    {
        /** @var AppStoreInstalledModule $moduleModel */
        $moduleModel = ObjectManager::getInstance(AppStoreInstalledModule::class);

        $moduleModel->reset()
            ->order('installed_at', 'DESC')
            ->select()
            ->fetch();
        $modules = $this->recoverFileOnlyInstalledModules($moduleModel->getItems());
        $updateResult = $this->loadUpdateIndex($modules);

        $this->assign('modules', $modules);
        $this->assign('update_index', $updateResult['updates']);
        $this->assign('update_error', $updateResult['error']);
        $this->assign('system_modules', Env::getInstance()->getModuleList(true));
        $this->assign('check_update_action', $this->request->getUrlBuilder()->getBackendUrl('appstore/backend/installed/checkUpdate'));
        $this->assign('update_action', $this->request->getUrlBuilder()->getBackendUrl('appstore/backend/installed/update'));
        $this->assign('uninstall_action', $this->request->getUrlBuilder()->getBackendUrl('appstore/backend/installed/uninstall'));
        $this->assign('review_action', $this->request->getUrlBuilder()->getBackendUrl('appstore/backend/installed/submit-review'));
        $this->assign('latest_uninstall_record', $this->loadLatestUninstallRecord());
        $this->assign('page_title', __('我的模块'));

        return $this->fetch('Weline_AppStore::templates/Backend/Installed/index.phtml');
    }

    #[Acl('Weline_AppStore::installed_detail', '模块详情', 'bi-info-circle', '查看模块详情')]
    public function detail(): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');

        $installId = $this->request->getGet('id');
        if (!$installId) {
            return $this->jsonResponse(false, __('缺少模块 ID'));
        }

        /** @var AppStoreInstalledModule $moduleModel */
        $moduleModel = ObjectManager::getInstance(AppStoreInstalledModule::class);
        $module = $moduleModel->load($installId);

        if (!$module->getInstallId()) {
            return $this->jsonResponse(false, __('模块不存在'));
        }

        return $this->jsonResponse(true, '', ['module' => $module->getData()]);
    }

    #[Acl('Weline_AppStore::installed_uninstall', '系统卸载提示', 'bi-terminal', '提示使用系统模块卸载')]
    public function uninstall(): string
    {
        if ($this->request->isPost()) {
            return $this->performUninstall();
        }

        $this->request->getResponse()->setHeader('Content-Type', 'application/json');

        $installId = $this->request->getPost('id');
        $moduleName = trim((string)$this->request->getPost('module_name', ''));
        if (!$installId && $moduleName === '') {
            return $this->jsonResponse(false, __('缺少模块标识'));
        }

        try {
            /** @var AppStoreInstalledModule $moduleModel */
            $moduleModel = ObjectManager::getInstance(AppStoreInstalledModule::class);
            if ($installId) {
                $moduleModel->load($installId);
            } else {
                $moduleModel->load($moduleName, AppStoreInstalledModule::schema_fields_module_name);
            }

            if (!$moduleModel->getInstallId()) {
                return $this->jsonResponse(false, __('模块不存在'));
            }

            $moduleName = $moduleModel->getModuleName();
            return $this->jsonResponse(false, __('App 商城不执行卸载。请使用系统模块卸载流程，系统会自动执行卸载数据备份。'), [
                'module_name' => $moduleName,
                'command' => 'php bin/w module:remove ' . $moduleName,
            ]);
        } catch (\Throwable $e) {
            return $this->jsonResponse(false, __('获取系统卸载提示失败：') . $e->getMessage());
        }
    }

    #[Acl('Weline_AppStore::installed_check_update', '检查更新', 'bi-arrow-repeat', '检查模块更新')]
    public function checkUpdate(): string
    {
        if ((int)$this->request->getPost('install_id', 0) > 0) {
            return $this->performUpdate();
        }

        $this->assign('check_update_result', [
            'success' => true,
            'message' => __('已重新检查平台更新。'),
        ]);

        return $this->index();
    }

    #[Acl('Weline_AppStore::installed_update', '更新模块', 'bi-cloud-arrow-down', '下载并升级已安装模块')]
    public function update(): string
    {
        return $this->performUpdate();
    }

    #[Acl('Weline_AppStore::installed_update', '鏇存柊妯″潡', 'bi-cloud-arrow-down', '涓嬭浇骞跺崌绾у凡瀹夎妯″潡')]
    public function postUpdate(): string
    {
        return $this->performUpdate();
    }

    #[Acl('Weline_AppStore::installed_submit_review', '提交评价', 'bi-star', '从终端提交应用评分和评价')]
    public function submitReview(): string
    {
        if (!$this->request->isPost()) {
            return $this->index();
        }

        try {
            $moduleId = (int)$this->request->getPost('platform_module_id', 0);
            $rating = (int)$this->request->getPost('rating', 5);
            $content = trim((string)$this->request->getPost('content', ''));
            if ($moduleId <= 0) {
                throw new \Weline\Framework\App\Exception(__('缺少平台应用 ID'));
            }

            /** @var AccountBindService $accountService */
            $accountService = ObjectManager::getInstance(AccountBindService::class);
            if (!$accountService->isBound()) {
                throw new \Weline\Framework\App\Exception(__('请先绑定官网账户'));
            }

            $token = $accountService->getApiToken();
            if (!$token) {
                throw new \Weline\Framework\App\Exception(__('获取授权令牌失败，请重新绑定官网账户'));
            }

            $client = new Client(['timeout' => 30]);
            $response = $client->post(
                $accountService->getPlatformApiUrl('/api/v1/platform/review/submit'),
                [
                    'http_errors' => false,
                    'headers' => ['Authorization' => 'Bearer ' . $token],
                    'json' => [
                        'module_id' => $moduleId,
                        'rating' => max(1, min(5, $rating)),
                        'content' => $content,
                        'domain' => $this->getCurrentDomain(),
                    ],
                ]
            );
            $data = json_decode($response->getBody()->getContents(), true);
            if ($response->getStatusCode() >= 400 || !is_array($data) || empty($data['success'])) {
                throw new \Weline\Framework\App\Exception((string)($data['message'] ?? __('评价提交失败')));
            }

            $this->assign('review_result', [
                'success' => true,
                'message' => __('评价已提交，官网评分已更新。'),
            ]);
        } catch (\Throwable $e) {
            $this->assign('review_result', [
                'success' => false,
                'message' => __('评价提交失败：') . $e->getMessage(),
            ]);
        }

        return $this->index();
    }

    #[Acl('Weline_AppStore::installed_uninstall', '卸载模块', 'bi-trash', '通过系统卸载流程卸载 App 商城模块')]
    public function postUninstall(): string
    {
        return $this->performUninstall();
    }

    private function performUninstall(): string
    {
        try {
            $installId = (int)$this->request->getPost('install_id', 0);
            if ($installId <= 0) {
                throw new \Weline\Framework\App\Exception(__('缺少安装记录 ID'));
            }

            /** @var AppStoreInstalledModule $moduleModel */
            $moduleModel = ObjectManager::getInstance(AppStoreInstalledModule::class);
            $moduleModel->load($installId);
            if (!$moduleModel->getInstallId()) {
                throw new \Weline\Framework\App\Exception(__('模块不存在'));
            }

            /** @var ModuleUninstallService $uninstallService */
            $uninstallService = ObjectManager::getInstance(ModuleUninstallService::class);
            $this->assign('uninstall_result', $uninstallService->uninstall($moduleModel));
        } catch (\Throwable $e) {
            $this->assign('uninstall_result', [
                'success' => false,
                'message' => __('卸载失败：') . $e->getMessage(),
            ]);
        }

        return $this->index();
    }

    private function performUpdate(): string
    {
        if (!$this->request->isPost()) {
            $this->assign('update_result', [
                'success' => false,
                'message' => __('无效的请求方法'),
            ]);

            return $this->index();
        }

        try {
            $installId = (int)$this->request->getPost('install_id', 0);
            if ($installId <= 0) {
                throw new \Weline\Framework\App\Exception(__('缺少安装记录 ID'));
            }

            /** @var AppStoreInstalledModule $moduleModel */
            $moduleModel = ObjectManager::getInstance(AppStoreInstalledModule::class);
            $module = $moduleModel->load($installId);
            if (!$module->getInstallId()) {
                throw new \Weline\Framework\App\Exception(__('模块不存在'));
            }

            $updateResult = $this->loadUpdateIndex([$module]);
            if ($updateResult['error'] !== '') {
                throw new \Weline\Framework\App\Exception($updateResult['error']);
            }

            $moduleName = $module->getModuleName();
            $update = $updateResult['updates'][$moduleName] ?? [];
            if (empty($update['update_available'])) {
                throw new \Weline\Framework\App\Exception(__('当前模块已是最新版本'));
            }

            /** @var ModuleUpdateService $updateService */
            $updateService = ObjectManager::getInstance(ModuleUpdateService::class);
            $result = $updateService->update($module, $update, $this->getCurrentDomain());
            $this->assign('update_result', $result);
        } catch (\Throwable $e) {
            $this->assign('update_result', [
                'success' => false,
                'message' => __('更新失败：') . $e->getMessage(),
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

    private function recoverFileOnlyInstalledModules(array $modules): array
    {
        $existing = [];
        foreach ($modules as $module) {
            $data = is_object($module) && method_exists($module, 'getData') ? $module->getData() : (array)$module;
            $moduleName = (string)($data[AppStoreInstalledModule::schema_fields_module_name] ?? $data['module_name'] ?? '');
            if ($moduleName !== '') {
                $existing[$moduleName] = true;
            }
        }

        $recovered = false;
        foreach ($this->discoverMarketplaceModuleDirectories() as $moduleDir) {
            $metadata = $this->parseRegisterMetadata($moduleDir . DS . 'register.php');
            $moduleName = $metadata['module_name'];
            if ($moduleName === '' || isset($existing[$moduleName])) {
                continue;
            }

            /** @var AppStoreInstalledModule $installedModule */
            $installedModule = ObjectManager::getInstance(AppStoreInstalledModule::class);
            $installedModule->clear()
                ->setModuleName($moduleName)
                ->setVersion($metadata['version'] !== '' ? $metadata['version'] : '0.0.0')
                ->setDisplayName($metadata['display_name'] !== '' ? $metadata['display_name'] : $moduleName)
                ->setDescription((string)__('从商城应用说明文件恢复的安装记录'))
                ->setPlatformModuleId(0);

            $recordTime = date('Y-m-d H:i:s', (int)(@filemtime($moduleDir . DS . '商城应用.md') ?: time()));
            $installedModule->setInstalledAt($recordTime);
            $installedModule->setData(AppStoreInstalledModule::schema_fields_updated_at, date('Y-m-d H:i:s'));
            $installedModule->save();

            $existing[$moduleName] = true;
            $recovered = true;
        }

        if (!$recovered) {
            return $modules;
        }

        /** @var AppStoreInstalledModule $moduleModel */
        $moduleModel = ObjectManager::getInstance(AppStoreInstalledModule::class);
        $moduleModel->reset()
            ->order('installed_at', 'DESC')
            ->select()
            ->fetch();

        return $moduleModel->getItems();
    }

    private function discoverMarketplaceModuleDirectories(): array
    {
        $matches = glob(APP_CODE_PATH . '*' . DS . '*' . DS . '商城应用.md') ?: [];
        $dirs = [];
        foreach ($matches as $readmePath) {
            $moduleDir = dirname($readmePath);
            if (is_file($moduleDir . DS . 'register.php')) {
                $dirs[] = $moduleDir;
            }
        }

        return $dirs;
    }

    private function parseRegisterMetadata(string $registerFile): array
    {
        $content = is_file($registerFile) ? (string)file_get_contents($registerFile) : '';
        if ($content === '') {
            return ['module_name' => '', 'version' => '', 'display_name' => ''];
        }

        preg_match("/Register::MODULE\\s*,\\s*'([^']+)'\\s*,\\s*__DIR__\\s*,\\s*'([^']*)'\\s*,\\s*__\\('([^']*)'\\)/s", $content, $matches);
        if (!$matches) {
            return ['module_name' => '', 'version' => '', 'display_name' => ''];
        }

        return [
            'module_name' => (string)$matches[1],
            'version' => (string)$matches[2],
            'display_name' => (string)$matches[3],
        ];
    }

    private function loadLatestUninstallRecord(): array
    {
        $dir = BP . 'var' . DS . 'appstore' . DS . 'install-records';
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . DS . '*.jsonl') ?: [];
        usort($files, static function (string $left, string $right): int {
            return ((int)@filemtime($right)) <=> ((int)@filemtime($left));
        });

        foreach ($files as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!$lines) {
                continue;
            }

            for ($index = count($lines) - 1; $index >= 0; $index--) {
                $record = json_decode((string)$lines[$index], true);
                if (!is_array($record) || ($record['action'] ?? '') !== 'uninstall') {
                    continue;
                }

                $moduleName = (string)($record['module_name'] ?? '');
                return [
                    'module_name' => $moduleName,
                    'display_name' => (string)($record['display_name'] ?? $moduleName),
                    'version' => (string)($record['version'] ?? ''),
                    'recorded_at' => (string)($record['recorded_at'] ?? ''),
                    'record_path' => $file,
                ];
            }
        }

        return [];
    }

    private function loadUpdateIndex(iterable $modules): array
    {
        $moduleList = [];
        foreach ($modules as $module) {
            $data = is_object($module) && method_exists($module, 'getData') ? $module->getData() : (array)$module;
            $moduleName = (string)($data['module_name'] ?? '');
            if ($moduleName === '') {
                continue;
            }
            $moduleList[] = [
                'name' => $moduleName,
                'module_name' => $moduleName,
                'version' => (string)($data['version'] ?? '0.0.0'),
                'platform_module_id' => (int)($data['platform_module_id'] ?? 0),
                'license_key' => (string)($data['license_key'] ?? ''),
            ];
        }

        if (!$moduleList) {
            return ['updates' => [], 'error' => ''];
        }

        try {
            /** @var AccountBindService $accountService */
            $accountService = ObjectManager::getInstance(AccountBindService::class);
            if (!$accountService->isBound()) {
                return ['updates' => [], 'error' => __('请先绑定官网账户后检查更新')];
            }

            $token = $accountService->getApiToken();
            if (!$token) {
                return ['updates' => [], 'error' => __('获取授权令牌失败，请重新绑定官网账户')];
            }

            $client = new Client(['timeout' => 30]);
            $response = $client->post(
                $accountService->getPlatformApiUrl('/api/v1/platform/module/check-update'),
                [
                    'headers' => ['Authorization' => 'Bearer ' . $token],
                    'form_params' => [
                        'modules' => $moduleList,
                        'domain' => $this->getCurrentDomain(),
                    ],
                ]
            );

            $data = json_decode($response->getBody()->getContents(), true);
            if (!is_array($data) || empty($data['success'])) {
                return [
                    'updates' => [],
                    'error' => (string)($data['message'] ?? __('检查更新失败')),
                ];
            }

            return [
                'updates' => $this->normalizeUpdatePayload($data, $moduleList),
                'error' => '',
            ];
        } catch (\Throwable $e) {
            return [
                'updates' => [],
                'error' => __('检查更新失败：') . $e->getMessage(),
            ];
        }
    }

    private function normalizeUpdatePayload(array $response, array $moduleList): array
    {
        $payload = $response['data'] ?? $response;
        if (!is_array($payload)) {
            return [];
        }

        $items = $payload['updates'] ?? $payload['items'] ?? $payload['modules'] ?? $payload;
        if (!is_array($items)) {
            return [];
        }

        $currentVersions = [];
        $currentModuleIds = [];
        $currentLicenseKeys = [];
        foreach ($moduleList as $module) {
            $name = (string)$module['name'];
            $currentVersions[$name] = (string)$module['version'];
            $currentModuleIds[$name] = (int)($module['platform_module_id'] ?? 0);
            $currentLicenseKeys[$name] = (string)($module['license_key'] ?? '');
        }

        $updates = [];
        foreach ($items as $key => $item) {
            if (!is_array($item)) {
                continue;
            }

            $moduleName = (string)($item['module_name'] ?? $item['name'] ?? (is_string($key) ? $key : ''));
            if ($moduleName === '' || !isset($currentVersions[$moduleName])) {
                continue;
            }

            $version = $item['latest_version'] ?? $item['version'] ?? $item['current_version'] ?? '';
            if (is_array($version)) {
                $version = $version['version'] ?? '';
            }
            $latestVersion = (string)$version;
            $hasUpdate = isset($item['update_available'])
                ? (bool)$item['update_available']
                : (isset($item['has_update'])
                    ? (bool)$item['has_update']
                    : ($latestVersion !== '' && version_compare($currentVersions[$moduleName], $latestVersion, '<')));

            $updates[$moduleName] = [
                'module_name' => $moduleName,
                'latest_version' => $latestVersion,
                'update_available' => $hasUpdate,
                'platform_module_id' => (int)($item['platform_module_id'] ?? $item['module_id'] ?? $item['id'] ?? $currentModuleIds[$moduleName] ?? 0),
                'license_key' => (string)($item['license_key'] ?? $currentLicenseKeys[$moduleName] ?? ''),
                'display_name' => (string)($item['display_name'] ?? ''),
                'description' => (string)($item['description'] ?? ''),
                'message' => (string)($item['message'] ?? ''),
            ];
        }

        return $updates;
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
}
