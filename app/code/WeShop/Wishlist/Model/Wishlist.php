<?php

declare(strict_types=1);

namespace WeShop\Wishlist\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 愿望清单模型
 */
#[Table(comment: 'WeShop愿望清单表')]
#[Index(name: 'idx_customer_id', columns: ['customer_id'], type: 'KEY', comment: '客户ID索引')]
#[Index(name: 'idx_product_id', columns: ['product_id'], type: 'KEY', comment: '产品ID索引')]
#[Index(name: 'idx_customer_product', columns: ['customer_id', 'product_id'], type: 'UNIQUE', comment: '客户产品唯一索引')]
class Wishlist extends Model
{
    public const schema_table = 'weshop_wishlist';
    public const schema_primary_key = 'wishlist_id';
    public string $indexer = 'wishlist_indexer';

    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '愿望清单ID')]
    public const schema_fields_ID = 'wishlist_id';
    #[Col('int', 0, nullable: false, comment: '客户ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col('int', 0, nullable: false, comment: '产品ID')]
    public const schema_fields_PRODUCT_ID = 'product_id';
    #[Col('datetime', 0, nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', 0, nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['wishlist_id'];
    public array $_index_sort_keys = ['customer_id', 'product_id'];
}

