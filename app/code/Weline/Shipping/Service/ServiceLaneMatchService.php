<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\ServiceRegion;
use Weline\Shipping\Model\ShippingAddress;
use Weline\Shipping\Model\ShippingService;

/**
 * 航线相对发货仓 + 目的地覆盖：在承运商可达性之后过滤/打分 ShippingService。
 */
final class ServiceLaneMatchService
{
    private const SPEC_DISTRICT = 40;
    private const SPEC_CITY = 30;
    private const SPEC_PROVINCE = 20;
    private const SPEC_COUNTRY = 10;
    private const SPEC_WILDCARD = 0;

    public function __construct(
        private readonly ObjectManager $objectManager,
        private readonly CoverageRuleMatcher $ruleMatcher,
        private readonly ShippingAddressService $shippingAddressService,
    ) {
    }

    public function resolveDefaultOriginId(): int
    {
        $default = $this->shippingAddressService->getDefault();
        if ($default instanceof ShippingAddress && (int)$default->getId() > 0) {
            return (int)$default->getId();
        }

        // 无 is_default 时：优先仓权威绑定，否则首个启用发货地址（避免种子航线被 origin=0 滤光）。
        try {
            if (interface_exists(\Weline\Shipping\Api\WarehouseShippingOriginInterface::class)
                && interface_exists(\Weline\Inventory\Api\DefaultWarehouseResolverInterface::class)
            ) {
                /** @var \Weline\Inventory\Api\DefaultWarehouseResolverInterface $resolver */
                $resolver = $this->objectManager->getInstance(
                    \Weline\Inventory\Api\DefaultWarehouseResolverInterface::class
                );
                $warehouseId = (int)$resolver->resolveDefault(0, 0)->warehouseId;
                if ($warehouseId > 0) {
                    /** @var \Weline\Shipping\Api\WarehouseShippingOriginInterface $origins */
                    $origins = $this->objectManager->getInstance(
                        \Weline\Shipping\Api\WarehouseShippingOriginInterface::class
                    );
                    $bound = (int)($origins->findShippingAddressId(0, $warehouseId) ?? 0);
                    if ($bound > 0) {
                        return $bound;
                    }
                }
            }
        } catch (\Throwable) {
            // fall through
        }

        try {
            /** @var ShippingAddress $model */
            $model = $this->objectManager->getInstance(ShippingAddress::class, [], false);
            $items = $model->reset()
                ->where(ShippingAddress::schema_fields_IS_ENABLED, 1)
                ->order(ShippingAddress::schema_fields_IS_DEFAULT, 'DESC')
                ->order(ShippingAddress::schema_fields_ID, 'ASC')
                ->select()
                ->fetch()
                ->getItems();
            $row = is_array($items) ? ($items[0] ?? null) : null;
            if ($row instanceof ShippingAddress && (int)$row->getId() > 0) {
                return (int)$row->getId();
            }
        } catch (\Throwable) {
            // no address
        }

        return 0;
    }

    /**
     * @param list<array<string, mixed>> $services from CarrierCoverageMatchService
     * @param array<string, mixed> $address dest
     * @return list<array<string, mixed>>
     */
    public function filterByOriginAndDest(array $services, array $address, ?int $originShippingAddressId = null): array
    {
        $originId = $originShippingAddressId ?? $this->resolveDefaultOriginId();
        $out = [];
        foreach ($services as $summary) {
            if (!is_array($summary)) {
                continue;
            }
            $serviceId = (int)($summary['service_id'] ?? 0);
            if ($serviceId <= 0) {
                continue;
            }
            /** @var ShippingService $service */
            $service = $this->objectManager->getInstance(ShippingService::class, [], false)->load($serviceId);
            if (!$service->getId() || !(bool)$service->getData(ShippingService::schema_fields_IS_ACTIVE)) {
                continue;
            }
            $boundOrigin = (int)$service->getData(ShippingService::schema_fields_ORIGIN_SHIPPING_ADDRESS_ID);
            if ($boundOrigin > 0) {
                if ($originId <= 0 || $boundOrigin !== $originId) {
                    continue;
                }
            }
            $rules = $this->loadServiceRules($serviceId);
            if ($rules === []) {
                $summary['lane_specificity'] = self::SPEC_WILDCARD;
                $out[] = $summary;
                continue;
            }
            if (!$this->ruleMatcher->addressMatchesAnyRule($address, $rules)) {
                continue;
            }
            $summary['lane_specificity'] = $this->bestSpecificity($address, $rules);
            $out[] = $summary;
        }

        usort($out, static function (array $a, array $b): int {
            $sa = (int)($a['lane_specificity'] ?? 0);
            $sb = (int)($b['lane_specificity'] ?? 0);
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }

            return ((int)($a['service_id'] ?? 0)) <=> ((int)($b['service_id'] ?? 0));
        });

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function loadServiceRules(int $serviceId): array
    {
        if ($serviceId <= 0) {
            return [];
        }
        /** @var ServiceRegion $model */
        $model = $this->objectManager->getInstance(ServiceRegion::class);
        try {
            $items = $model->reset()
                ->where(ServiceRegion::schema_fields_SERVICE_ID, $serviceId)
                ->where(ServiceRegion::schema_fields_IS_ACTIVE, 1)
                ->select()
                ->fetch()
                ->getItems();
        } catch (\Throwable) {
            return [];
        }
        $rules = [];
        foreach ($items as $item) {
            if (!$item instanceof ServiceRegion) {
                continue;
            }
            $rules[] = [
                'country_code' => (string)$item->getData(ServiceRegion::schema_fields_COUNTRY_CODE),
                'region_type' => (string)$item->getData(ServiceRegion::schema_fields_REGION_TYPE),
                'region_id' => (int)$item->getData(ServiceRegion::schema_fields_REGION_ID),
                'region_code' => (string)$item->getData(ServiceRegion::schema_fields_REGION_CODE),
            ];
        }

        return $rules;
    }

    /**
     * @param array<string, mixed> $address
     * @param list<array<string, mixed>> $rules
     */
    public function bestSpecificity(array $address, array $rules): int
    {
        $best = self::SPEC_WILDCARD;
        $resolved = $this->ruleMatcher->resolveAddressLevels($address);
        foreach ($rules as $rule) {
            if (!$this->ruleMatcher->addressMatchesAnyRule($address, [$rule])) {
                continue;
            }
            $type = strtolower(trim((string)($rule['region_type'] ?? '')));
            $score = match ($type) {
                ServiceRegion::TYPE_DISTRICT, 'district' => self::SPEC_DISTRICT,
                ServiceRegion::TYPE_CITY, 'city' => self::SPEC_CITY,
                ServiceRegion::TYPE_PROVINCE, 'province' => self::SPEC_PROVINCE,
                ServiceRegion::TYPE_COUNTRY, 'country' => self::SPEC_COUNTRY,
                default => self::SPEC_WILDCARD,
            };
            if ($score > $best) {
                $best = $score;
            }
        }
        unset($resolved);

        return $best;
    }
}
