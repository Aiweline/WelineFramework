<?php

declare(strict_types=1);

namespace Weline\Search\Model\Shard;

final class SearchDocument extends AbstractSearchWebsiteShardModel
{
    public const schema_primary_key = 'document_id';
    public const schema_fields_ID = 'document_id';
    public const schema_fields_ENTITY_TYPE = 'entity_type';
    public const schema_fields_ENTITY_ID = 'entity_id';
    public const schema_fields_WEBSITE_ID = 'website_id';
    public const schema_fields_WEBSITE_CODE = 'website_code';
    public const schema_fields_STORE_ID = 'store_id';
    public const schema_fields_STORE_CODE = 'store_code';
    public const schema_fields_CHANNEL_ID = 'channel_id';
    public const schema_fields_CHANNEL_CODE = 'channel_code';
    public const schema_fields_LOCALE = 'locale';
    public const schema_fields_CURRENCY = 'currency';
    public const schema_fields_GENERATION = 'generation';
    public const schema_fields_DOCUMENT_VERSION = 'document_version';
    public const schema_fields_PAYLOAD_HASH = 'payload_hash';
    public const schema_fields_TITLE = 'title';
    public const schema_fields_SKU = 'sku';
    public const schema_fields_STATUS = 'status';
    public const schema_fields_UPDATED_AT = 'updated_at';

    public static function entityCode(): string
    {
        return 'document';
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
