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
 * 许可证管理控制器
 */
#[Acl('Weline_AppStore::license', '许可证管理', 'bi-key', '许可证管理', 'Weline_AppStore::appstore')]
class License extends BackendController
{
    /**
     * 许可证列表
     */
    #[Acl('Weline_AppStore::license_view', '查看许可证', 'bi-list', '查看许可证列表')]
    public function index(): string
    {
        /** @var AccountBindService $accountService */
        $accountService = ObjectManager::getInstance(AccountBindService::class);

        $result = $accountService->getUserLicenses();

        $this->assign('licenses', $result['licenses'] ?? []);
        $this->assign('is_bound', $accountService->isBound());
        $this->assign('page_title', __('许可证管理'));

        return $this->fetch();
    }

    /**
     * 激活许可证
     */
    #[Acl('Weline_AppStore::license_activate', '激活许可证', 'bi-check-circle', '激活许可证')]
    public function activate(): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');

        $licenseKey = $this->request->getPost('license_key');
        $domain = $this->request->getPost('domain');

        if (!$licenseKey) {
            return $this->jsonResponse(false, __('缺少许可证密钥'));
        }

        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->post(
                Env::get('appstore.platform_url', 'https://app.aiweline.com') . '/api/v1/platform/license/activate',
                [
                    'json' => [
                        'license_key' => $licenseKey,
                        'domain' => $domain ?: $_SERVER['HTTP_HOST'],
                    ],
                ]
            );

            $data = json_decode($response->getBody()->getContents(), true);

            if ($data['success']) {
                // 更新本地许可证状态
                /** @var AppStoreInstalledModule $moduleModel */
                $moduleModel = ObjectManager::getInstance(AppStoreInstalledModule::class);
                $module = $moduleModel->reset()
                    ->where('license_key', $licenseKey)
                    ->find();

                if ($module) {
                    $module->setLicenseStatus(AppStoreInstalledModule::LICENSE_STATUS_VALID);
                    $module->setBoundDomain($data['domain'] ?? $domain);
                    $module->setLicenseExpiresAt($data['expires_at'] ?? null);
                    $module->save();
                }

                return $this->jsonResponse(true, __('许可证激活成功'), $data);
            } else {
                return $this->jsonResponse(false, $data['message'] ?? __('激活失败'));
            }
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('激活失败：') . $e->getMessage());
        }
    }

    /**
     * 验证许可证
     */
    #[Acl('Weline_AppStore::license_validate', '验证许可证', 'bi-shield-check', '验证许可证')]
    public function validate(): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');

        $licenseKey = $this->request->getPost('license_key');

        if (!$licenseKey) {
            return $this->jsonResponse(false, __('缺少许可证密钥'));
        }

        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->post(
                Env::get('appstore.platform_url', 'https://app.aiweline.com') . '/api/v1/platform/license/validate',
                [
                    'json' => [
                        'license_key' => $licenseKey,
                        'domain' => $_SERVER['HTTP_HOST'],
                    ],
                ]
            );

            return $response->getBody()->getContents();
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('验证失败：') . $e->getMessage());
        }
    }

    /**
     * 续订许可证
     */
    #[Acl('Weline_AppStore::license_renew', '续订许可证', 'bi-arrow-repeat', '续订许可证')]
    public function renew(): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');

        $licenseKey = $this->request->getPost('license_key');
        $subscriptionCycle = $this->request->getPost('subscription_cycle', 'yearly');

        if (!$licenseKey) {
            return $this->jsonResponse(false, __('缺少许可证密钥'));
        }

        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->post(
                Env::get('appstore.platform_url', 'https://app.aiweline.com') . '/api/v1/platform/license/renew',
                [
                    'json' => [
                        'license_key' => $licenseKey,
                        'subscription_cycle' => $subscriptionCycle,
                    ],
                ]
            );

            return $response->getBody()->getContents();
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('续订失败：') . $e->getMessage());
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
