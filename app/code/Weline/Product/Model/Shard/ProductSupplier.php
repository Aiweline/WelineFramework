<?php

declare(strict_types=1);

namespace Weline\Product\Model\Shard;

/**
 * Per-product supplier offer / sourcing link (website shard).
 */
final class ProductSupplier extends AbstractWebsiteShardModel
{
    public const schema_primary_key = 'link_id';
    public const schema_fields_ID = 'link_id';
    public const schema_fields_PRODUCT_ID = 'product_id';
    public const schema_fields_SUPPLIER_ID = 'supplier_id';
    public const schema_fields_IS_PRIMARY = 'is_primary';
    public const schema_fields_SUPPLIER_PRODUCT_URL = 'supplier_product_url';
    public const schema_fields_SUPPLIER_SKU = 'supplier_sku';
    public const schema_fields_SUPPLIER_PRODUCT_NAME = 'supplier_product_name';
    public const schema_fields_CURRENCY = 'currency';
    public const schema_fields_UNIT_PRICE_MINOR = 'unit_price_minor';
    public const schema_fields_LIST_PRICE_MINOR = 'list_price_minor';
    public const schema_fields_MOQ = 'moq';
    public const schema_fields_LEAD_TIME_DAYS = 'lead_time_days';
    public const schema_fields_PACK_QTY = 'pack_qty';
    public const schema_fields_LAST_QUOTED_AT = 'last_quoted_at';
    public const schema_fields_NOTES = 'notes';
    public const schema_fields_STATUS = 'status';
    public const schema_fields_CREATED_AT = 'created_at';
    public const schema_fields_UPDATED_AT = 'updated_at';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    public static function entityCode(): string
    {
        return 'product_supplier';
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
