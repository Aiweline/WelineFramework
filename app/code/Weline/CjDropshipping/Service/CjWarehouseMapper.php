<?php

declare(strict_types=1);

namespace Weline\CjDropshipping\Service;

/**
 * CJ globalWarehouseList 行 → 私有仓表字段。
 * 官方列表以 areaId 为仓唯一标识；若行内带 storageId 则优先（下单可选）。
 */
final class CjWarehouseMapper
{
    /**
     * @param array<string, mixed> $row
     * @return array{external_id:string,name:string,country_code:string,enabled:int}|null
     */
    public static function mapApiRow(array $row): ?array
    {
        $id = trim((string)($row['storageId'] ?? $row['storage_id'] ?? ''));
        if ($id === '') {
            $id = trim((string)($row['areaId'] ?? $row['id'] ?? ''));
        }
        if ($id === '') {
            return null;
        }
        $name = trim((string)($row['areaEn'] ?? $row['areaCn'] ?? $row['en'] ?? $row['zh'] ?? $row['name'] ?? $id));
        $country = strtoupper(trim((string)($row['countryCode'] ?? $row['valueEn'] ?? '')));
        $enabled = empty($row['disabled']) ? 1 : 0;

        return [
            'external_id' => $id,
            'name' => $name !== '' ? $name : $id,
            'country_code' => $country,
            'enabled' => $enabled,
        ];
    }
}
