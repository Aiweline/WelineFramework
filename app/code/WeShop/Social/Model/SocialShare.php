<?php

declare(strict_types=1);

namespace WeShop\Social\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 社交分享模型
 */
#[Table(comment: 'WeShop社交分享表')]
#[Index(name: 'idx_customer_id', columns: ['customer_id'], type: 'KEY', comment: '客户ID索引')]
#[Index(name: 'idx_product_id', columns: ['product_id'], type: 'KEY', comment: '产品ID索引')]
class SocialShare extends Model
{
    public const schema_table = 'weshop_social_share';
    public const schema_primary_key = 'share_id';
    public string $indexer = 'social_share_indexer';

    public array $_unit_primary_keys = ['share_id'];
    public array $_index_sort_keys = ['customer_id', 'product_id', 'platform'];

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '分享ID')]
    public const schema_fields_ID = 'share_id';
    #[Col(type: 'int', nullable: false, comment: '客户ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col(type: 'int', nullable: false, comment: '产品ID')]
    public const schema_fields_PRODUCT_ID = 'product_id';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: '平台')]
    public const schema_fields_PLATFORM = 'platform';
    #[Col(type: 'datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
}
