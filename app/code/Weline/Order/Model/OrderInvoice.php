<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Order\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/** 发票模型 */
#[Table(comment: '发票表')]
#[Index(name: 'uniq_order_invoice_order', columns: ['order_id'], type: 'UNIQUE')]
#[Index(name: 'idx_invoice_number', columns: ['invoice_number'], type: 'UNIQUE')]
#[Index(name: 'uniq_order_invoice_effect', columns: ['effect_key'], type: 'UNIQUE')]
class OrderInvoice extends Model
{
    public const schema_table = 'weline_order_invoice';
    public const schema_primary_key = 'invoice_id';

    #[Col(type: 'int', length: 11, primaryKey: true, autoIncrement: true, nullable: false, comment: '发票ID')]
    public const schema_fields_ID = 'invoice_id';
    #[Col(type: 'int', length: 11, nullable: false, comment: '订单ID')]
    public const schema_fields_ORDER_ID = 'order_id';
    #[Col(type: 'varchar', length: 100, nullable: false, unique: true, comment: '发票号')]
    public const schema_fields_INVOICE_NUMBER = 'invoice_number';
    #[Col(type: 'varchar', length: 191, nullable: true, comment: 'Deterministic payment effect key')]
    public const schema_fields_EFFECT_KEY = 'effect_key';
    #[Col(type: 'varchar', length: 96, nullable: true, comment: 'Payment attempt code')]
    public const schema_fields_ATTEMPT_CODE = 'attempt_code';
    #[Col(type: 'decimal', length: '10,2', nullable: false, comment: '发票金额')]
    public const schema_fields_AMOUNT = 'amount';
    #[Col(type: 'bigint', length: 20, nullable: true, comment: 'Frozen invoice amount in minor units')]
    public const schema_fields_AMOUNT_MINOR = 'amount_minor';
    #[Col(type: 'bigint', length: 20, nullable: false, default: 0, comment: 'Frozen invoice tax in minor units')]
    public const schema_fields_TAX_AMOUNT_MINOR = 'tax_amount_minor';
    #[Col(type: 'text', nullable: true, comment: 'Frozen order tax snapshot JSON')]
    public const schema_fields_TAX_SNAPSHOT_JSON = 'tax_snapshot_json';
    #[Col(type: 'varchar', length: 32, nullable: false, default: 'normal', comment: 'normal|historical_only')]
    public const schema_fields_RESOURCE_MODE = 'resource_mode';
    #[Col(type: 'varchar', length: 50, nullable: false, default: 'pending', comment: '发票状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'timestamp', nullable: true, comment: '开具时间')]
    public const schema_fields_ISSUED_AT = 'issued_at';
    #[Col(type: 'timestamp', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    // 发票状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_ISSUED = 'issued';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['invoice_id'];

    /**
     * 索引排序键
     */
    public array $_index_sort_keys = [
        'invoice_id',
        'order_id',
        'invoice_number',
        'effect_key',
        'attempt_code',
    ];

    /**
     * 生成发票号
     */
    public function generateInvoiceNumber(): string
    {
        return 'INV' . date('YmdHis') . str_pad((string)mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    public static function deterministicInvoiceNumber(string $effectKey): string
    {
        if (trim($effectKey) === '') {
            throw new \InvalidArgumentException('invoice_effect_key_required');
        }

        return 'INV-' . strtoupper(substr(hash('sha256', $effectKey), 0, 24));
    }
}
