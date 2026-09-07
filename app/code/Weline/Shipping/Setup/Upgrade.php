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
use Weline\Shipping\Model\PostalPlace\LocalDescription as PostalPlaceLocalDescription;
use Weline\Shipping\Model\Region\LocalDescription as RegionLocalDescription;
use Weline\Shipping\Model\ShippingService;
use Weline\Shipping\Model\Street\LocalDescription as StreetLocalDescription;
use Weline\Shipping\Service\CarrierCoverageAdminService;
use Weline\Shipping\Service\RegionLocalSeedService;

/**
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
            RegionLocalDescription::class,
            StreetLocalDescription::class,
            PostalPlaceLocalDescription::class,
            ShippingService::class,
        ] as $modelClass) {
            $model = ObjectManager::getInstance($modelClass);
            $runner = ObjectManager::make(ModelSetup::class);
            $runner->putModel($model);
            $model->setup($runner, $context);
        }

        $this->dropLegacyZoneSchema();
        $this->seedCarrierCoverageDefaults();
        $this->seedRegionLocals();
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
}
