<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\SystemConfig\Model\SystemConfig;

/**
 * 幂等写入全局默认联系地址（英文），供邮件页尾 / 前台联系信息继承。
 */
final class SiteContactSeedService
{
    public const CONFIG_MODULE = 'Weline_Websites';

    public const CONFIG_AREA = 'backend';

    public const KEY_ADDRESS = 'website/contact/address';

    public const KEY_PHONE = 'website/contact/phone';

    public const KEY_SERVICE_HOURS = 'website/contact/service_hours';

    /**
     * 公开检索未命中街道路号时的英文公司/园区级默认地址（可在系统配置 Global 覆盖）。
     */
    public const DEFAULT_ADDRESS_EN =
        'Chengdu Amayun Technology Co., Ltd., China (Sichuan) Pilot Free Trade Zone, '
        . 'Chengdu High-tech Zone, Chengdu, Sichuan, P.R. China';

    public function __construct(
        private readonly SystemConfig $systemConfig,
    ) {
    }

    public function ensureGlobalDefaults(): bool
    {
        $changed = false;
        $resolved = $this->systemConfig->resolveConfig(
            self::KEY_ADDRESS,
            self::CONFIG_MODULE,
            self::CONFIG_AREA,
            SystemConfig::SCOPE_GLOBAL,
            SystemConfig::LOCALE_DEFAULT,
            null
        );
        $current = trim((string)($resolved['value'] ?? ''));
        $sourceScope = (string)($resolved['source']['scope'] ?? '');
        $hasOwnGlobal = !empty($resolved['found'])
            && $sourceScope === SystemConfig::SCOPE_GLOBAL
            && $current !== '';

        if (!$hasOwnGlobal) {
            $ok = $this->systemConfig->setScopedConfig(
                self::KEY_ADDRESS,
                self::DEFAULT_ADDRESS_EN,
                self::CONFIG_MODULE,
                self::CONFIG_AREA,
                SystemConfig::SCOPE_GLOBAL,
                SystemConfig::LOCALE_DEFAULT,
                [
                    'value_type' => 'string',
                    'reason' => 'websites_site_contact_seed',
                ]
            );
            if (!$ok) {
                throw new \RuntimeException('website_contact_address_seed_failed');
            }
            $changed = true;
        }

        return $changed;
    }
}
