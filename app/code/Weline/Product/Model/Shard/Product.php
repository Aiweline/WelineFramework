<?php

declare(strict_types=1);

namespace Weline\Product\Model\Shard;

final class Product extends AbstractWebsiteShardModel
{
    public const schema_primary_key = 'product_id';
    public const schema_fields_ID = 'product_id';
    public const schema_fields_SKU = 'sku';
    public const schema_fields_GLOBAL_PRODUCT_UUID = 'global_product_uuid';
    public const schema_fields_STATUS = 'status';
    public const schema_fields_PUBLISH_VERSION = 'publish_version';
    public const schema_fields_CAS_TOKEN = 'cas_token';
    public const schema_fields_CREATED_AT = 'created_at';
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    public static function entityCode(): string
    {
        return 'product';
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
