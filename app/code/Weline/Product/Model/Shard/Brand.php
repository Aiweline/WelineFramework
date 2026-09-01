<?php

declare(strict_types=1);

namespace Weline\Product\Model\Shard;

final class Brand extends AbstractWebsiteShardModel
{
    public const schema_primary_key = 'brand_id';
    public const schema_fields_ID = 'brand_id';
    public const schema_fields_GLOBAL_BRAND_UUID = 'global_brand_uuid';
    public const schema_fields_CODE = 'code';
    public const schema_fields_NAME = 'name';
    public const schema_fields_LOGO_URL = 'logo_url';
    public const schema_fields_LOGO_ASSET_ID = 'logo_asset_id';
    public const schema_fields_DESCRIPTION = 'description';
    public const schema_fields_STATUS = 'status';
    public const schema_fields_POSITION = 'position';
    public const schema_fields_CREATED_AT = 'created_at';
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    public static function entityCode(): string
    {
        return 'brand';
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
