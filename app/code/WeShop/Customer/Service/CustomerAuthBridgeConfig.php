<?php

declare(strict_types=1);

namespace WeShop\Customer\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Model\SystemConfig;

/**
 * 前台 Weline customer/account/login 与 WeShop 密码登录桥的开关（后台 SystemConfig，非 env）。
 */
final class CustomerAuthBridgeConfig
{
    public const MODULE = 'WeShop_Customer';

    public const CONFIG_KEY = 'weshop_password_login_enabled';

    public function isPasswordBridgeEnabled(): bool
    {
        /** @var SystemConfig $systemConfig */
        $systemConfig = ObjectManager::getInstance(SystemConfig::class);
        $raw = $systemConfig->getConfig(self::CONFIG_KEY, self::MODULE, SystemConfig::area_BACKEND);

        if ($raw === null || $raw === '') {
            return false;
        }

        return $raw === '1' || $raw === 1 || $raw === true;
    }
}
