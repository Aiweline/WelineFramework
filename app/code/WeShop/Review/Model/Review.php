<?php

declare(strict_types=1);

namespace WeShop\Review\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 商品评价模型
 */
#[Table(comment: 'WeShop商品评价表')]
#[Index(name: 'idx_product_id', columns: ['product_id'], type: 'KEY', comment: '产品ID索引')]
#[Index(name: 'idx_customer_id', columns: ['customer_id'], type: 'KEY', comment: '客户ID索引')]
#[Index(name: 'idx_status', columns: ['status'], type: 'KEY', comment: '状态索引')]
class Review extends Model
{
    public const schema_table = 'weshop_review';
    public const schema_primary_key = 'review_id';
    public string $indexer = 'review_indexer';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '评价ID')]
    public const schema_fields_ID = 'review_id';
    #[Col(type: 'int', nullable: false, comment: '产品ID')]
    public const schema_fields_PRODUCT_ID = 'product_id';
    #[Col(type: 'int', nullable: false, comment: '客户ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 5, comment: '评分（1-5）')]
    public const schema_fields_RATING = 'rating';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '评价标题')]
    public const schema_fields_TITLE = 'title';
    #[Col(type: 'text', nullable: true, comment: '评价内容')]
    public const schema_fields_CONTENT = 'content';
    #[Col(type: 'varchar', length: 20, nullable: true, default: 'pending', comment: '状态（pending/approved/rejected）')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['review_id'];
    public array $_index_sort_keys = ['product_id', 'customer_id', 'status'];
}
