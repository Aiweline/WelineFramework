<?php

declare(strict_types=1);

namespace Weline\Product\Model\Shard;

final class CategoryLink extends AbstractWebsiteShardModel
{
    public const schema_primary_key = 'link_id';
    public const schema_fields_ID = 'link_id';
    public const schema_fields_CATEGORY_ID = 'category_id';
    public const schema_fields_PRODUCT_ID = 'product_id';

    public static function entityCode(): string
    {
        return 'category_link';
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
