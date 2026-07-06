<?php

declare(strict_types=1);

namespace Weline\Checkout\Api;

interface OrderSchemaInterface
{
    public const schema_table = 'weline_checkout_order';
    public const schema_primary_key = 'order_id';
    public const schema_primary_keys = ['order_id'];

    public const schema_fields_ID = 'order_id';
    public const schema_fields_ORDER_NUMBER = 'order_number';
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    public const schema_fields_STATUS = 'status';
    public const schema_fields_SUBTOTAL = 'subtotal';
    public const schema_fields_SHIPPING_AMOUNT = 'shipping_amount';
    public const schema_fields_TAX_AMOUNT = 'tax_amount';
    public const schema_fields_DISCOUNT_AMOUNT = 'discount_amount';
    public const schema_fields_TOTAL_AMOUNT = 'total_amount';
    public const schema_fields_CURRENCY = 'currency';
    public const schema_fields_SOURCE_APP = 'source_app';
    public const schema_fields_SOURCE_MODULE = 'source_module';
    public const schema_fields_BUSINESS_CODE = 'business_code';
    public const schema_fields_BUSINESS_NAME = 'business_name';
    public const schema_fields_SHIPPING_ADDRESS = 'shipping_address';
    public const schema_fields_BILLING_ADDRESS = 'billing_address';
    public const schema_fields_PAYMENT_METHOD = 'payment_method';
    public const schema_fields_PAYMENT_STATUS = 'payment_status';
    public const schema_fields_SHIPPING_METHOD = 'shipping_method';
    public const schema_fields_SHIPPING_STATUS = 'shipping_status';
    public const schema_fields_REMARK = 'remark';
    public const schema_fields_CREATED_TIME = 'created_time';
    public const schema_fields_UPDATED_TIME = 'updated_time';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';

    public const PAYMENT_STATUS_PENDING = 'pending';
    public const PAYMENT_STATUS_PAID = 'paid';
    public const PAYMENT_STATUS_FAILED = 'failed';
    public const PAYMENT_STATUS_REFUNDED = 'refunded';

    public const SHIPPING_STATUS_PENDING = 'pending';
    public const SHIPPING_STATUS_SHIPPED = 'shipped';
    public const SHIPPING_STATUS_DELIVERED = 'delivered';
}
