<?php

declare(strict_types=1);

namespace Weline\Product\Model\Shard;

final class Offer extends AbstractWebsiteShardModel
{
    public const schema_primary_key = 'offer_id';
    public const schema_fields_ID = 'offer_id';
    public const schema_fields_PRODUCT_ID = 'product_id';
    public const schema_fields_GLOBAL_OFFER_UUID = 'global_offer_uuid';
    public const schema_fields_SKU = 'sku';
    public const schema_fields_IDENTITY_VERSION = 'identity_version';
    public const schema_fields_COMBINATION_KEY = 'combination_key';
    public const schema_fields_IS_DEFAULT = 'is_default';
    public const schema_fields_REQUIRES_SHIPPING = 'requires_shipping';
    public const schema_fields_TYPE_CONFIG_JSON = 'type_config_json';
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
        return 'offer';
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
