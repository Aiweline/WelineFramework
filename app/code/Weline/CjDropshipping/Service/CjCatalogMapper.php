<?php

declare(strict_types=1);

namespace Weline\CjDropshipping\Service;

use Weline\Dropship\Api\Data\DropshipCatalogSnapshot;

/**
 * Normalize CJ product list payloads into shell DropshipCatalogSnapshot rows.
 */
final class CjCatalogMapper
{
    /**
     * @param array<string, mixed> $resp
     * @return list<array<string, mixed>>
     */
    public static function extractList(array $resp): array
    {
        $data = $resp['data'] ?? null;
        if (!is_array($data)) {
            return [];
        }

        foreach (['list', 'content', 'records', 'rows', 'productList'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return self::listOfMaps($data[$key]);
            }
        }

        // Some CJ payloads return a bare product array under data.
        if (array_is_list($data)) {
            return self::listOfMaps($data);
        }

        return [];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row, string $countryCode = '', string $locale = ''): ?DropshipCatalogSnapshot
    {
        $pid = self::firstString($row, ['pid', 'id', 'productId', 'product_id', 'spu']);
        $preferZh = self::localePrefersZh($locale);
        $en = self::firstString($row, ['productNameEn', 'nameEn', 'title']);
        $zh = self::firstString($row, ['productName', 'productNameCn', 'productNameZh', 'name']);
        $title = $preferZh
            ? ($zh !== '' ? $zh : $en)
            : ($en !== '' ? $en : $zh);
        if ($pid === '' && $title === '') {
            return null;
        }
        if ($title === '') {
            $title = $pid !== '' ? ('CJ #' . $pid) : 'CJ product';
        }

        $price = self::firstFloat($row, ['sellPrice', 'nowPrice', 'price', 'discountPrice', 'productPrice']);
        $qty = (int)self::firstFloat($row, ['warehouseInventoryNum', 'listedNum', 'inventory', 'stock', 'qty']);

        return DropshipCatalogSnapshot::fromArray([
            'provider_code' => 'cj',
            'external_spu' => $pid,
            'external_sku' => self::firstString($row, ['productSku', 'sku', 'variantSku']),
            'title' => $title,
            'origin_currency' => 'USD',
            'origin_price_minor' => (int)round($price * 100),
            'qty' => $qty,
            'shelf_status' => 'active',
            'country_code' => $countryCode,
            'raw' => $row,
            'suggested_eav' => ['dropship_source' => 'cj'],
        ]);
    }

    public static function localePrefersZh(string $locale): bool
    {
        $locale = strtolower(str_replace('-', '_', trim($locale)));
        if ($locale === '') {
            return true;
        }

        return str_starts_with($locale, 'zh');
    }

    /**
     * @param array<int|string, mixed> $rows
     * @return list<array<string, mixed>>
     */
    private static function listOfMaps(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $keys
     */
    private static function firstString(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            $v = trim((string)$row[$key]);
            if ($v !== '') {
                return $v;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $keys
     */
    private static function firstFloat(array $row, array $keys): float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row) || $row[$key] === '' || $row[$key] === null) {
                continue;
            }
            if (is_numeric($row[$key])) {
                return (float)$row[$key];
            }
        }

        return 0.0;
    }
}
