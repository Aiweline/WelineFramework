<?php

declare(strict_types=1);

namespace Weline\Product\Model\Shard;

final class Category extends AbstractWebsiteShardModel
{
    public const schema_primary_key = 'category_id';
    public const schema_fields_ID = 'category_id';
    public const schema_fields_GLOBAL_CATEGORY_UUID = 'global_category_uuid';
    public const schema_fields_PARENT_ID = 'parent_id';
    public const schema_fields_PATH = 'path';
    public const schema_fields_POSITION = 'position';
    public const schema_fields_STATUS = 'status';

    public static function entityCode(): string
    {
        return 'category';
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
