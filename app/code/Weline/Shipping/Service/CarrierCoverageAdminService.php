<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\CarrierRegion;

/**
 * 承运商覆盖范围后台读写与启用校验。
 */
final class CarrierCoverageAdminService
{
    public const REGION_TYPES = [
        CarrierRegion::TYPE_COUNTRY,
        CarrierRegion::TYPE_PROVINCE,
        CarrierRegion::TYPE_CITY,
        CarrierRegion::TYPE_DISTRICT,
        CarrierRegion::TYPE_STREET,
    ];

    public function __construct(
        private readonly ObjectManager $objectManager,
        private readonly CarrierCoverageProviderRegistry $providers,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForCarrier(int $carrierId): array
    {
        if ($carrierId <= 0) {
            return [];
        }
        /** @var CarrierRegion $model */
        $model = $this->objectManager->getInstance(CarrierRegion::class);
        $items = $model->reset()
            ->where(CarrierRegion::schema_fields_CARRIER_ID, $carrierId)
            ->where(CarrierRegion::schema_fields_IS_ACTIVE, 1)
            ->order(CarrierRegion::schema_fields_ID, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
        $out = [];
        foreach ($items as $item) {
            if (!$item instanceof CarrierRegion) {
                continue;
            }
            $out[] = $this->rowFromModel($item);
        }

        return $out;
    }

    public function countForCarrier(int $carrierId): int
    {
        return count($this->listForCarrier($carrierId));
    }

    /**
     * @param list<array<string, mixed>>|array<string, mixed> $rows
     */
    public function replaceForCarrier(int $carrierId, array $rows): int
    {
        if ($carrierId <= 0) {
            throw new \InvalidArgumentException('carrier_id required');
        }
        $normalized = $this->normalizeRows($rows);

        /** @var CarrierRegion $model */
        $model = $this->objectManager->getInstance(CarrierRegion::class);
        $existing = $model->reset()
            ->where(CarrierRegion::schema_fields_CARRIER_ID, $carrierId)
            ->select()
            ->fetch()
            ->getItems();
        foreach ($existing as $item) {
            if ($item instanceof CarrierRegion && $item->getId()) {
                $item->delete();
            }
        }

        $now = date('Y-m-d H:i:s');
        $count = 0;
        foreach ($normalized as $row) {
            /** @var CarrierRegion $fresh */
            $fresh = $this->objectManager->getInstance(CarrierRegion::class, [], false);
            $fresh->clear()->setData([
                CarrierRegion::schema_fields_CARRIER_ID => $carrierId,
                CarrierRegion::schema_fields_REGION_TYPE => $row['region_type'],
                CarrierRegion::schema_fields_COUNTRY_CODE => $row['country_code'],
                CarrierRegion::schema_fields_REGION_ID => $row['region_id'],
                CarrierRegion::schema_fields_REGION_CODE => $row['region_code'],
                CarrierRegion::schema_fields_STREET_ID => $row['street_id'],
                CarrierRegion::schema_fields_IS_ACTIVE => 1,
                CarrierRegion::schema_fields_CREATED_AT => $now,
                CarrierRegion::schema_fields_UPDATED_AT => $now,
            ])->save();
            $count++;
        }

        return $count;
    }

    /**
     * Apply merged Provider defaults (replace existing).
     */
    public function applyProviderDefaults(int $carrierId): int
    {
        return $this->replaceForCarrier($carrierId, $this->providers->mergedDefaultCoverage());
    }

    /**
     * @param list<array<string, mixed>>|array<string, mixed>|string|null $raw
     * @return list<array<string, mixed>>
     */
    public function rowsFromPayload(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }

        return $this->normalizeRows($raw);
    }

    /**
     * @throws \RuntimeException
     */
    public function assertActiveRequiresCoverage(int $carrierId, bool $isActive): void
    {
        if (!$isActive) {
            return;
        }
        if ($this->countForCarrier($carrierId) > 0) {
            return;
        }
        throw new \RuntimeException((string)__('启用承运商前必须配置支持范围。'));
    }

    /**
     * Human-readable labels for admin UI (no raw JSON).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    public function formatRowLabels(array $rows): array
    {
        $typeLabels = [
            CarrierRegion::TYPE_COUNTRY => (string)__('国家'),
            CarrierRegion::TYPE_PROVINCE => (string)__('省份'),
            CarrierRegion::TYPE_CITY => (string)__('城市'),
            CarrierRegion::TYPE_DISTRICT => (string)__('区县'),
            CarrierRegion::TYPE_STREET => (string)__('街道'),
        ];
        $out = [];
        foreach ($this->normalizeRows($rows) as $row) {
            $type = (string)$row['region_type'];
            $country = (string)$row['country_code'];
            $name = trim((string)($row['region_name'] ?? $row['label'] ?? ''));
            $code = trim((string)($row['region_code'] ?? ''));
            if ($type === CarrierRegion::TYPE_COUNTRY) {
                $place = $this->countryDisplayName($country);
                $out[] = ($typeLabels[$type] ?? $type) . '：' . $place . '（' . (string)__('全国') . '）';
                continue;
            }
            $place = $name !== '' ? $name : ($code !== '' ? $code : ('#' . (string)(int)($row['region_id'] ?? 0)));
            $out[] = ($typeLabels[$type] ?? $type) . '：' . $country . ' / ' . $place;
        }

        return $out;
    }

    private function countryDisplayName(string $countryCode): string
    {
        $map = [
            'CN' => (string)__('中国'),
            'US' => (string)__('美国'),
            'HK' => (string)__('中国香港'),
            'MO' => (string)__('中国澳门'),
            'TW' => (string)__('中国台湾'),
            'JP' => (string)__('日本'),
            'KR' => (string)__('韩国'),
            'SG' => (string)__('新加坡'),
            'AU' => (string)__('澳大利亚'),
            'GB' => (string)__('英国'),
            'CA' => (string)__('加拿大'),
            'DE' => (string)__('德国'),
            'FR' => (string)__('法国'),
        ];

        return $map[$countryCode] ?? $countryCode;
    }

    /**
     * @return array{region_type:string,country_code:string,region_id:?int,region_code:string,street_id:?int}
     */
    private function rowFromModel(CarrierRegion $item): array
    {
        $rid = (int)$item->getData(CarrierRegion::schema_fields_REGION_ID);
        $sid = (int)$item->getData(CarrierRegion::schema_fields_STREET_ID);

        return [
            'carrier_region_id' => (int)$item->getId(),
            'region_type' => (string)$item->getData(CarrierRegion::schema_fields_REGION_TYPE),
            'country_code' => (string)$item->getData(CarrierRegion::schema_fields_COUNTRY_CODE),
            'region_id' => $rid > 0 ? $rid : null,
            'region_code' => (string)$item->getData(CarrierRegion::schema_fields_REGION_CODE),
            'street_id' => $sid > 0 ? $sid : null,
        ];
    }

    /**
     * @param list<array<string, mixed>>|array<string, mixed> $rows
     * @return list<array{region_type:string,country_code:string,region_id:?int,region_code:string,street_id:?int}>
     */
    public function normalizeRows(array $rows): array
    {
        if ($rows !== [] && !array_is_list($rows)) {
            $maybeList = array_values($rows);
            if (isset($maybeList[0]) && is_array($maybeList[0])) {
                $rows = $maybeList;
            } else {
                $rows = [$rows];
            }
        }

        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = strtolower(trim((string)($row['region_type'] ?? $row['type'] ?? '')));
            if (!in_array($type, self::REGION_TYPES, true)) {
                continue;
            }
            $country = strtoupper(trim((string)($row['country_code'] ?? $row['country'] ?? '')));
            if ($country === '' || !preg_match('/^[A-Z]{2}$/', $country)) {
                continue;
            }
            $regionId = (int)($row['region_id'] ?? 0);
            $regionCode = trim((string)($row['region_code'] ?? $row['code'] ?? ''));
            $streetId = (int)($row['street_id'] ?? 0);
            if ($type === CarrierRegion::TYPE_COUNTRY) {
                $regionId = 0;
                $regionCode = $country;
                $streetId = 0;
            } elseif ($type === CarrierRegion::TYPE_STREET) {
                if ($streetId <= 0 && $regionCode === '') {
                    continue;
                }
            } elseif ($regionId <= 0 && $regionCode === '') {
                continue;
            }

            $key = $type . '|' . $country . '|' . $regionId . '|' . strtoupper($regionCode) . '|' . $streetId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'region_type' => $type,
                'country_code' => $country,
                'region_id' => $regionId > 0 ? $regionId : null,
                'region_code' => $regionCode,
                'street_id' => $streetId > 0 ? $streetId : null,
            ];
        }

        return $out;
    }
}
