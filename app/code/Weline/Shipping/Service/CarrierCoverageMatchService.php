<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\Carrier;
use Weline\Shipping\Model\CarrierRegion;
use Weline\Shipping\Model\ShippingService;

/**
 * 结账匹配主路径：禁运 → 可售白名单（可空）→ 承运商覆盖 → sort_order → 服务。
 */
final class CarrierCoverageMatchService
{
    public function __construct(
        private readonly ObjectManager $objectManager,
        private readonly EmbargoService $embargoService,
        private readonly DestinationService $destinationService,
        private readonly CoverageRuleMatcher $ruleMatcher,
    ) {
    }

    /**
     * @param array<string, mixed>|null $context website_id/store_id/channel_id
     * @return list<array<string, mixed>>
     */
    public function getAvailableServices(
        string $countryCode,
        ?string $province = null,
        ?string $city = null,
        ?string $district = null,
        ?array $context = null,
    ): array {
        $address = [
            'country_code' => strtoupper(trim($countryCode)) ?: 'CN',
            'province' => trim((string)$province),
            'city' => trim((string)$city),
            'district' => trim((string)$district),
        ];

        $embargo = $this->embargoService->evaluateAddress($address, $context);
        if (!empty($embargo['blocked'])) {
            return [];
        }

        if (!$this->destinationService->addressAllowed($address, $context)) {
            return [];
        }

        $carrierIds = $this->matchingCarrierIds($address);
        if ($carrierIds === []) {
            return [];
        }

        /** @var ShippingService $serviceModel */
        $serviceModel = $this->objectManager->getInstance(ShippingService::class);
        $result = [];
        foreach ($carrierIds as $carrierId) {
            try {
                $services = $serviceModel->reset()
                    ->where(ShippingService::schema_fields_CARRIER_ID, $carrierId)
                    ->where(ShippingService::schema_fields_IS_ACTIVE, 1)
                    ->order(ShippingService::schema_fields_SORT_ORDER, 'ASC')
                    ->select()
                    ->fetch()
                    ->getItems();
            } catch (\Throwable) {
                continue;
            }
            foreach ($services as $service) {
                if (!$service instanceof ShippingService) {
                    continue;
                }
                $result[] = [
                    'service_id' => $service->getId(),
                    'service_name' => $service->getData(ShippingService::schema_fields_SERVICE_NAME),
                    'service_code' => $service->getData(ShippingService::schema_fields_SERVICE_CODE),
                    'carrier_id' => $carrierId,
                    'estimated_days_min' => $service->getData(ShippingService::schema_fields_ESTIMATED_DAYS_MIN),
                    'estimated_days_max' => $service->getData(ShippingService::schema_fields_ESTIMATED_DAYS_MAX),
                    'is_free_shipping' => $service->getData(ShippingService::schema_fields_IS_FREE_SHIPPING),
                ];
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $address
     * @return list<int> carrier ids ordered by sort_order
     */
    public function matchingCarrierIds(array $address): array
    {
        /** @var Carrier $carrierModel */
        $carrierModel = $this->objectManager->getInstance(Carrier::class);
        try {
            $carriers = $carrierModel->reset()
                ->where(Carrier::schema_fields_IS_ACTIVE, 1)
                ->order(Carrier::schema_fields_SORT_ORDER, 'ASC')
                ->order(Carrier::schema_fields_CARRIER_NAME, 'ASC')
                ->select()
                ->fetch()
                ->getItems();
        } catch (\Throwable) {
            return [];
        }

        $matched = [];
        foreach ($carriers as $carrier) {
            if (!$carrier instanceof Carrier) {
                continue;
            }
            $carrierId = (int)$carrier->getId();
            if ($carrierId <= 0) {
                continue;
            }
            $rules = $this->loadCarrierRules($carrierId);
            if ($rules === []) {
                continue;
            }
            if ($this->ruleMatcher->addressMatchesAnyRule($address, $rules)) {
                $matched[] = $carrierId;
            }
        }

        return $matched;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadCarrierRules(int $carrierId): array
    {
        try {
            /** @var CarrierRegion $model */
            $model = $this->objectManager->getInstance(CarrierRegion::class);
            $items = $model->reset()
                ->where(CarrierRegion::schema_fields_CARRIER_ID, $carrierId)
                ->where(CarrierRegion::schema_fields_IS_ACTIVE, 1)
                ->select()
                ->fetch()
                ->getItems();
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (!$item instanceof CarrierRegion) {
                continue;
            }
            $out[] = [
                'region_type' => (string)$item->getData(CarrierRegion::schema_fields_REGION_TYPE),
                'country_code' => (string)$item->getData(CarrierRegion::schema_fields_COUNTRY_CODE),
                'region_id' => (int)$item->getData(CarrierRegion::schema_fields_REGION_ID),
                'region_code' => (string)$item->getData(CarrierRegion::schema_fields_REGION_CODE),
                'street_id' => (int)$item->getData(CarrierRegion::schema_fields_STREET_ID),
            ];
        }

        return $out;
    }
}
