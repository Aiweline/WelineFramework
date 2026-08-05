<?php

declare(strict_types=1);

namespace Weline\Product\Model\Shard;

final class Media extends AbstractWebsiteShardModel
{
    public const schema_primary_key = 'media_id';
    public const schema_fields_ID = 'media_id';
    public const schema_fields_PRODUCT_ID = 'product_id';
    public const schema_fields_PATH = 'path';
    public const schema_fields_BLOB_KEY = 'blob_key';
    public const schema_fields_REF_COUNT = 'ref_count';
    public const schema_fields_COW_SOURCE_MEDIA_ID = 'cow_source_media_id';
    public const schema_fields_CAS_TOKEN = 'cas_token';
    public const schema_fields_POSITION = 'position';

    public static function entityCode(): string
    {
        return 'media';
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
