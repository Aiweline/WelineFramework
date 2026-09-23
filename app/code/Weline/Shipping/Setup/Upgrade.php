<?php

declare(strict_types=1);

namespace Weline\Shipping\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\Shipping\Model\Carrier;
use Weline\Shipping\Model\CarrierRegion;
use Weline\Shipping\Model\DeliveryAddress;
use Weline\Shipping\Model\DestinationRegion;
use Weline\Shipping\Model\EmbargoRegion;
use Weline\Shipping\Model\EmbargoReason;
use Weline\Shipping\Model\EmbargoReason\LocalDescription as EmbargoReasonLocalDescription;
use Weline\Shipping\Model\PostalPlace\LocalDescription as PostalPlaceLocalDescription;
use Weline\Shipping\Model\Region\LocalDescription as RegionLocalDescription;
use Weline\Shipping\Model\ShippingService;
use Weline\Shipping\Model\ServiceRegion;
use Weline\Shipping\Model\Street\LocalDescription as StreetLocalDescription;
use Weline\Shipping\Service\CarrierCoverageAdminService;
use Weline\Shipping\Service\DefaultShippingLaneSeedService;
use Weline\Shipping\Service\EmbargoReasonAdminService;
use Weline\Shipping\Service\FreeShippingConditionTypeAdminService;
use Weline\Shipping\Service\FreeShippingRuleSeedService;
use Weline\Shipping\Service\RegionLocalSeedService;
use Weline\Shipping\Service\ShippingProviderManager;
use Weline\Shipping\Service\SystemEmbargoAdminService;

/**
 * 2.9.30：默认站公开标快安装/升级一次性迁移；保留人工配置，不再回写满49免邮。
 * 2.9.7：全球可达市场种子扩至 ~243 国（中国发运经济小包价）+ 目的地/承运商覆盖增量并入。
 * 2.9.1：DeliveryAddress purpose_checkout / purpose_receiving；存量双标回填。
 * 2.9.0：第4章履约周边（incoterm + 退货模板/策略 + 同单分批发策略）。
 * 2.8.0：第3章同仓分箱 + 签名保价 + 旺季燃油（PackingPolicy/CheckoutAddon/SeasonalRule）。
 * 2.7.0：第2章偏远加价 + 地址点类型/危品门禁（ShippingSurchargeRule）。
 * 2.6.0：第1章运费阶梯表 + ShippingProfile（General/Heavy 种子）+ max_weight/rate_brackets。
 * 2.5.0：Carrier.provider_code + 万能配送壳 Provider 扫描；默认 API 承运商种子委托 Extends。
 * 2.4.95：国家 Region.sort_order 热门位次种子（default-markets）+ 邮编多国待选按位次排序。
 * 2.4.90：种子九档航线锚定当前仓发货地址（origin_shipping_address_id + WarehouseShippingOrigin）。
 * 2.4.89：多仓拆单运费 — WarehouseShippingOrigin 权威仓↔发货地址绑定。
 * 2.4.86：费用模板自建可删、系统种子 SEED_TPL_* 不可删（对齐免邮 remove）。
 * 2.4.85：免邮条件类型字典 FreeShippingConditionType + LocalDescription（列表 <local>）。
 * 2.4.84：费用模板九档航线种子补齐（始终 upsert SEED_TPL_* + 重量/体积/件数字段）。
 * 2.4.82：FreeShippingRule 国际满额种子模板（origin=seed 不可删）。
 * 2.4.72：禁运原因字典 EmbargoReason + LocalDescription。
 * 2.4.68：ShippingService/RateTemplate/FreeShippingRule 作用范围 + 默认可达市场航线种子。
 * 2.4.66：ShippingService.origin_shipping_address_id + ServiceRegion 航线目的地覆盖。
 * 2.4.54：系统默认禁运种子 + EmbargoRegion 扩列。
 * 2.4.48：PostalPlace LocalDescription（place_name 地址展示名多语言）。
 * 2.4.18：删除遗留 Zone（w_shipping_zones / zone_regions）与 services.zone_id。
 * 2.4.12：Region/Street LocalDescription + 源语言 Local 回填 + LocalModelTranslation 入队。
 * 2.4.0：承运商覆盖 / 可售目的地表 + 缺省覆盖种子。
 */
final class Upgrade implements UpgradeInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        foreach ([
            DeliveryAddress::class,
            Carrier::class,
            CarrierRegion::class,
            DestinationRegion::class,
            EmbargoRegion::class,
            EmbargoReason::class,
            EmbargoReasonLocalDescription::class,
            \Weline\Shipping\Model\FreeShippingConditionType::class,
            \Weline\Shipping\Model\FreeShippingConditionType\LocalDescription::class,
            RegionLocalDescription::class,
            StreetLocalDescription::class,
            PostalPlaceLocalDescription::class,
            ShippingService::class,
            ServiceRegion::class,
            \Weline\Shipping\Model\RateTemplate::class,
            \Weline\Shipping\Model\FreeShippingRule::class,
            \Weline\Shipping\Model\WarehouseShippingOrigin::class,
            \Weline\Shipping\Model\ShippingProfile::class,
            \Weline\Shipping\Model\ShippingProfileService::class,
            \Weline\Shipping\Model\ShippingSurchargeRule::class,
            \Weline\Shipping\Model\ShippingPackingPolicy::class,
            \Weline\Shipping\Model\ShippingCheckoutAddon::class,
            \Weline\Shipping\Model\ShippingSeasonalRule::class,
            \Weline\Shipping\Model\ShippingLabelIdempotency::class,
            \Weline\Shipping\Model\ShippingLabelOrphan::class,
            \Weline\Shipping\Model\ShippingCommercePolicy::class,
        ] as $modelClass) {
            $model = ObjectManager::getInstance($modelClass);
            $runner = ObjectManager::make(ModelSetup::class);
            $runner->putModel($model);
            $model->setup($runner, $context);
        }

        $from = $context->getFromSetupVersion();
        if (version_compare($from, '2.9.29', '<')) {
            $this->migrateDeliveryAddressPurposeFlags();
            $this->dropLegacyZoneSchema();
            $this->migrateConfigScopeColumns();
            $this->seedCarrierCoverageDefaults();
            $this->seedRegionLocals();
            $this->seedEmbargoReasons();
            $this->seedFreeShippingConditionTypes();
            $this->seedCountrySortOrders();
            $this->seedProviderCodesAndDefaultApiCarriers();
        }
        if (version_compare($from, '2.9.30', '<')) {
            $this->seedSystemEmbargo();
            $this->seedFreeShippingRules();
            $this->seedDefaultLanes();
            $seeder = ObjectManager::getInstance(FreeShippingRuleSeedService::class);
            $admin = ObjectManager::getInstance(\Weline\Shipping\Service\ShippingConfigurationAdminService::class);
            foreach (FreeShippingRuleSeedService::canonicalCodes() as $code) {
                $id = $seeder->findSeedId($code, 'website', 0);
                if ($id > 0) { $admin->setFreeShippingRuleActive($id, false); }
            }
        }
    }

    /**
     * 2.9.1：存量地址双标（结账+收货），避免启用过滤后列表空窗。
     */
    private function migrateDeliveryAddressPurposeFlags(): void
    {
        try {
            /** @var DeliveryAddress $model */
            $model = ObjectManager::getInstance(DeliveryAddress::class, [], false);
            $conn = $model->getConnection();
            $connector = $conn->getConnector();
            $quote = static function (string $ident) use ($connector): string {
                if (method_exists($connector, 'quoteIdentifier')) {
                    return (string)$connector->quoteIdentifier($ident);
                }

                return '"' . str_replace('"', '""', $ident) . '"';
            };
            $table = $quote(DeliveryAddress::schema_table);
            $checkoutCol = $quote(DeliveryAddress::schema_fields_PURPOSE_CHECKOUT);
            $receivingCol = $quote(DeliveryAddress::schema_fields_PURPOSE_RECEIVING);

            try {
                $model->reset()->query(
                    'ALTER TABLE ' . $table
                    . ' ADD COLUMN IF NOT EXISTS ' . $checkoutCol
                    . ' int NOT NULL DEFAULT 1'
                )->fetch();
            } catch (\Throwable) {
            }
            try {
                $model->reset()->query(
                    'ALTER TABLE ' . $table
                    . ' ADD COLUMN IF NOT EXISTS ' . $receivingCol
                    . ' int NOT NULL DEFAULT 1'
                )->fetch();
            } catch (\Throwable) {
            }

            $model->reset()->query(
                'UPDATE ' . $table
                . ' SET ' . $checkoutCol . '=1, ' . $receivingCol . '=1'
                . ' WHERE ' . $checkoutCol . ' IS NULL OR ' . $receivingCol . ' IS NULL'
                . ' OR (' . $checkoutCol . '=0 AND ' . $receivingCol . '=0)'
            )->fetch();
        } catch (\Throwable) {
            // Schema may not be ready; next upgrade can retry.
        }
    }

    /**
     * 2.5.0：Carrier.provider_code 回填；默认 API 承运商种子委托 Extends。
     */
    private function seedProviderCodesAndDefaultApiCarriers(): void
    {
        try {
            /** @var Carrier $carrierModel */
            $carrierModel = ObjectManager::getInstance(Carrier::class, [], false);
            $items = $carrierModel->reset()->select()->fetch()->getItems();
            foreach ($items as $carrier) {
                if (!$carrier instanceof Carrier) {
                    continue;
                }
                $code = trim((string)$carrier->getData(Carrier::schema_fields_PROVIDER_CODE));
                if ($code === '') {
                    $carrier->setData(
                        Carrier::schema_fields_PROVIDER_CODE,
                        ShippingProviderManager::DEFAULT_PROVIDER_CODE,
                    );
                    $carrier->save();
                }
            }

            $seedClass = 'Weline\\Shipping\\Extends\\Module\\Weline_Shipping\\Setup\\DefaultApiCarrierSeed';
            if (class_exists($seedClass)) {
                $seeder = ObjectManager::getInstance($seedClass);
                if (\is_object($seeder) && method_exists($seeder, 'seed')) {
                    $seeder->seed();
                }
            }
        } catch (\Throwable) {
            // Seed must not block module upgrade.
        }
    }

    private function seedCountrySortOrders(): void
    {
        try {
            /** @var \Weline\Shipping\Service\RegionService $regions */
            $regions = ObjectManager::getInstance(\Weline\Shipping\Service\RegionService::class);
            $regions->seedCountrySortFromDefaultMarkets(true);
        } catch (\Throwable) {
            // Seed must not block module upgrade.
        }
    }

    private function seedFreeShippingConditionTypes(): void
    {
        try {
            /** @var FreeShippingConditionTypeAdminService $admin */
            $admin = ObjectManager::getInstance(FreeShippingConditionTypeAdminService::class);
            $admin->seedDefaults();
        } catch (\Throwable) {
            // Seed must not block module upgrade.
        }
    }

    private function seedFreeShippingRules(): void
    {
        ObjectManager::getInstance(FreeShippingRuleSeedService::class)->seedDefaults('website', 0);
    }

    private function seedEmbargoReasons(): void
    {
        try {
            /** @var EmbargoReasonAdminService $admin */
            $admin = ObjectManager::getInstance(EmbargoReasonAdminService::class);
            $admin->seedDefaults();
        } catch (\Throwable) {
            // Seed must not block module upgrade.
        }
    }

    private function seedSystemEmbargo(): void
    {
        $path = BP . 'app/code/Weline/Shipping/data/system-embargo/countries.tsv';
        if (!is_file($path)) {
            throw new \RuntimeException('System embargo seed source missing.');
        }
        $raw = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($raw)) {
            throw new \RuntimeException('System embargo seed source unreadable.');
        }
        $rows = [];
        foreach ($raw as $i => $line) {
            $line = trim((string)$line);
            if ($line === '' || str_starts_with($line, '#') || ($i === 0 && str_contains($line, 'country_code'))) {
                continue;
            }
            $parts = preg_split("/\t|,\s*/", $line) ?: [];
            $cc = strtoupper(trim((string)($parts[0] ?? '')));
            $reason = trim((string)($parts[1] ?? 'no_commerce'));
            if ($cc === '') {
                continue;
            }
            $rows[] = ['country_code' => $cc, 'reason_code' => $reason !== '' ? $reason : 'no_commerce'];
        }
        /** Existing system rules, including merchant-deactivated ones, are not reset. */
        $admin = ObjectManager::getInstance(SystemEmbargoAdminService::class);
        $existing = [];
        foreach ($admin->listAll() as $row) {
            if (($row['region_type'] ?? '') === 'country') { $existing[(string)$row['country_code']] = true; }
        }
        foreach ($rows as $row) {
            if (!isset($existing[$row['country_code']])) {
                $admin->addRegion('country', $row['country_code'], 0, '', $row['reason_code'], EmbargoRegion::ORIGIN_SEED);
            }
        }
    }

    private function dropLegacyZoneSchema(): void
    {
        try {
            /** @var ShippingService $service */
            $service = ObjectManager::getInstance(ShippingService::class);
            $conn = $service->getConnection();
            $connector = $conn->getConnector();
            $quote = static function (string $ident) use ($connector): string {
                if (method_exists($connector, 'quoteIdentifier')) {
                    return (string)$connector->quoteIdentifier($ident);
                }
                return '"' . str_replace('"', '""', $ident) . '"';
            };

            // Drop dependent column/index first, then legacy tables.
            try {
                $service->reset()->query(
                    'ALTER TABLE ' . $quote(ShippingService::schema_table)
                    . ' DROP COLUMN IF EXISTS ' . $quote('zone_id')
                )->fetch();
            } catch (\Throwable) {
                // Column may already be gone after model sync.
            }

            foreach (['w_shipping_zone_regions', 'w_shipping_zones'] as $table) {
                try {
                    $service->reset()->query('DROP TABLE IF EXISTS ' . $quote($table))->fetch();
                } catch (\Throwable) {
                    // Ignore missing tables on fresh installs.
                }
            }
        } catch (\Throwable) {
            // Connection/schema not ready; next upgrade can retry.
        }
    }

    private function seedCarrierCoverageDefaults(): void
    {
        try {
            /** @var CarrierCoverageAdminService $admin */
            $admin = ObjectManager::getInstance(CarrierCoverageAdminService::class);
            /** @var Carrier $model */
            $model = ObjectManager::getInstance(Carrier::class);
            $carriers = $model->reset()
                ->where(Carrier::schema_fields_IS_ACTIVE, 1)
                ->select()
                ->fetch()
                ->getItems();
            foreach ($carriers as $carrier) {
                if (!$carrier instanceof Carrier) {
                    continue;
                }
                $id = (int)$carrier->getId();
                if ($id <= 0 || $admin->countForCarrier($id) > 0) {
                    continue;
                }
                $providerCode = (string)$carrier->getData(Carrier::schema_fields_PROVIDER_CODE);
                $admin->applyProviderDefaults($id, $providerCode);
            }
        } catch (\Throwable) {
            // Schema may not be ready yet; next deploy can retry.
        }
    }

    private function seedRegionLocals(): void
    {
        try {
            /** @var RegionLocalSeedService $seeder */
            $seeder = ObjectManager::getInstance(RegionLocalSeedService::class);
            $seeder->seedAll();
        } catch (\Throwable) {
            // Local tables or region catalog may be empty on fresh installs.
        }
    }

    private function migrateConfigScopeColumns(): void
    {
        try {
            /** @var ShippingService $service */
            $service = ObjectManager::getInstance(ShippingService::class);
            $conn = $service->getConnection();
            $connector = $conn->getConnector();
            $quote = static function (string $ident) use ($connector): string {
                if (method_exists($connector, 'quoteIdentifier')) {
                    return (string)$connector->quoteIdentifier($ident);
                }

                return '"' . str_replace('"', '""', $ident) . '"';
            };

            $tables = [
                ShippingService::schema_table => ['idx_service_code', 'service_code'],
                'w_shipping_rate_templates' => ['idx_template_code', 'template_code'],
                'w_shipping_free_shipping_rules' => ['idx_rule_code', 'rule_code'],
            ];
            foreach ($tables as $table => [$legacyIndex, $codeCol]) {
                try {
                    $service->reset()->query(
                        'DROP INDEX IF EXISTS ' . $quote($legacyIndex)
                    )->fetch();
                } catch (\Throwable) {
                }
                try {
                    $service->reset()->query(
                        'ALTER TABLE ' . $quote($table)
                        . ' ADD COLUMN IF NOT EXISTS ' . $quote('scope_type')
                        . " varchar(16) NOT NULL DEFAULT 'website'"
                    )->fetch();
                } catch (\Throwable) {
                }
                try {
                    $service->reset()->query(
                        'ALTER TABLE ' . $quote($table)
                        . ' ADD COLUMN IF NOT EXISTS ' . $quote('scope_id')
                        . ' int NOT NULL DEFAULT 0'
                    )->fetch();
                } catch (\Throwable) {
                }
                try {
                    $service->reset()->query(
                        'UPDATE ' . $quote($table)
                        . ' SET ' . $quote('scope_type') . "='website'"
                        . ' WHERE ' . $quote('scope_type') . " IS NULL OR " . $quote('scope_type') . "=''"
                    )->fetch();
                } catch (\Throwable) {
                }
            }
        } catch (\Throwable) {
            // Schema may not be ready yet.
        }
    }

    private function seedDefaultLanes(): void
    {
        ObjectManager::getInstance(DefaultShippingLaneSeedService::class)->seedDefaultWebsite(0);
    }
}
