<?php
declare(strict_types=1);

namespace Weline\AppStore\Controller\Backend;

use GuzzleHttp\Client;
use Weline\AppStore\Model\AppStoreInstalledModule;
use Weline\AppStore\Service\AccountBindService;
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
        $modules = $moduleModel->getItems();

        $this->assign('modules', $modules);
        $this->assign('system_modules', Env::getInstance()->getModuleList(true));
        $this->assign('module_manager_url', $this->request->getUrlBuilder()->getBackendUrl('module-manager/backend/listing'));
        $this->assign('page_title', __('我的模块'));

        return $this->fetch();
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
                'module_manager_url' => $this->request->getUrlBuilder()->getBackendUrl('module-manager/backend/listing'),
            ]);
        } catch (\Throwable $e) {
            return $this->jsonResponse(false, __('获取系统卸载提示失败：') . $e->getMessage());
        }
    }

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

            $client = new Client();
            $response = $client->post(
                rtrim($platformUrl, '/') . '/api/v1/platform/module/check-update',
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

    private function jsonResponse(bool $success, string $message, array $data = []): string
    {
        return json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);
    }
}
