<?php
declare(strict_types=1);

namespace Weline\PlatformAppStore\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\PlatformAppStore\Model\PlatformModule;
use Weline\PlatformAppStore\Model\PlatformModuleLicense;
use Weline\PlatformAppStore\Model\PlatformOrder;

/**
 * 许可证服务
 *
 * 负责许可证的生成、激活、验证和域名绑定
 */
class LicenseService
{
    /**
     * 许可证密钥前缀
     */
    private const LICENSE_PREFIX = 'WL';

    /**
     * 许可证密钥长度（不含前缀）
     */
    private const LICENSE_LENGTH = 32;

    /**
     * 订阅周期配置（天数）
     */
    private const SUBSCRIPTION_CYCLES = [
        'monthly' => 30,
        'quarterly' => 90,
        'yearly' => 365,
    ];

    /**
     * 生成许可证密钥
     *
     * @return string 许可证密钥
     */
    public function generateLicenseKey(): string
    {
        $bytes = random_bytes(self::LICENSE_LENGTH / 2);
        return self::LICENSE_PREFIX . '-' . strtoupper(bin2hex($bytes));
    }

    /**
     * 为订单创建许可证
     *
     * @param PlatformOrder $order 订单
     * @param PlatformModule $module 模块
     * @return PlatformModuleLicense 许可证
     */
    public function createLicenseForOrder(PlatformOrder $order, PlatformModule $module): PlatformModuleLicense
    {
        /** @var PlatformModuleLicense $license */
        $license = ObjectManager::getInstance(PlatformModuleLicense::class);
        $license->setLicenseKey($this->generateLicenseKey());
        $license->setModuleId($order->getModuleId());
        $license->setOrderId($order->getOrderId());
        $license->setCustomerId($order->getCustomerId());
        $license->setStatus(PlatformModuleLicense::STATUS_INACTIVE);

        // 订阅类型设置过期时间
        if ($order->getType() === PlatformOrder::TYPE_SUBSCRIPTION) {
            $expiresAt = $this->calculateExpirationDate($module->getSubscriptionCycle());
            $license->setExpiresAt($expiresAt);
        }

        $license->save();
        return $license;
    }

    /**
     * 计算过期日期
     *
     * @param string|null $subscriptionCycle 订阅周期
     * @return string 过期日期
     */
    public function calculateExpirationDate(?string $subscriptionCycle): string
    {
        $days = self::SUBSCRIPTION_CYCLES[$subscriptionCycle] ?? 365;
        return date('Y-m-d H:i:s', strtotime("+{$days} days"));
    }

    /**
     * 激活许可证（绑定域名）
     *
     * @param string $licenseKey 许可证密钥
     * @param string $domain 要绑定的域名
     * @return array 激活结果
     */
    public function activateLicense(string $licenseKey, string $domain): array
    {
        /** @var PlatformModuleLicense $license */
        $license = ObjectManager::getInstance(PlatformModuleLicense::class);
        $license->load($licenseKey, PlatformModuleLicense::schema_fields_license_key);

        if (!$license->getLicenseId()) {
            return [
                'success' => false,
                'error' => 'license_not_found',
                'message' => __('许可证不存在'),
            ];
        }

        if ($license->getStatus() === PlatformModuleLicense::STATUS_REVOKED) {
            return [
                'success' => false,
                'error' => 'license_revoked',
                'message' => __('许可证已被撤销'),
            ];
        }

        // 检查是否已绑定其他域名
        $boundDomain = $license->getDomain();
        if ($boundDomain && !$this->domainsMatch($boundDomain, $domain)) {
            return [
                'success' => false,
                'error' => 'already_bound',
                'message' => __('许可证已绑定到其他域名：') . $boundDomain,
            ];
        }

        // 检查是否过期
        if ($license->isExpired()) {
            return [
                'success' => false,
                'error' => 'license_expired',
                'message' => __('许可证已过期'),
            ];
        }

        // 激活许可证
        $license->activate($this->normalizeDomain($domain));
        $license->save();

        return [
            'success' => true,
            'license_id' => $license->getLicenseId(),
            'license_key' => $license->getLicenseKey(),
            'domain' => $license->getDomain(),
            'expires_at' => $license->getExpiresAt(),
            'activated_at' => $license->getActivatedAt(),
        ];
    }

    /**
     * 验证许可证
     *
     * @param string $licenseKey 许可证密钥
     * @param string $domain 请求域名
     * @return array 验证结果
     */
    public function validateLicense(string $licenseKey, string $domain): array
    {
        /** @var PlatformModuleLicense $license */
        $license = ObjectManager::getInstance(PlatformModuleLicense::class);
        $license->load($licenseKey, PlatformModuleLicense::schema_fields_license_key);

        if (!$license->getLicenseId()) {
            return [
                'valid' => false,
                'error' => 'license_not_found',
                'message' => __('许可证不存在'),
            ];
        }

        // 检查状态
        if ($license->getStatus() === PlatformModuleLicense::STATUS_REVOKED) {
            return [
                'valid' => false,
                'error' => 'license_revoked',
                'message' => __('许可证已被撤销'),
            ];
        }

        // 检查是否已激活
        if (!$license->isActivated()) {
            return [
                'valid' => false,
                'error' => 'not_activated',
                'message' => __('许可证尚未激活'),
            ];
        }

        // 检查域名匹配
        if (!$this->domainsMatch($license->getDomain(), $domain)) {
            return [
                'valid' => false,
                'error' => 'domain_mismatch',
                'message' => __('域名不匹配，许可证绑定域名：') . $license->getDomain(),
            ];
        }

        // 检查是否过期
        if ($license->isExpired()) {
            // 更新状态为过期
            $license->setStatus(PlatformModuleLicense::STATUS_EXPIRED);
            $license->save();

            return [
                'valid' => false,
                'error' => 'license_expired',
                'message' => __('许可证已过期'),
                'expires_at' => $license->getExpiresAt(),
            ];
        }

        return [
            'valid' => true,
            'license_id' => $license->getLicenseId(),
            'module_id' => $license->getModuleId(),
            'domain' => $license->getDomain(),
            'expires_at' => $license->getExpiresAt(),
        ];
    }

    /**
     * 检查域名是否匹配
     *
     * 支持 www 和非 www 自动匹配
     *
     * @param string $boundDomain 绑定域名
     * @param string $requestDomain 请求域名
     * @return bool
     */
    public function domainsMatch(string $boundDomain, string $requestDomain): bool
    {
        $boundDomain = $this->normalizeDomain($boundDomain);
        $requestDomain = $this->normalizeDomain($requestDomain);

        // 如果任一域名为空，返回不匹配
        if (empty($boundDomain) || empty($requestDomain)) {
            return false;
        }

        // 完全匹配
        if ($boundDomain === $requestDomain) {
            return true;
        }

        // www 匹配
        $boundWww = (str_starts_with($boundDomain, 'www.')) ? substr($boundDomain, 4) : 'www.' . $boundDomain;
        $requestWww = (str_starts_with($requestDomain, 'www.')) ? substr($requestDomain, 4) : 'www.' . $requestDomain;

        return $boundWww === $requestDomain || $boundDomain === $requestWww;
    }

    /**
     * 标准化域名
     *
     * @param string $domain 域名
     * @return string 标准化后的域名
     */
    public function normalizeDomain(string $domain): string
    {
        // 移除协议
        $domain = preg_replace('#^https?://#i', '', $domain);
        // 移除端口
        $domain = preg_replace('#:\d+$#', '', $domain);
        // 移除路径
        $domain = strtok($domain, '/');
        // 转小写
        return strtolower(trim($domain));
    }

    /**
     * 撤销许可证
     *
     * @param string $licenseKey 许可证密钥
     * @param string $reason 撤销原因
     * @return bool
     */
    public function revokeLicense(string $licenseKey, string $reason = ''): bool
    {
        /** @var PlatformModuleLicense $license */
        $license = ObjectManager::getInstance(PlatformModuleLicense::class);
        $license->load($licenseKey, PlatformModuleLicense::schema_fields_license_key);

        if (!$license->getLicenseId()) {
            return false;
        }

        $license->revoke();
        $license->save();
        return true;
    }

    /**
     * 续订许可证
     *
     * @param string $licenseKey 许可证密钥
     * @param string $subscriptionCycle 订阅周期
     * @return array
     */
    public function renewLicense(string $licenseKey, string $subscriptionCycle): array
    {
        /** @var PlatformModuleLicense $license */
        $license = ObjectManager::getInstance(PlatformModuleLicense::class);
        $license->load($licenseKey, PlatformModuleLicense::schema_fields_license_key);

        if (!$license->getLicenseId()) {
            return [
                'success' => false,
                'error' => 'license_not_found',
                'message' => __('许可证不存在'),
            ];
        }

        // 计算新的过期时间（从当前过期时间或现在开始计算）
        $currentExpiry = $license->getExpiresAt();
        $baseTime = ($currentExpiry && strtotime($currentExpiry) > time())
            ? strtotime($currentExpiry)
            : time();

        $days = self::SUBSCRIPTION_CYCLES[$subscriptionCycle] ?? 365;
        $newExpiry = date('Y-m-d H:i:s', strtotime("+{$days} days", $baseTime));

        $license->setExpiresAt($newExpiry);
        $license->setStatus(PlatformModuleLicense::STATUS_ACTIVE);
        $license->save();

        return [
            'success' => true,
            'expires_at' => $newExpiry,
        ];
    }

    /**
     * 获取许可证详情
     *
     * @param string $licenseKey 许可证密钥
     * @return array|null
     */
    public function getLicenseDetails(string $licenseKey): ?array
    {
        /** @var PlatformModuleLicense $license */
        $license = ObjectManager::getInstance(PlatformModuleLicense::class);
        $license->load($licenseKey, PlatformModuleLicense::schema_fields_license_key);

        if (!$license->getLicenseId()) {
            return null;
        }

        /** @var PlatformModule $module */
        $module = ObjectManager::getInstance(PlatformModule::class);
        $module->load($license->getModuleId());

        return [
            'license_id' => $license->getLicenseId(),
            'license_key' => $license->getLicenseKey(),
            'module_id' => $license->getModuleId(),
            'module_name' => $module->getName(),
            'module_display_name' => $module->getDisplayName(),
            'domain' => $license->getDomain(),
            'status' => $license->getStatus(),
            'is_active' => $license->isActive(),
            'expires_at' => $license->getExpiresAt(),
            'activated_at' => $license->getActivatedAt(),
            'created_at' => $license->getData('created_at'),
        ];
    }
}
