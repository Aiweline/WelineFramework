<?php
declare(strict_types=1);

namespace Weline\PlatformAppStore\Controller\Api;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Framework\Manager\ObjectManager;
use Weline\PlatformAppStore\Service\LicenseService;

/**
 * 许可证 API 控制器
 *
 * 提供许可证激活、验证等 API
 */
class License extends FrontendRestController
{
    private LicenseService $licenseService;

    public function __construct()
    {
        parent::__construct();
        $this->licenseService = ObjectManager::getInstance(LicenseService::class);
    }

    /**
     * 激活许可证（绑定域名）
     * POST /rest/v1/platform/license/activate
     */
    public function postActivate()
    {
        try {
            $licenseKey = $this->request->getPost('license_key');
            $domain = $this->request->getPost('domain');

            if (!$licenseKey) {
                return $this->error(__('缺少许可证密钥'), '', 400);
            }

            if (!$domain) {
                $domain = $this->request->getServer('HTTP_HOST');
            }

            $result = $this->licenseService->activateLicense($licenseKey, $domain);

            if (!$result['success']) {
                return $this->error($result['message'], '', 400);
            }

            return $this->success(__('许可证激活成功'), $result);
        } catch (\Exception $e) {
            return $this->error(__('激活失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }

    /**
     * 验证许可证
     * POST /rest/v1/platform/license/validate
     */
    public function postValidate()
    {
        try {
            $licenseKey = $this->request->getPost('license_key');
            $domain = $this->request->getPost('domain');

            if (!$licenseKey) {
                return $this->error(__('缺少许可证密钥'), '', 400);
            }

            if (!$domain) {
                $domain = $this->request->getServer('HTTP_HOST');
            }

            $result = $this->licenseService->validateLicense($licenseKey, $domain);

            if (!$result['valid']) {
                return $this->error($result['message'], $result, 403);
            }

            return $this->success(__('许可证有效'), $result);
        } catch (\Exception $e) {
            return $this->error(__('验证失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }

    /**
     * 检查许可证状态
     * POST /rest/v1/platform/license/check
     */
    public function postCheck()
    {
        try {
            $licenseKey = $this->request->getPost('license_key');

            if (!$licenseKey) {
                return $this->error(__('缺少许可证密钥'), '', 400);
            }

            $details = $this->licenseService->getLicenseDetails($licenseKey);

            if (!$details) {
                return $this->error(__('许可证不存在'), '', 404);
            }

            return $this->success(__('获取成功'), $details);
        } catch (\Exception $e) {
            return $this->error(__('检查失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }

    /**
     * 获取用户的许可证列表
     * POST /rest/v1/platform/license/list
     */
    public function postList()
    {
        try {
            $customerId = $this->request->getPost('customer_id');

            if (!$customerId) {
                return $this->error(__('缺少客户ID'), '', 400);
            }

            /** @var \Weline\PlatformAppStore\Model\PlatformModuleLicense $licenseModel */
            $licenseModel = ObjectManager::getInstance(\Weline\PlatformAppStore\Model\PlatformModuleLicense::class);

            $licenses = $licenseModel->reset()
                ->where('customer_id', $customerId)
                ->order('created_at', 'DESC')
                ->select()
                ->fetch();

            return $this->success(__('获取成功'), [
                'licenses' => $licenses,
            ]);
        } catch (\Exception $e) {
            return $this->error(__('获取列表失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }

    /**
     * 续订许可证
     * POST /rest/v1/platform/license/renew
     */
    public function postRenew()
    {
        try {
            $licenseKey = $this->request->getPost('license_key');
            $subscriptionCycle = $this->request->getPost('subscription_cycle', 'yearly');

            if (!$licenseKey) {
                return $this->error(__('缺少许可证密钥'), '', 400);
            }

            $result = $this->licenseService->renewLicense($licenseKey, $subscriptionCycle);

            if (!$result['success']) {
                return $this->error($result['message'], '', 400);
            }

            return $this->success(__('续订成功'), $result);
        } catch (\Exception $e) {
            return $this->error(__('续订失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
}
