<?php

declare(strict_types=1);

namespace Weline\Checkout\Api;

interface OrderItemSchemaInterface
{
    public const schema_table = 'weline_checkout_order_item';
    public const schema_primary_key = 'item_id';
    public const schema_primary_keys = ['item_id'];

    public const schema_fields_ID = 'item_id';
    public const schema_fields_ORDER_ID = 'order_id';
    public const schema_fields_PRODUCT_ID = 'product_id';
    public const schema_fields_PRODUCT_NAME = 'product_name';
    public const schema_fields_PRODUCT_SKU = 'product_sku';
    public const schema_fields_SOURCE_APP = 'source_app';
    public const schema_fields_SOURCE_MODULE = 'source_module';
    public const schema_fields_BUSINESS_CODE = 'business_code';
    public const schema_fields_BUSINESS_NAME = 'business_name';
    public const schema_fields_QUANTITY = 'quantity';
    public const schema_fields_PRICE = 'price';
    public const schema_fields_TOTAL_PRICE = 'total_price';
    public const schema_fields_ATTRIBUTES = 'attributes';
    public const schema_fields_CREATED_TIME = 'created_time';
}
