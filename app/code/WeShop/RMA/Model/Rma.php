<?php

declare(strict_types=1);

namespace WeShop\RMA\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 退货模型
 */
#[Table(comment: 'WeShop退货表')]
#[Index(name: 'idx_order_id', columns: ['order_id'], type: 'KEY', comment: '订单ID索引')]
#[Index(name: 'idx_customer_id', columns: ['customer_id'], type: 'KEY', comment: '客户ID索引')]
class Rma extends Model
{
    public const schema_table = 'weshop_rma';
    public const schema_primary_key = 'rma_id';
    public string $indexer = 'rma_indexer';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '退货ID')]
    public const schema_fields_ID = 'rma_id';
    #[Col(type: 'int', nullable: false, comment: '订单ID')]
    public const schema_fields_ORDER_ID = 'order_id';
    #[Col(type: 'int', nullable: false, comment: '客户ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '退货原因')]
    public const schema_fields_REASON = 'reason';
    #[Col(type: 'text', nullable: true, comment: '描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col(type: 'varchar', length: 20, nullable: true, default: 'pending', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: false, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['rma_id'];
    public array $_index_sort_keys = ['order_id', 'customer_id', 'status'];
}
