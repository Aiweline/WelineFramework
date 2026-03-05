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
/** 支付记录模型 */
#[Table(comment: '支付记录表')]
#[Index(name: 'idx_order_id', columns: ['order_id'])]
#[Index(name: 'idx_transaction_id', columns: ['transaction_id'])]
class OrderPayment extends Model
{
    public const schema_table = 'weline_order_payment';
    public const schema_primary_key = 'payment_id';
    #[Col('int', 11, nullable: false, primaryKey: true, autoIncrement: true, comment: '支付ID')]
    public const schema_fields_ID = 'payment_id';
    #[Col('int', 11, nullable: false, comment: '订单ID')]
    public const schema_fields_ORDER_ID = 'order_id';
    #[Col('varchar', 100, nullable: false, comment: '支付方式')]
    public const schema_fields_PAYMENT_METHOD = 'payment_method';
    #[Col('decimal', '10,2', nullable: false, comment: '支付金额')]
    public const schema_fields_AMOUNT = 'amount';
    #[Col('varchar', 10, nullable: false, default: 'CNY', comment: '货币代码')]
    public const schema_fields_CURRENCY = 'currency';
    #[Col('varchar', 255, comment: '交易ID')]
    public const schema_fields_TRANSACTION_ID = 'transaction_id';
    #[Col('varchar', 50, nullable: false, default: 'pending', comment: '支付状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('timestamp', comment: '支付时间')]
    public const schema_fields_PAID_AT = 'paid_at';
    #[Col('timestamp', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    // 支付状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';
    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['payment_id'];
    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['payment_id', 'order_id', 'transaction_id'];
}
