<?php
declare(strict_types=1);
namespace WeShop\Order\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: 'WeShop订单表')]
#[Index(name: 'uk_increment_id', columns: ['increment_id'], type: 'UNIQUE', comment: '订单号唯一')]
class Order extends Model
{
    public const schema_table = 'weshop_order';
    public const schema_primary_key = 'order_id';
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '订单ID')]
    public const schema_fields_ID = 'order_id';
    #[Col(type: 'varchar', length: 32, nullable: false, comment: '订单号')]
    public const schema_fields_increment_id = 'increment_id';
    #[Col(type: 'int', nullable: false, comment: '客户ID')]
    public const schema_fields_customer_id = 'customer_id';
    #[Col(type: 'varchar', length: 50, nullable: true, default: 'pending', comment: '订单状态')]
    public const schema_fields_status = 'status';
    #[Col(type: 'decimal', length: '10,2', nullable: true, default: '0.00', comment: '订单总额')]
    public const schema_fields_total = 'total';
    #[Col(type: 'decimal', length: '10,2', nullable: true, default: '0.00', comment: 'Subtotal')]
    public const schema_fields_subtotal = 'subtotal';
    #[Col(type: 'decimal', length: '10,2', nullable: true, default: '0.00', comment: 'Shipping amount')]
    public const schema_fields_shipping_amount = 'shipping_amount';
    #[Col(type: 'decimal', length: '10,2', nullable: true, default: '0.00', comment: 'Discount amount')]
    public const schema_fields_discount_amount = 'discount_amount';
    #[Col(type: 'decimal', length: '10,2', nullable: true, default: '0.00', comment: 'Tax amount')]
    public const schema_fields_tax_amount = 'tax_amount';
    #[Col(type: 'varchar', length: 32, nullable: true, default: 'pending', comment: 'Payment status')]
    public const schema_fields_payment_status = 'payment_status';
    #[Col(type: 'varchar', length: 32, nullable: true, default: 'pending', comment: 'Fulfillment status')]
    public const schema_fields_fulfillment_status = 'fulfillment_status';
    #[Col(type: 'varchar', length: 32, nullable: true, default: 'none', comment: 'Return status')]
    public const schema_fields_return_status = 'return_status';
    #[Col(type: 'varchar', length: 100, nullable: true, comment: 'Shipping method')]
    public const schema_fields_shipping_method = 'shipping_method';
    #[Col(type: 'varchar', length: 100, nullable: true, comment: 'Payment method')]
    public const schema_fields_payment_method = 'payment_method';
    #[Col(type: 'text', nullable: true, comment: 'Shipping address JSON')]
    public const schema_fields_shipping_address = 'shipping_address';
    #[Col(type: 'varchar', length: 120, nullable: true, comment: 'Fulfillment carrier')]
    public const schema_fields_fulfillment_carrier = 'fulfillment_carrier';
    #[Col(type: 'varchar', length: 120, nullable: true, comment: 'Fulfillment tracking number')]
    public const schema_fields_fulfillment_tracking_number = 'fulfillment_tracking_number';
    #[Col(type: 'datetime', nullable: true, comment: 'Shipped at')]
    public const schema_fields_shipped_at = 'shipped_at';
    #[Col(type: 'datetime', nullable: true, comment: 'Delivered at')]
    public const schema_fields_delivered_at = 'delivered_at';
    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_created_at = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_updated_at = 'updated_at';
    public string $indexer = 'order_indexer';
    public array $_unit_primary_keys = ['order_id'];
    public array $_index_sort_keys = ['order_id', 'increment_id', 'customer_id', 'status', 'payment_status', 'fulfillment_status', 'total', 'subtotal', 'created_at'];
}
