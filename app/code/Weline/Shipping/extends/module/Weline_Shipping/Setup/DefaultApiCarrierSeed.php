<?php

declare(strict_types=1);

namespace Weline\Shipping\Extends\Module\Weline_Shipping\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Extends\Module\Weline_Shipping\ShippingProvider\YanwenDestinationCoverage;
use Weline\Shipping\Model\Carrier;
use Weline\Shipping\Model\ShippingService;
use Weline\Shipping\Service\CarrierCoverageAdminService;

/**
 * Seeds the built-in Yanwen carrier row (provider_code=yanwen) + country coverage.
 */
final class DefaultApiCarrierSeed
{
    public function seed(): void
    {
        $carrierId = $this->ensureCarrier();
        if ($carrierId <= 0) {
            return;
        }
        $this->ensureCoverage($carrierId);
        $this->ensurePlaceholderService($carrierId);
    }

    private function ensureCarrier(): int
    {
        /** @var Carrier $existing */
        $existing = ObjectManager::getInstance(Carrier::class, [], false)
            ->where(Carrier::schema_fields_CARRIER_CODE, 'YANWEN')
            ->find()
            ->fetch();
        if ($existing instanceof Carrier && $existing->getId()) {
            if (trim((string)$existing->getData(Carrier::schema_fields_PROVIDER_CODE)) === '') {
                $existing->setData(Carrier::schema_fields_PROVIDER_CODE, 'yanwen');
                $existing->save();
            }

            return (int)$existing->getId();
        }

        $carrier = ObjectManager::getInstance(Carrier::class, [], false);
        $carrier->setData([
            Carrier::schema_fields_CARRIER_CODE => 'YANWEN',
            Carrier::schema_fields_CARRIER_NAME => '燕文物流',
            Carrier::schema_fields_CARRIER_TYPE => Carrier::TYPE_API,
            Carrier::schema_fields_PROVIDER_CODE => 'yanwen',
            Carrier::schema_fields_TRACKING_URL_TEMPLATE => 'https://www.yw56.com.cn/track?num={tracking_number}',
            Carrier::schema_fields_TRACKING_SUPPORT_STATUS => Carrier::TRACKING_SUPPORTED,
            Carrier::schema_fields_IS_ACTIVE => 1,
            Carrier::schema_fields_SORT_ORDER => 100,
        ]);
        $carrier->save();

        return (int)$carrier->getId();
    }

    private function ensureCoverage(int $carrierId): void
    {
        try {
            /** @var CarrierCoverageAdminService $admin */
            $admin = ObjectManager::getInstance(CarrierCoverageAdminService::class);
            // Always align API carrier coverage to Provider-hardcoded country catalog.
            $admin->replaceForCarrier($carrierId, YanwenDestinationCoverage::defaultCoverageRegions());
        } catch (\Throwable) {
            // Coverage tables may not be ready.
        }
    }

    private function ensurePlaceholderService(int $carrierId): void
    {
        try {
            $service = ObjectManager::getInstance(ShippingService::class, [], false);
            $found = $service->where(ShippingService::schema_fields_SERVICE_CODE, 'YANWEN_DYNAMIC')->find()->fetch();
            if ($found instanceof ShippingService && $found->getId()) {
                return;
            }
            $service->setData([
                ShippingService::schema_fields_SERVICE_CODE => 'YANWEN_DYNAMIC',
                ShippingService::schema_fields_SERVICE_NAME => '燕文动态报价',
                ShippingService::schema_fields_CARRIER_ID => $carrierId,
                ShippingService::schema_fields_RATE_TEMPLATE_ID => 0,
                ShippingService::schema_fields_IS_ACTIVE => 0,
                ShippingService::schema_fields_SORT_ORDER => 100,
            ]);
            $service->save();
        } catch (\Throwable) {
            // Carrier binding is enough when service schema rejects optional fields.
        }
    }
}
