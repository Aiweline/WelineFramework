<?php

declare(strict_types=1);

namespace Weline\Product\Model\Shard;

final class CategoryLink extends AbstractWebsiteShardModel
{
    public const schema_primary_key = 'link_id';
    public const schema_fields_ID = 'link_id';
    public const schema_fields_CATEGORY_ID = 'category_id';
    public const schema_fields_PRODUCT_ID = 'product_id';
    public const schema_fields_STORE_ID = 'store_id';
    public const schema_fields_SCOPE_STATE = 'scope_state';
    public const schema_fields_SELECTED = 'selected';
    public const schema_fields_POSITION = 'position';

    public static function entityCode(): string
    {
        return 'category_link';
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
