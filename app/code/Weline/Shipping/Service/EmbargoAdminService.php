<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\EmbargoRegion;

/**
 * 后台：按 scope 全量替换禁运区域配置。
 */
final class EmbargoAdminService
{
    public const SCOPE_TYPES = [
        EmbargoRegion::SCOPE_WEBSITE,
        EmbargoRegion::SCOPE_STORE,
        EmbargoRegion::SCOPE_CHANNEL,
    ];

    public const REGION_TYPES = [
        EmbargoRegion::TYPE_COUNTRY,
        EmbargoRegion::TYPE_PROVINCE,
        EmbargoRegion::TYPE_CITY,
        EmbargoRegion::TYPE_DISTRICT,
        EmbargoRegion::TYPE_STREET,
    ];

    public function __construct(
        private readonly ObjectManager $objectManager
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForScope(string $scopeType, int $scopeId): array
    {
        $scopeType = $this->normalizeScopeType($scopeType);
        /** @var EmbargoRegion $model */
        $model = $this->objectManager->getInstance(EmbargoRegion::class);
        $items = $model->reset()
            ->where(EmbargoRegion::schema_fields_SCOPE_TYPE, $scopeType)
            ->where(EmbargoRegion::schema_fields_SCOPE_ID, $scopeId)
            ->where(EmbargoRegion::schema_fields_IS_ACTIVE, 1)
            ->order(EmbargoRegion::schema_fields_ID, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
        $out = [];
        foreach ($items as $item) {
            if (!$item instanceof EmbargoRegion) {
                continue;
            }
            $rid = (int)$item->getData(EmbargoRegion::schema_fields_REGION_ID);
            $sid = (int)$item->getData(EmbargoRegion::schema_fields_STREET_ID);
            $type = (string)$item->getData(EmbargoRegion::schema_fields_REGION_TYPE);
            $country = (string)$item->getData(EmbargoRegion::schema_fields_COUNTRY_CODE);
            $code = (string)$item->getData(EmbargoRegion::schema_fields_REGION_CODE);
            $label = $type === EmbargoRegion::TYPE_COUNTRY
                ? $this->countryDisplayName($country)
                : ($code !== '' ? $code : ($rid > 0 ? '#' . $rid : $country));
            $out[] = [
                'embargo_id' => (int)$item->getId(),
                'scope_type' => (string)$item->getData(EmbargoRegion::schema_fields_SCOPE_TYPE),
                'scope_id' => (int)$item->getData(EmbargoRegion::schema_fields_SCOPE_ID),
                'region_type' => $type,
                'country_code' => $country,
                'region_id' => $rid > 0 ? $rid : null,
                'region_code' => $code,
                'street_id' => $sid > 0 ? $sid : null,
                'label' => $label,
                'region_name' => $label,
            ];
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
        $code = strtoupper(trim($countryCode));

        return $map[$code] ?? ($code !== '' ? $code : (string)__('未知'));
    }

    /**
     * @param list<array<string, mixed>>|array<string, mixed> $rows
     */
    public function replaceForScope(string $scopeType, int $scopeId, array $rows): int
    {
        if (strtolower(trim($scopeType)) === EmbargoRegion::SCOPE_SYSTEM) {
            throw new \InvalidArgumentException(SystemEmbargoAdminService::ERROR_REPLACE_FORBIDDEN);
        }
        $scopeType = $this->normalizeScopeType($scopeType);
        $normalized = $this->normalizeRows($rows);

        /** @var EmbargoRegion $model */
        $model = $this->objectManager->getInstance(EmbargoRegion::class);
        $existing = $model->reset()
            ->where(EmbargoRegion::schema_fields_SCOPE_TYPE, $scopeType)
            ->where(EmbargoRegion::schema_fields_SCOPE_ID, $scopeId)
            ->select()
            ->fetch()
            ->getItems();
        foreach ($existing as $item) {
            if ($item instanceof EmbargoRegion && $item->getId()) {
                $item->delete();
            }
        }

        $now = date('Y-m-d H:i:s');
        $count = 0;
        foreach ($normalized as $row) {
            /** @var EmbargoRegion $fresh */
            $fresh = $this->objectManager->getInstance(EmbargoRegion::class, [], false);
            $fresh->clear()->setData([
                EmbargoRegion::schema_fields_SCOPE_TYPE => $scopeType,
                EmbargoRegion::schema_fields_SCOPE_ID => $scopeId,
                EmbargoRegion::schema_fields_REGION_TYPE => $row['region_type'],
                EmbargoRegion::schema_fields_COUNTRY_CODE => $row['country_code'],
                EmbargoRegion::schema_fields_REGION_ID => (int)($row['region_id'] ?? 0),
                EmbargoRegion::schema_fields_REGION_CODE => (string)($row['region_code'] ?? ''),
                EmbargoRegion::schema_fields_STREET_ID => (int)($row['street_id'] ?? 0),
                EmbargoRegion::schema_fields_REASON_CODE => '',
                EmbargoRegion::schema_fields_IS_ACTIVE => 1,
                EmbargoRegion::schema_fields_DISABLED_BY => '',
                EmbargoRegion::schema_fields_CREATED_AT => $now,
                EmbargoRegion::schema_fields_UPDATED_AT => $now,
            ])->save();
            $count++;
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $embargoPayload extensions[shipping][embargo]
     * @return list<array<string, mixed>>
     */
    public function rowsFromExtensionPayload(array $embargoPayload): array
    {
        $raw = $embargoPayload['rows'] ?? $embargoPayload['json'] ?? null;
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
     * @param list<array<string, mixed>>|array<string, mixed> $rows
     * @return list<array{region_type:string,country_code:string,region_id:?int,region_code:string,street_id:?int}>
     */
    private function normalizeRows(array $rows): array
    {
        // 支持 {0:{...},1:{...}} 或 list
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
            if ($type === EmbargoRegion::TYPE_COUNTRY) {
                $regionId = 0;
                $regionCode = $country;
                $streetId = 0;
            } elseif ($type === EmbargoRegion::TYPE_STREET) {
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

    private function normalizeScopeType(string $scopeType): string
    {
        $scopeType = strtolower(trim($scopeType));
        if (!in_array($scopeType, self::SCOPE_TYPES, true)) {
            throw new \InvalidArgumentException('Invalid embargo scope_type: ' . $scopeType);
        }

        return $scopeType;
    }
}
