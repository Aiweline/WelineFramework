<?php

declare(strict_types=1);

namespace Weline\Order\Service;

/**
 * 退货地址优先级：商品地址 > 范围内已开启的自定义地址 > 订单履约仓。
 *
 * 不读配置、不查库。多个商品地址按 product_id 去重后换行拼接，不再落到自定义或订单仓。
 */
final class BuyerAftersaleReturnAddressResolver
{
    public const SOURCE_PRODUCT = 'product';
    public const SOURCE_CUSTOM = 'custom';
    public const SOURCE_ORDER = 'order';

    /**
     * @param array<int|string, string> $productAddresses
     * @param list<int> $warehouseIds
     * @return array{source:string,address:string,warehouse_ids:list<int>}
     */
    public function resolve(
        array $productAddresses,
        bool $customEnabled,
        string $customAddress,
        array $warehouseIds,
    ): array {
        $byProduct = [];
        foreach ($productAddresses as $productId => $address) {
            $id = (int)$productId;
            $text = trim((string)$address);
            if ($id <= 0 || $text === '') {
                continue;
            }
            $byProduct[$id] = $text;
        }
        ksort($byProduct);
        $distinct = [];
        foreach ($byProduct as $text) {
            if (!\in_array($text, $distinct, true)) {
                $distinct[] = $text;
            }
        }
        if ($distinct !== []) {
            return [
                'source' => self::SOURCE_PRODUCT,
                'address' => implode("\n", $distinct),
                'warehouse_ids' => [],
            ];
        }

        $custom = trim($customAddress);
        if ($customEnabled && $custom !== '') {
            return [
                'source' => self::SOURCE_CUSTOM,
                'address' => $custom,
                'warehouse_ids' => [],
            ];
        }

        $ids = [];
        foreach ($warehouseIds as $warehouseId) {
            $id = (int)$warehouseId;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        ksort($ids);

        return [
            'source' => self::SOURCE_ORDER,
            'address' => '',
            'warehouse_ids' => array_values($ids),
        ];
    }
}
