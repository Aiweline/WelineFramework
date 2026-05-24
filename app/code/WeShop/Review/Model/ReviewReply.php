<?php

declare(strict_types=1);

namespace WeShop\Review\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WeShop review reply table')]
#[Index(name: 'idx_review_id', columns: ['review_id'], type: 'KEY', comment: 'Review ID index')]
#[Index(name: 'idx_parent_reply_id', columns: ['parent_reply_id'], type: 'KEY', comment: 'Parent reply ID index')]
#[Index(name: 'idx_product_id', columns: ['product_id'], type: 'KEY', comment: 'Product ID index')]
#[Index(name: 'idx_customer_id', columns: ['customer_id'], type: 'KEY', comment: 'Customer ID index')]
#[Index(name: 'idx_review_status_created', columns: ['review_id', 'status', 'created_at'], type: 'KEY', comment: 'Review reply list index')]
class ReviewReply extends Model
{
    public const schema_table = 'weshop_review_reply';
    public const schema_primary_key = 'reply_id';
    public string $indexer = 'review_reply_indexer';

    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Reply ID')]
    public const schema_fields_ID = 'reply_id';
    #[Col(type: 'int', nullable: false, comment: 'Review ID')]
    public const schema_fields_REVIEW_ID = 'review_id';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'Parent reply ID')]
    public const schema_fields_PARENT_REPLY_ID = 'parent_reply_id';
    #[Col(type: 'int', nullable: false, comment: 'Product ID')]
    public const schema_fields_PRODUCT_ID = 'product_id';
    #[Col(type: 'int', nullable: false, comment: 'Customer ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col(type: 'varchar', length: 120, nullable: true, comment: 'Display name')]
    public const schema_fields_DISPLAY_NAME = 'display_name';
    #[Col(type: 'text', nullable: false, comment: 'Reply content')]
    public const schema_fields_CONTENT = 'content';
    #[Col(type: 'text', nullable: true, comment: 'Mentioned customer ID JSON')]
    public const schema_fields_MENTIONED_CUSTOMER_IDS = 'mentioned_customer_ids';
    #[Col(type: 'varchar', length: 20, nullable: false, default: 'approved', comment: 'Status')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['reply_id'];
    public array $_index_sort_keys = ['review_id', 'parent_reply_id', 'product_id', 'customer_id', 'status', 'created_at'];
}
