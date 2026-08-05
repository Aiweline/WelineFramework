<?php

declare(strict_types=1);

namespace Weline\Product\Model\Shard;

final class StoreProduct extends AbstractWebsiteShardModel
{
    public const schema_primary_key = 'store_product_id';
    public const schema_fields_ID = 'store_product_id';
    public const schema_fields_STORE_ID = 'store_id';
    public const schema_fields_PRODUCT_ID = 'product_id';
    public const schema_fields_SELECTED = 'selected';

    public static function entityCode(): string
    {
        return 'store_product';
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
