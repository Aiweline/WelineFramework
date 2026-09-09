<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\ServiceRegion;

/**
 * 配送航线目的地覆盖后台读写（对齐 CarrierCoverageAdminService）。
 */
final class ServiceLaneAdminService
{
    public const REGION_TYPES = [
        ServiceRegion::TYPE_COUNTRY,
        ServiceRegion::TYPE_PROVINCE,
        ServiceRegion::TYPE_CITY,
        ServiceRegion::TYPE_DISTRICT,
    ];

    public function __construct(
        private readonly ObjectManager $objectManager,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForService(int $serviceId): array
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
                ->order(ServiceRegion::schema_fields_ID, 'ASC')
                ->select()
                ->fetch()
                ->getItems();
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (!$item instanceof ServiceRegion) {
                continue;
            }
            $out[] = [
                'service_region_id' => (int)$item->getId(),
                'service_id' => (int)$item->getData(ServiceRegion::schema_fields_SERVICE_ID),
                'region_type' => (string)$item->getData(ServiceRegion::schema_fields_REGION_TYPE),
                'country_code' => (string)$item->getData(ServiceRegion::schema_fields_COUNTRY_CODE),
                'region_id' => (int)$item->getData(ServiceRegion::schema_fields_REGION_ID),
                'region_code' => (string)$item->getData(ServiceRegion::schema_fields_REGION_CODE),
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    public function formatRowLabels(array $rows): array
    {
        $typeLabels = [
            ServiceRegion::TYPE_COUNTRY => (string)__('国家'),
            ServiceRegion::TYPE_PROVINCE => (string)__('省份'),
            ServiceRegion::TYPE_CITY => (string)__('城市'),
            ServiceRegion::TYPE_DISTRICT => (string)__('区县'),
        ];
        $labels = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = (string)($row['region_type'] ?? '');
            $cc = (string)($row['country_code'] ?? '');
            $code = (string)($row['region_code'] ?? '');
            $typeLabel = $typeLabels[$type] ?? $type;
            $labels[] = $type === ServiceRegion::TYPE_COUNTRY
                ? $typeLabel . ':' . $cc
                : $typeLabel . ':' . $cc . '/' . ($code !== '' ? $code : (string)(int)($row['region_id'] ?? 0));
        }

        return $labels;
    }

    /**
     * @param list<array<string, mixed>>|array<string, mixed> $rows
     */
    public function replaceForService(int $serviceId, array $rows): int
    {
        if ($serviceId <= 0) {
            throw new \InvalidArgumentException('service_id required');
        }
        $normalized = $this->normalizeRows($rows);

        /** @var ServiceRegion $model */
        $model = $this->objectManager->getInstance(ServiceRegion::class);
        try {
            $existing = $model->reset()
                ->where(ServiceRegion::schema_fields_SERVICE_ID, $serviceId)
                ->select()
                ->fetch()
                ->getItems();
        } catch (\Throwable) {
            $existing = [];
        }
        foreach ($existing as $item) {
            if ($item instanceof ServiceRegion && $item->getId()) {
                $item->delete();
            }
        }

        $saved = 0;
        foreach ($normalized as $row) {
            /** @var ServiceRegion $fresh */
            $fresh = $this->objectManager->getInstance(ServiceRegion::class, [], false);
            $fresh->setData([
                ServiceRegion::schema_fields_SERVICE_ID => $serviceId,
                ServiceRegion::schema_fields_REGION_TYPE => $row['region_type'],
                ServiceRegion::schema_fields_COUNTRY_CODE => $row['country_code'],
                ServiceRegion::schema_fields_REGION_ID => $row['region_id'] > 0 ? $row['region_id'] : null,
                ServiceRegion::schema_fields_REGION_CODE => $row['region_code'] !== '' ? $row['region_code'] : null,
                ServiceRegion::schema_fields_IS_ACTIVE => 1,
            ])->save();
            $saved++;
        }

        return $saved;
    }

    /**
     * @param list<array<string, mixed>>|string|null $raw multi-selection JSON or list
     * @return list<array<string, mixed>>
     */
    public function rowsFromAddressSelection(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw) || $raw === []) {
            return [];
        }

        /** @var ShippingConfigurationAdminService $config */
        $config = $this->objectManager->getInstance(ShippingConfigurationAdminService::class);
        $created = $config->createRegionsFromAddressSelection($raw);
        $rows = [];
        foreach ($created['regions'] as $region) {
            if (!is_object($region) || !method_exists($region, 'getData')) {
                continue;
            }
            $type = (string)$region->getData(\Weline\Shipping\Model\Region::schema_fields_REGION_TYPE);
            $country = (string)$region->getData(\Weline\Shipping\Model\Region::schema_fields_COUNTRY_CODE);
            $regionId = (int)$region->getId();
            $code = (string)$region->getData(\Weline\Shipping\Model\Region::schema_fields_REGION_CODE);
            if ($type === ServiceRegion::TYPE_COUNTRY) {
                $rows[] = [
                    'region_type' => ServiceRegion::TYPE_COUNTRY,
                    'country_code' => $country,
                    'region_id' => 0,
                    'region_code' => $country,
                ];
                continue;
            }
            $rows[] = [
                'region_type' => $type,
                'country_code' => $country,
                'region_id' => $regionId,
                'region_code' => $code,
            ];
        }

        return $this->normalizeRows($rows);
    }

    /**
     * @param list<array<string, mixed>>|array<string, mixed> $rows
     * @return list<array{region_type:string,country_code:string,region_id:int,region_code:string}>
     */
    public function normalizeRows(array $rows): array
    {
        if ($rows !== [] && !array_is_list($rows)) {
            $rows = [$rows];
        }
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = strtolower(trim((string)($row['region_type'] ?? '')));
            if (!in_array($type, self::REGION_TYPES, true)) {
                continue;
            }
            $country = strtoupper(trim((string)($row['country_code'] ?? '')));
            if (preg_match('/^[A-Z]{2}$/D', $country) !== 1) {
                continue;
            }
            $regionId = (int)($row['region_id'] ?? 0);
            $regionCode = strtoupper(trim((string)($row['region_code'] ?? '')));
            if ($type === ServiceRegion::TYPE_COUNTRY) {
                $regionId = 0;
                if ($regionCode === '') {
                    $regionCode = $country;
                }
            } elseif ($regionId <= 0 && $regionCode === '') {
                continue;
            }
            $key = $type . '|' . $country . '|' . $regionId . '|' . $regionCode;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'region_type' => $type,
                'country_code' => $country,
                'region_id' => $regionId,
                'region_code' => $regionCode,
            ];
        }

        return $out;
    }
}
