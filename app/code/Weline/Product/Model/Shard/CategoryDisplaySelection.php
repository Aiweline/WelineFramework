<?php

declare(strict_types=1);

namespace Weline\Product\Model\Shard;

/**
 * Store/Channel display selection for website category tree nodes.
 */
final class CategoryDisplaySelection extends AbstractWebsiteShardModel
{
    public const schema_primary_key = 'selection_id';
    public const schema_fields_ID = 'selection_id';
    public const schema_fields_STORE_ID = 'store_id';
    public const schema_fields_CHANNEL_ID = 'channel_id';
    public const schema_fields_CATEGORY_ID = 'category_id';
    public const schema_fields_ENABLED = 'enabled';
    public const schema_fields_POSITION = 'position';

    public static function entityCode(): string
    {
        return 'category_display_selection';
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
