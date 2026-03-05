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
/** 订单历史模型 */
#[Table(comment: '订单历史表')]
#[Index(name: 'idx_order_id', columns: ['order_id'])]
#[Index(name: 'idx_created_at', columns: ['created_at'])]
class OrderHistory extends Model
{
    public const schema_table = 'weline_order_history';
    public const schema_primary_key = 'history_id';
    #[Col('int', 11, nullable: false, primaryKey: true, autoIncrement: true, comment: '历史ID')]
    public const schema_fields_ID = 'history_id';
    #[Col('int', 11, nullable: false, comment: '订单ID')]
    public const schema_fields_ORDER_ID = 'order_id';
    #[Col('varchar', 50, comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('text', comment: '备注')]
    public const schema_fields_COMMENT = 'comment';
    #[Col('smallint', 1, nullable: false, default: 0, comment: '是否通知客户')]
    public const schema_fields_IS_CUSTOMER_NOTIFIED = 'is_customer_notified';
    #[Col('int', 11, comment: '创建人ID')]
    public const schema_fields_CREATED_BY = 'created_by';
    #[Col('timestamp', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['history_id'];
    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['history_id', 'order_id', 'created_at'];
}
