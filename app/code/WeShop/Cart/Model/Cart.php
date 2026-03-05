<?php

declare(strict_types=1);

namespace WeShop\Cart\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 购物车模型
 */
#[Table(comment: 'WeShop购物车表')]
#[Index(name: 'idx_customer_id', columns: ['customer_id'], comment: '客户ID索引')]
#[Index(name: 'idx_product_id', columns: ['product_id'], comment: '产品ID索引')]
#[Index(name: 'idx_customer_product', columns: ['customer_id', 'product_id'], type: 'UNIQUE', comment: '客户产品唯一索引')]
class Cart extends Model
{
    public const schema_table = 'weshop_cart';
    public const schema_primary_key = 'cart_id';
    public string $indexer = 'cart_indexer';

    #[Col('integer', 0, primaryKey: true, autoIncrement: true, nullable: false, comment: '购物车ID')]
    public const schema_fields_ID = 'cart_id';
    #[Col('integer', 0, nullable: false, comment: '客户ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col('integer', 0, nullable: false, comment: '产品ID')]
    public const schema_fields_PRODUCT_ID = 'product_id';
    #[Col('integer', 0, nullable: false, default: 1, comment: '数量')]
    public const schema_fields_QUANTITY = 'quantity';
    #[Col('decimal', '10,2', nullable: false, default: 0.00, comment: '单价')]
    public const schema_fields_PRICE = 'price';
    #[Col('datetime', 0, nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', 0, nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';


    public array $_unit_primary_keys = ['cart_id'];
    public array $_index_sort_keys = ['customer_id', 'product_id'];
}

