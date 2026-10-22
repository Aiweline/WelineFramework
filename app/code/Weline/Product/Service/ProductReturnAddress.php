<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Repository\AttributeValueRepository;

/**
 * 商品自己的退货地址。存在商品属性 return_address（网站级、空 locale）。
 * 读失败或未填写视为没有，交给订单售后往下裁决。
 */
final class ProductReturnAddress
{
    public const ATTRIBUTE_CODE = 'return_address';

    public function __construct(private readonly ?AttributeValueRepository $attributes = null)
    {
    }

    /**
     * @param list<int> $productIds
     * @return array<int, string>
     */
    public function map(int $websiteId, array $productIds): array
    {
        if ($websiteId < 0 || $productIds === []) {
            return [];
        }
        try {
            $rows = $this->repository()->listExplicitRows(
                $websiteId,
                'product',
                $productIds,
                [0],
                [''],
                [self::ATTRIBUTE_CODE],
                true,
            );
        } catch (\Throwable) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            if (!\is_array($row) || !empty($row['cleared'])) {
                continue;
            }
            $productId = (int)($row['entity_id'] ?? 0);
            $text = trim((string)($row['value'] ?? ''));
            if ($productId > 0 && $text !== '') {
                $map[$productId] = $text;
            }
        }

        return $map;
    }

    private function repository(): AttributeValueRepository
    {
        return $this->attributes ?? ObjectManager::getInstance(AttributeValueRepository::class);
    }
}
