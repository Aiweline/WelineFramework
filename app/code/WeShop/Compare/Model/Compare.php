<?php
declare(strict_types=1);
namespace WeShop\Compare\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * 商品对比模型
 */
#[Table(comment: 'WeShop商品对比表')]
#[Index(name: 'idx_customer_id', columns: ['customer_id'], comment: '客户ID索引')]
#[Index(name: 'idx_product_id', columns: ['product_id'], comment: '产品ID索引')]
#[Index(name: 'idx_customer_product', columns: ['customer_id', 'product_id'], type: 'UNIQUE', comment: '客户产品唯一索引')]
class Compare extends Model
{
    public const schema_table = 'weshop_compare';
    public const schema_primary_key = 'compare_id';
    public string $indexer = 'compare_indexer';
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '对比ID')]
    public const schema_fields_ID = 'compare_id';
    #[Col(type: 'int', nullable: false, comment: '客户ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col(type: 'int', nullable: false, comment: '产品ID')]
    public const schema_fields_PRODUCT_ID = 'product_id';
    #[Col(type: 'datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    public array $_unit_primary_keys = ['compare_id'];
    public array $_index_sort_keys = ['customer_id', 'product_id'];
}
