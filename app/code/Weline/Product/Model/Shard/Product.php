<?php

declare(strict_types=1);

namespace Weline\Product\Model\Shard;

final class Product extends AbstractWebsiteShardModel
{
    public const schema_primary_key = 'product_id';
    public const schema_fields_ID = 'product_id';
    public const schema_fields_SKU = 'sku';
    public const schema_fields_GLOBAL_PRODUCT_UUID = 'global_product_uuid';
    public const schema_fields_PRODUCT_CODE = 'product_code';
    public const schema_fields_OWNER_WEBSITE_ID = 'owner_website_id';
    public const schema_fields_PROVIDER_CODE = 'provider_code';
    public const schema_fields_PRODUCT_TYPE = 'product_type';
    public const schema_fields_IDENTITY_VERSION = 'identity_version';
    public const schema_fields_SOURCE_WEBSITE_ID = 'source_website_id';
    public const schema_fields_SOURCE_VERSION = 'source_version';
    public const schema_fields_STATUS = 'status';
    public const schema_fields_PUBLISH_VERSION = 'publish_version';
    public const schema_fields_CAS_TOKEN = 'cas_token';
    public const schema_fields_CREATED_AT = 'created_at';
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_ARCHIVED = 'archived';

    public static function entityCode(): string
    {
        return 'product';
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
