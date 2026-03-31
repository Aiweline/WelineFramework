<?php
declare(strict_types=1);

namespace Weline\AppStore\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\Env;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\ObjectManager;
use Weline\AppStore\Model\AppStoreInstalledModule;
use Weline\AppStore\Service\AccountBindService;

/**
 * 已安装模块控制器
 */
#[Acl('Weline_AppStore::installed', '我的模块', 'bi-puzzle', '已安装模块管理', 'Weline_AppStore::appstore')]
class Installed extends BackendController
{
    /**
     * 已安装模块列表
     */
    #[Acl('Weline_AppStore::installed_view', '查看模块', 'bi-list', '查看已安装模块')]
    public function index(): string
    {
        /** @var AppStoreInstalledModule $moduleModel */
        $moduleModel = ObjectManager::getInstance(AppStoreInstalledModule::class);

        $modules = $moduleModel->reset()
            ->order('installed_at', 'DESC')
            ->select()
            ->fetch();

        $this->assign('modules', $modules);
        $this->assign('page_title', __('我的模块'));

        return $this->fetch();
    }

    /**
     * 模块详情
     */
    #[Acl('Weline_AppStore::installed_detail', '模块详情', 'bi-info-circle', '查看模块详情')]
    public function detail(): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');

        $installId = $this->request->getGet('id');

        if (!$installId) {
            return $this->jsonResponse(false, __('缺少模块ID'));
        }

        /** @var AppStoreInstalledModule $moduleModel */
        $moduleModel = ObjectManager::getInstance(AppStoreInstalledModule::class);
        $module = $moduleModel->load($installId);

        if (!$module->getInstallId()) {
            return $this->jsonResponse(false, __('模块不存在'));
        }

        return $this->jsonResponse(true, '', ['module' => $module->getData()]);
    }

    /**
     * 卸载模块
     */
    #[Acl('Weline_AppStore::installed_uninstall', '卸载模块', 'bi-trash', '卸载已安装模块')]
    public function uninstall(): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');

        $installId = $this->request->getPost('id');
        $moduleName = $this->request->getPost('module_name');

        if (!$installId && !$moduleName) {
            return $this->jsonResponse(false, __('缺少模块标识'));
        }

        try {
            /** @var AppStoreInstalledModule $moduleModel */
            $moduleModel = ObjectManager::getInstance(AppStoreInstalledModule::class);

            if ($installId) {
                $moduleModel->load($installId);
            } else {
                $moduleModel->load($moduleName, 'module_name');
            }

            if (!$moduleModel->getInstallId()) {
                return $this->jsonResponse(false, __('模块不存在'));
            }

            $moduleName = $moduleModel->getModuleName();

            // 执行卸载命令
            $command = PHP_BINARY . ' ' . BP . 'bin' . DS . 'w module:uninstall ' . escapeshellarg($moduleName);
            exec($command . ' 2>&1', $output, $returnCode);

            if ($returnCode !== 0) {
                return $this->jsonResponse(false, __('卸载失败：') . implode("\n", $output));
            }

            // 删除记录
            $moduleModel->delete();

            return $this->jsonResponse(true, __('模块已卸载'));
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('卸载失败：') . $e->getMessage());
        }
    }

    /**
     * 检查更新
     */
    #[Acl('Weline_AppStore::installed_check_update', '检查更新', 'bi-arrow-repeat', '检查模块更新')]
    public function checkUpdate(): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');

        try {
            /** @var AccountBindService $accountService */
            $accountService = ObjectManager::getInstance(AccountBindService::class);

            if (!$accountService->isBound()) {
                return $this->jsonResponse(false, __('请先绑定官网账户'));
            }

            /** @var AppStoreInstalledModule $moduleModel */
            $moduleModel = ObjectManager::getInstance(AppStoreInstalledModule::class);
            $modules = $moduleModel->reset()->select()->fetch();

            $moduleList = [];
            foreach ($modules as $module) {
                if (is_object($module) && method_exists($module, 'getData')) {
                    $module = $module->getData();
                }
                $moduleList[] = [
                    'name' => $module['module_name'] ?? '',
                    'version' => $module['version'] ?? '0.0.0',
                ];
            }

            $token = $accountService->getApiToken();
            $platformUrl = Env::get('appstore.platform_url', 'https://app.aiweline.com');
            if (!is_string($platformUrl) || $platformUrl === '') {
                $platformUrl = 'https://app.aiweline.com';
            }
            $client = new \GuzzleHttp\Client();
            $response = $client->post(
                $platformUrl . '/api/v1/platform/module/check-update',
                [
                    'headers' => ['Authorization' => 'Bearer ' . $token],
                    'json' => ['modules' => $moduleList],
                ]
            );

            return $response->getBody()->getContents();
        } catch (\Throwable $e) {
            return $this->jsonResponse(false, __('检查更新失败：') . $e->getMessage());
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
