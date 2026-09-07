<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\DestinationRegion;

/**
 * 可售目的地后台：按 scope 全量替换。
 */
final class DestinationAdminService
{
    public const SCOPE_TYPES = [
        DestinationRegion::SCOPE_WEBSITE,
        DestinationRegion::SCOPE_STORE,
        DestinationRegion::SCOPE_CHANNEL,
    ];

    public const REGION_TYPES = [
        DestinationRegion::TYPE_COUNTRY,
        DestinationRegion::TYPE_PROVINCE,
        DestinationRegion::TYPE_CITY,
        DestinationRegion::TYPE_DISTRICT,
        DestinationRegion::TYPE_STREET,
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
        /** @var DestinationRegion $model */
        $model = $this->objectManager->getInstance(DestinationRegion::class);
        $items = $model->reset()
            ->where(DestinationRegion::schema_fields_SCOPE_TYPE, $scopeType)
            ->where(DestinationRegion::schema_fields_SCOPE_ID, $scopeId)
            ->where(DestinationRegion::schema_fields_IS_ACTIVE, 1)
            ->order(DestinationRegion::schema_fields_ID, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
        $out = [];
        foreach ($items as $item) {
            if (!$item instanceof DestinationRegion) {
                continue;
            }
            $rid = (int)$item->getData(DestinationRegion::schema_fields_REGION_ID);
            $sid = (int)$item->getData(DestinationRegion::schema_fields_STREET_ID);
            $type = (string)$item->getData(DestinationRegion::schema_fields_REGION_TYPE);
            $country = (string)$item->getData(DestinationRegion::schema_fields_COUNTRY_CODE);
            $code = (string)$item->getData(DestinationRegion::schema_fields_REGION_CODE);
            $label = $type === DestinationRegion::TYPE_COUNTRY
                ? $this->countryDisplayName($country)
                : ($code !== '' ? $code : ($rid > 0 ? '#' . $rid : $country));
            $out[] = [
                'destination_id' => (int)$item->getId(),
                'scope_type' => (string)$item->getData(DestinationRegion::schema_fields_SCOPE_TYPE),
                'scope_id' => (int)$item->getData(DestinationRegion::schema_fields_SCOPE_ID),
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
        $scopeType = $this->normalizeScopeType($scopeType);
        $normalized = $this->normalizeRows($rows);

        /** @var DestinationRegion $model */
        $model = $this->objectManager->getInstance(DestinationRegion::class);
        $existing = $model->reset()
            ->where(DestinationRegion::schema_fields_SCOPE_TYPE, $scopeType)
            ->where(DestinationRegion::schema_fields_SCOPE_ID, $scopeId)
            ->select()
            ->fetch()
            ->getItems();
        foreach ($existing as $item) {
            if ($item instanceof DestinationRegion && $item->getId()) {
                $item->delete();
            }
        }

        $now = date('Y-m-d H:i:s');
        $count = 0;
        foreach ($normalized as $row) {
            /** @var DestinationRegion $fresh */
            $fresh = $this->objectManager->getInstance(DestinationRegion::class, [], false);
            $fresh->clear()->setData([
                DestinationRegion::schema_fields_SCOPE_TYPE => $scopeType,
                DestinationRegion::schema_fields_SCOPE_ID => $scopeId,
                DestinationRegion::schema_fields_REGION_TYPE => $row['region_type'],
                DestinationRegion::schema_fields_COUNTRY_CODE => $row['country_code'],
                DestinationRegion::schema_fields_REGION_ID => $row['region_id'],
                DestinationRegion::schema_fields_REGION_CODE => $row['region_code'],
                DestinationRegion::schema_fields_STREET_ID => $row['street_id'],
                DestinationRegion::schema_fields_IS_ACTIVE => 1,
                DestinationRegion::schema_fields_CREATED_AT => $now,
                DestinationRegion::schema_fields_UPDATED_AT => $now,
            ])->save();
            $count++;
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $payload extensions[shipping][destination]
     * @return list<array<string, mixed>>
     */
    public function rowsFromExtensionPayload(array $payload): array
    {
        $raw = $payload['rows'] ?? $payload['json'] ?? null;
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
        // reuse same shape as embargo / carrier coverage
        $admin = new CarrierCoverageAdminService(
            $this->objectManager,
            $this->objectManager->getInstance(CarrierCoverageProviderRegistry::class),
        );

        return $admin->normalizeRows($rows);
    }

    private function normalizeScopeType(string $scopeType): string
    {
        $scopeType = strtolower(trim($scopeType));
        if (!in_array($scopeType, self::SCOPE_TYPES, true)) {
            throw new \InvalidArgumentException('Invalid destination scope_type: ' . $scopeType);
        }

        return $scopeType;
    }
}
