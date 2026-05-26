<?php
declare(strict_types=1);

namespace Weline\AppStore\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\ObjectManager;
use Weline\AppStore\Service\AccountBindService;
use Weline\AppStore\Service\ModuleInstallerService;

/**
 * AppStore 商城首页控制器
 */
#[Acl('Weline_AppStore::index', '商城首页', 'bi-bag', '应用商城首页', 'Weline_AppStore::appstore')]
class Index extends BackendController
{
    /**
     * 商城首页
     */
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
        $filters = [
            'q' => $searchQuery,
            'pricing' => $pricingFilter,
        ];
        $moduleResult = $isBound ? $this->loadPlatformModules($accountService, $filters) : ['items' => [], 'error' => ''];

        $this->assign('is_bound', $isBound);
        $this->assign('account', $account);
        $this->assign('modules', $moduleResult['items']);
        $this->assign('store_error', $moduleResult['error']);
        $this->assign('search_query', $searchQuery);
        $this->assign('pricing_filter', $pricingFilter);
        $this->assign('platform_url', $accountService->getPlatformUrl());
        $this->assign('store_domain', $this->getCurrentDomain());
        $this->assign('account_url', $this->request->getUrlBuilder()->getBackendUrl('appstore/backend/account'));
        $this->assign('download_action', $this->request->getUrlBuilder()->getBackendUrl('appstore/backend/index/download'));
        $this->assign('install_action', $this->request->getUrlBuilder()->getBackendUrl('appstore/backend/index/install'));
        $this->assign('page_title', __('应用商城'));

        return $this->fetch('Weline_AppStore::templates/Backend/Index/index.phtml');
    }

    /**
     * 只下载模块包，不执行安装
     */
    #[Acl('Weline_AppStore::index_download', '下载模块', 'bi-cloud-download', '从官网商城下载模块包')]
    public function download(): string
    {
        if (!$this->request->isPost()) {
            $this->assign('download_result', [
                'success' => false,
                'message' => __('无效的请求方法'),
            ]);

            return $this->index();
        }

        try {
            $licenseKey = trim((string)$this->request->getPost('license_key', ''));
            $version = trim((string)$this->request->getPost('version', ''));
            $moduleId = (int)$this->request->getPost('module_id', 0);

            if ($licenseKey === '' || $moduleId <= 0) {
                throw new \Weline\Framework\App\Exception(__('缺少许可证或模块 ID'));
            }

            /** @var ModuleInstallerService $installer */
            $installer = ObjectManager::getInstance(ModuleInstallerService::class);
            $result = $installer->download($licenseKey, $moduleId, $version !== '' ? $version : null);
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

    /**
     * 模块列表（API代理）
     */
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

            // 调用平台 API
            $platformUrl = $accountService->getPlatformUrl();
            if (!is_string($platformUrl) || $platformUrl === '') {
                $platformUrl = 'https://app.aiweline.com';
            }
            $client = new \GuzzleHttp\Client();
            $response = $client->post(
                $accountService->getPlatformUrl() . '/api/v1/platform/module/list',
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

    /**
     * 下载并安装模块
     */
    #[Acl('Weline_AppStore::index_install', '安装模块', 'bi-download', '下载并安装模块')]
    public function install(): string
    {
        if (!$this->request->isPost()) {
            $this->assign('install_result', [
                'success' => false,
                'message' => __('无效的请求方法'),
            ]);

            return $this->index();
        }

        try {
            $licenseKey = trim((string)$this->request->getPost('license_key', ''));
            $version = trim((string)$this->request->getPost('version', ''));
            $moduleId = (int)$this->request->getPost('module_id', 0);

            if ($licenseKey === '') {
                throw new \Weline\Framework\App\Exception(__('缺少许可证密钥'));
            }
            if ($moduleId <= 0) {
                throw new \Weline\Framework\App\Exception(__('缺少模块 ID'));
            }

            /** @var ModuleInstallerService $installer */
            $installer = ObjectManager::getInstance(ModuleInstallerService::class);

            // 下载模块
            $downloadResult = $installer->download($licenseKey, $moduleId, $version !== '' ? $version : null);
            $moduleInfo = $downloadResult['module_info'] ?? [];
            $platformModuleId = (int)($moduleInfo['module_id'] ?? ($moduleInfo['id'] ?? $moduleId));

            // 安装模块
            $installOptions = [
                'license_key' => $licenseKey,
                'platform_module_id' => $platformModuleId,
                'download_log_id' => (int)($downloadResult['log_id'] ?? 0),
                'download_file_hash' => (string)($downloadResult['file_hash'] ?? ''),
                'download_file_size' => (int)($downloadResult['file_size'] ?? 0),
            ];
            if (!empty($moduleInfo['display_name'])) {
                $installOptions['display_name'] = (string)$moduleInfo['display_name'];
            }
            if (!empty($moduleInfo['description'])) {
                $installOptions['description'] = (string)$moduleInfo['description'];
            }
            $installResult = $installer->install($downloadResult['file_path'], $installOptions);

            if ($installResult['success']) {
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

    /**
     * JSON 响应
     */
    private function jsonResponse(bool $success, string $message, array $data = []): string
    {
        return json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);
    }

    private function loadPlatformModules(AccountBindService $accountService, array $filters = []): array
    {
        try {
            $token = $accountService->getApiToken();
            if (!$token) {
                return [
                    'items' => [],
                    'error' => __('授权令牌无效，请重新授权官网账户'),
                ];
            }

            $client = new \GuzzleHttp\Client(['timeout' => 30]);
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

            $response = $client->post(
                $accountService->getPlatformUrl() . '/api/v1/platform/module/list',
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
                'items' => is_array($items) ? $items : [],
                'error' => '',
            ];
        } catch (\Throwable $e) {
            return [
                'items' => [],
                'error' => __('获取模块列表失败：') . $e->getMessage(),
            ];
        }
    }

    private function getCurrentDomain(): string
    {
        return (string)\w_env('server.http_host', 'localhost');
    }
}
