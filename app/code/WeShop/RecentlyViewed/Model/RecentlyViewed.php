<?php
declare(strict_types=1);
namespace WeShop\RecentlyViewed\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * 最近浏览模型
 */
#[Table(comment: 'WeShop最近浏览表')]
#[Index(name: 'idx_customer_id', columns: ['customer_id'], type: 'KEY', comment: '客户ID索引')]
#[Index(name: 'idx_product_id', columns: ['product_id'], type: 'KEY', comment: '产品ID索引')]
#[Index(name: 'idx_viewed_at', columns: ['viewed_at'], type: 'KEY', comment: '浏览时间索引')]
class RecentlyViewed extends Model
{
    public const schema_table = 'weshop_recently_viewed';
    public const schema_primary_key = 'view_id';
    public string $indexer = 'recently_viewed_indexer';
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '浏览ID')]
    public const schema_fields_ID = 'view_id';
    #[Col(type: 'int', nullable: false, comment: '客户ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col(type: 'int', nullable: false, comment: '产品ID')]
    public const schema_fields_PRODUCT_ID = 'product_id';
    #[Col(type: 'datetime', nullable: false, comment: '浏览时间')]
    public const schema_fields_VIEWED_AT = 'viewed_at';
    public array $_unit_primary_keys = ['view_id'];
    public array $_index_sort_keys = ['customer_id', 'viewed_at'];
}
