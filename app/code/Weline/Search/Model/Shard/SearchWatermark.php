<?php

declare(strict_types=1);

namespace Weline\Search\Model\Shard;

final class SearchWatermark extends AbstractSearchWebsiteShardModel
{
    public const schema_primary_key = 'watermark_id';
    public const schema_fields_ID = 'watermark_id';
    public const schema_fields_WEBSITE_ID = 'website_id';
    public const schema_fields_ACTIVE_GENERATION = 'active_generation';
    public const schema_fields_BUILD_GENERATION = 'build_generation';
    public const schema_fields_BUILD_SOURCE_WATERMARK = 'build_source_watermark';
    public const schema_fields_FULL_WATERMARK = 'full_watermark';
    public const schema_fields_INCREMENTAL_WATERMARK = 'incremental_watermark';
    public const schema_fields_BUILD_TOKEN = 'build_token';
    public const schema_fields_BUILD_STATUS = 'build_status';
    public const schema_fields_SHARD_FINGERPRINT = 'shard_fingerprint';
    public const schema_fields_ROW_VERSION = 'row_version';
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const BUILD_IDLE = 'idle';
    public const BUILD_BUILDING = 'building';
    public const BUILD_SOURCE_ADVANCED = 'source_advanced';

    public static function entityCode(): string
    {
        return 'watermark';
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
