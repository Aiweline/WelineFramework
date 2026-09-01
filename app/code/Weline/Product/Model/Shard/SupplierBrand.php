<?php

declare(strict_types=1);

namespace Weline\Product\Model\Shard;

/**
 * Many-to-many supplier↔brand link (website shard).
 */
final class SupplierBrand extends AbstractWebsiteShardModel
{
    public const schema_primary_key = 'link_id';
    public const schema_fields_ID = 'link_id';
    public const schema_fields_SUPPLIER_ID = 'supplier_id';
    public const schema_fields_BRAND_ID = 'brand_id';
    public const schema_fields_POSITION = 'position';
    public const schema_fields_CREATED_AT = 'created_at';

    public static function entityCode(): string
    {
        return 'supplier_brand';
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
