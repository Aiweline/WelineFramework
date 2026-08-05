<?php

declare(strict_types=1);

namespace Weline\Search\Model;

/**
 * Canonical Search website shard key helpers.
 *
 * Shard key is the decimal string of website_id (including "0").
 */
final class SearchShardKey
{
    public const FAMILY_CODE = 'search.website';
    public const ENTITY_CODES = ['document', 'watermark', 'applied_event'];

    public static function fromWebsiteId(int $websiteId): string
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException(__('website_id 不能为负数：%{1}', [$websiteId]));
        }

        return (string) $websiteId;
    }

    public static function parse(string $shardKey): int
    {
        $shardKey = trim($shardKey);
        if ($shardKey === '' || !preg_match('/^(0|[1-9]\d*)$/', $shardKey)) {
            throw new \InvalidArgumentException(__('非法 search shard key：%{1}', [$shardKey]));
        }

        return (int) $shardKey;
    }

    public static function tableName(string $shardKey, string $entity): string
    {
        self::parse($shardKey);
        $entity = trim($entity);
        if (!in_array($entity, self::ENTITY_CODES, true)) {
            throw new \InvalidArgumentException(__('非法 search shard 实体名：%{1}', [$entity]));
        }

        return 'search_ws_' . $shardKey . '_' . $entity;
    }
}
