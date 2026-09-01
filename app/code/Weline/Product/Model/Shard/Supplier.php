<?php

declare(strict_types=1);

namespace Weline\Product\Model\Shard;

final class Supplier extends AbstractWebsiteShardModel
{
    public const schema_primary_key = 'supplier_id';
    public const schema_fields_ID = 'supplier_id';
    public const schema_fields_GLOBAL_SUPPLIER_UUID = 'global_supplier_uuid';
    public const schema_fields_CODE = 'code';
    public const schema_fields_NAME = 'name';
    public const schema_fields_STORE_URL = 'store_url';
    public const schema_fields_IMAGE_URL = 'image_url';
    public const schema_fields_IMAGE_ASSET_ID = 'image_asset_id';
    public const schema_fields_CONTACT_NAME = 'contact_name';
    public const schema_fields_CONTACT_PHONE = 'contact_phone';
    public const schema_fields_CONTACT_EMAIL = 'contact_email';
    public const schema_fields_DEFAULT_CURRENCY = 'default_currency';
    public const schema_fields_DEFAULT_PAYMENT_TERMS = 'default_payment_terms';
    public const schema_fields_DEFAULT_LEAD_TIME_DAYS = 'default_lead_time_days';
    public const schema_fields_DEFAULT_MOQ = 'default_moq';
    public const schema_fields_DESCRIPTION = 'description';
    public const schema_fields_STATUS = 'status';
    public const schema_fields_POSITION = 'position';
    public const schema_fields_CREATED_AT = 'created_at';
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    public static function entityCode(): string
    {
        return 'supplier';
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
