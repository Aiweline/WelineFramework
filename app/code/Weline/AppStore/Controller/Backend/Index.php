<?php
declare(strict_types=1);

namespace Weline\AppStore\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\Env;
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

        $this->assign('is_bound', $isBound);
        $this->assign('account', $account);
        $this->assign('page_title', __('应用商城'));

        return $this->fetch();
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
            $platformUrl = Env::get('appstore.platform_url', 'https://app.aiweline.com');
            if (!is_string($platformUrl) || $platformUrl === '') {
                $platformUrl = 'https://app.aiweline.com';
            }
            $client = new \GuzzleHttp\Client();
            $response = $client->post(
                $platformUrl . '/api/v1/platform/module/list',
                [
                    'headers' => ['Authorization' => 'Bearer ' . $token],
                    'json' => $this->request->getPost(),
                ]
            );

            return $response->getBody()->getContents();
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('获取模块列表失败：') . $e->getMessage());
        }
    }

    /**
     * 下载并安装模块
     */
    #[Acl('Weline_AppStore::index_install', '安装模块', 'bi-download', '下载并安装模块')]
    public function install(): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');

        try {
            $licenseKey = $this->request->getPost('license_key');
            $version = $this->request->getPost('version');

            if (!$licenseKey) {
                return $this->jsonResponse(false, __('缺少许可证密钥'));
            }

            /** @var ModuleInstallerService $installer */
            $installer = ObjectManager::getInstance(ModuleInstallerService::class);

            // 下载模块
            $downloadResult = $installer->download($licenseKey, $version);

            // 安装模块
            $installResult = $installer->install($downloadResult['file_path'], [
                'license_key' => $licenseKey,
                'platform_module_id' => $downloadResult['module_info']['id'] ?? 0,
            ]);

            if ($installResult['success']) {
                return $this->jsonResponse(true, __('模块安装成功'), $installResult);
            } else {
                return $this->jsonResponse(false, __('模块安装失败：') . ($installResult['message'] ?? ''), $installResult);
            }
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('安装失败：') . $e->getMessage());
        }
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
}
