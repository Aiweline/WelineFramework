<?php

declare(strict_types=1);

namespace Weline\Product\Model;

/**
 * Canonical Product website shard key helpers.
 *
 * Shard key is the decimal string of website_id (including "0").
 * Negative website_id is illegal; leading zeros are rejected.
 */
final class ProductShardKey
{
    public const FAMILY_CODE = 'product.website';

    /** @var list<string> */
    public const ENTITY_CODES = [
        'product',
        'offer',
        'category',
        'category_link',
        'attribute_value',
        'price',
        'media',
        'store_product',
        'store_offer',
    ];

    public static function fromWebsiteId(int $websiteId): string
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException(__('website_id 不能为负数：%{1}', [$websiteId]));
        }

        return (string)$websiteId;
    }

    public static function parse(string $shardKey): int
    {
        $shardKey = trim($shardKey);
        if ($shardKey === '' || !preg_match('/^(0|[1-9]\d*)$/', $shardKey)) {
            throw new \InvalidArgumentException(__('非法 product shard key：%{1}', [$shardKey]));
        }

        $max = (string)PHP_INT_MAX;
        if (strlen($shardKey) > strlen($max)
            || (strlen($shardKey) === strlen($max) && strcmp($shardKey, $max) > 0)
        ) {
            throw new \InvalidArgumentException(__('product shard key 超出整数范围：%{1}', [$shardKey]));
        }

        return (int)$shardKey;
    }

    public static function tableName(string $shardKey, string $entity): string
    {
        self::parse($shardKey);
        $entity = trim($entity);
        if (!in_array($entity, self::ENTITY_CODES, true)) {
            throw new \InvalidArgumentException(__('非法 product shard 实体名：%{1}', [$entity]));
        }

        return 'product_ws_' . $shardKey . '_' . $entity;
    }
}
