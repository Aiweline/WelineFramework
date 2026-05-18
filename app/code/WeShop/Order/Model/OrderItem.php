<?php
declare(strict_types=1);
namespace WeShop\Order\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * 订单项模型
 */
#[Table(comment: 'WeShop订单项表')]
#[Index(name: 'idx_order_id', columns: ['order_id'], type: 'KEY', comment: '订单ID索引')]
#[Index(name: 'idx_product_id', columns: ['product_id'], type: 'KEY', comment: '产品ID索引')]
class OrderItem extends Model
{
    public const schema_table = 'weshop_order_item';
    public const schema_primary_key = 'item_id';
    public string $indexer = 'order_item_indexer';
    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '订单项ID')]
    public const schema_fields_ID = 'item_id';
    #[Col('int', 0, nullable: false, comment: '订单ID')]
    public const schema_fields_ORDER_ID = 'order_id';
    #[Col('int', 0, nullable: false, comment: '产品ID')]
    public const schema_fields_PRODUCT_ID = 'product_id';
    #[Col('varchar', 255, nullable: false, comment: '产品名称')]
    public const schema_fields_PRODUCT_NAME = 'product_name';
    #[Col('varchar', 100, nullable: true, comment: '产品SKU')]
    public const schema_fields_PRODUCT_SKU = 'product_sku';
    #[Col('varchar', 255, nullable: true, comment: 'Product image snapshot')]
    public const schema_fields_PRODUCT_IMAGE = 'product_image';
    #[Col('int', 0, nullable: false, default: 1, comment: '数量')]
    public const schema_fields_QUANTITY = 'quantity';
    #[Col('decimal', '10,2', nullable: false, default: '0.00', comment: '单价')]
    public const schema_fields_PRICE = 'price';
    #[Col('decimal', '10,2', nullable: false, default: '0.00', comment: '小计')]
    public const schema_fields_TOTAL = 'total';
    #[Col('datetime', 0, nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    public array $_unit_primary_keys = ['item_id'];
    public array $_index_sort_keys = ['order_id', 'product_id'];
}
