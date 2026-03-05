<?php

declare(strict_types=1);

namespace WeShop\Invoice\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 发票模型
 */
#[Table(comment: 'WeShop发票表')]
#[Index(name: 'idx_order_id', columns: ['order_id'], comment: '订单ID索引')]
#[Index(name: 'idx_invoice_number', columns: ['invoice_number'], type: 'UNIQUE', comment: '发票号唯一索引')]
class Invoice extends Model
{
    public const schema_table = 'weshop_invoice';
    public const schema_primary_key = 'invoice_id';
    public string $indexer = 'invoice_indexer';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '发票ID')]
    public const schema_fields_ID = 'invoice_id';
    #[Col(type: 'int', nullable: false, comment: '订单ID')]
    public const schema_fields_ORDER_ID = 'order_id';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: '发票号')]
    public const schema_fields_INVOICE_NUMBER = 'invoice_number';
    #[Col(type: 'decimal', length: '10,2', nullable: false, default: '0.00', comment: '金额')]
    public const schema_fields_AMOUNT = 'amount';
    #[Col(type: 'varchar', length: 20, nullable: true, default: 'pending', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    public array $_unit_primary_keys = ['invoice_id'];
    public array $_index_sort_keys = ['order_id', 'invoice_number'];
}
