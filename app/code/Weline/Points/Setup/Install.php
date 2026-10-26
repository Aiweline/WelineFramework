<?php

declare(strict_types=1);

namespace Weline\Points\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\InstallInterface;
use Weline\Points\Model\VipLevel;
use Weline\Points\Service\PointsProductAttributeInstaller;

/**
 * Seeds platform-default VIP tiers. Sites may edit thresholds and benefits;
 * reinstall only fills missing level codes.
 */
class Install implements InstallInterface
{
    private const LEVELS = [
        ['standard', '普通会员', 0, '0.0000', '1.000', 0, 10],
        ['silver', '白银会员', 1000, '0.0100', '1.050', 0, 20],
        ['gold', '黄金会员', 5000, '0.0300', '1.100', 0, 30],
        ['platinum', '铂金会员', 20000, '0.0500', '1.200', 1, 40],
        ['diamond', '钻石会员', 50000, '0.0800', '1.300', 1, 50],
    ];

    public function setup(Setup $setup, Context $context): void
    {
        foreach (self::LEVELS as [$code, $name, $minGrowth, $discount, $multiplier, $freeShipping, $sort]) {
            $existing = ObjectManager::getInstance(VipLevel::class)
                ->clear()
                ->where(VipLevel::schema_fields_CODE, $code)
                ->find()
                ->fetchArray();
            if (\is_array($existing) && $existing !== []) {
                continue;
            }

            ObjectManager::getInstance(VipLevel::class)
                ->clearData()
                ->setData([
                    VipLevel::schema_fields_CODE => $code,
                    VipLevel::schema_fields_NAME => $name,
                    VipLevel::schema_fields_MIN_GROWTH => $minGrowth,
                    VipLevel::schema_fields_DISCOUNT_RATE => $discount,
                    VipLevel::schema_fields_POINTS_MULTIPLIER => $multiplier,
                    VipLevel::schema_fields_FREE_SHIPPING => $freeShipping,
                    VipLevel::schema_fields_IS_ACTIVE => 1,
                    VipLevel::schema_fields_SORT_ORDER => $sort,
                    VipLevel::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
                    VipLevel::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
                ])
                ->save();
        }

        try {
            ObjectManager::getInstance(PointsProductAttributeInstaller::class)->ensureAttributes();
        } catch (\Throwable) {
            // Product/EAV schema may not be ready yet; Upgrade retries it.
        }
    }
}
