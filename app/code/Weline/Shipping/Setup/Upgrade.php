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
use Weline\Shipping\Service\RegionLocalSeedService;
use Weline\Shipping\Service\SystemEmbargoAdminService;

/**
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
            CarrierRegion::class,
            DestinationRegion::class,
            EmbargoRegion::class,
            EmbargoReason::class,
            EmbargoReasonLocalDescription::class,
            RegionLocalDescription::class,
            StreetLocalDescription::class,
            PostalPlaceLocalDescription::class,
            ShippingService::class,
            ServiceRegion::class,
            \Weline\Shipping\Model\RateTemplate::class,
            \Weline\Shipping\Model\FreeShippingRule::class,
        ] as $modelClass) {
            $model = ObjectManager::getInstance($modelClass);
            $runner = ObjectManager::make(ModelSetup::class);
            $runner->putModel($model);
            $model->setup($runner, $context);
        }

        $this->dropLegacyZoneSchema();
        $this->migrateConfigScopeColumns();
        $this->seedCarrierCoverageDefaults();
        $this->seedRegionLocals();
        $this->seedSystemEmbargo();
        $this->seedEmbargoReasons();
        $this->seedDefaultLanes();
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
            return;
        }
        $raw = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($raw)) {
            return;
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
        try {
            /** @var SystemEmbargoAdminService $admin */
            $admin = ObjectManager::getInstance(SystemEmbargoAdminService::class);
            $admin->seedFromRows($rows);
            // seedFromRows 内已 purge；再显式一次以防仅有旧伪种子、TSV 未变时漏清。
            $admin->purgeNonCanonicalSeeds(array_column($rows, 'country_code'));
        } catch (\Throwable) {
            // Seed must not block module upgrade.
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
                $admin->applyProviderDefaults($id);
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
        try {
            /** @var DefaultShippingLaneSeedService $seeder */
            $seeder = ObjectManager::getInstance(DefaultShippingLaneSeedService::class);
            $seeder->seedDefaultWebsite(0);
        } catch (\Throwable) {
            // Seed must not block module upgrade.
        }
    }
}
