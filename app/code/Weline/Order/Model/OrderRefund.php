<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫科技 编写，所有解释权归 weline 所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Order\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/** 退款记录模型 */
#[Table(comment: '退款记录表')]
#[Index(name: 'idx_order_id', columns: ['order_id'])]
class OrderRefund extends Model
{

    public const schema_table = 'weline_order_refund';
    public const schema_primary_key = 'refund_id';
    #[Col('int', 11, nullable: false, primaryKey: true, autoIncrement: true, comment: '退款ID')]
    public const schema_fields_ID = 'refund_id';
    #[Col('int', 11, nullable: false, comment: '订单ID')]
    public const schema_fields_ORDER_ID = 'order_id';
    #[Col('decimal', '10,2', nullable: false, comment: '退款金额')]
    public const schema_fields_AMOUNT = 'amount';
    #[Col('text', comment: '退款原因')]
    public const schema_fields_REASON = 'reason';
    #[Col('varchar', 50, nullable: false, default: 'pending', comment: '退款状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('timestamp', comment: '退款时间')]
    public const schema_fields_REFUNDED_AT = 'refunded_at';
    #[Col('timestamp', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    // 退款状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['refund_id'];

    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['refund_id', 'order_id'];
}
