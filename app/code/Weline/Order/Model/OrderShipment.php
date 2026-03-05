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
/** 发货记录模型 */
#[Table(comment: '发货记录表')]
#[Index(name: 'idx_order_id', columns: ['order_id'])]
#[Index(name: 'idx_tracking_number', columns: ['tracking_number'])]
class OrderShipment extends Model
{

    public const schema_table = 'weline_order_shipment';
    public const schema_primary_key = 'shipment_id';
    #[Col('int', 11, nullable: false, primaryKey: true, autoIncrement: true, comment: '发货ID')]
    public const schema_fields_ID = 'shipment_id';
    #[Col('int', 11, nullable: false, comment: '订单ID')]
    public const schema_fields_ORDER_ID = 'order_id';
    #[Col('varchar', 100, comment: '物流单号')]
    public const schema_fields_TRACKING_NUMBER = 'tracking_number';
    #[Col('varchar', 100, comment: '承运商')]
    public const schema_fields_CARRIER = 'carrier';
    #[Col('varchar', 50, nullable: false, default: 'pending', comment: '发货状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('timestamp', comment: '发货时间')]
    public const schema_fields_SHIPPED_AT = 'shipped_at';
    #[Col('timestamp', comment: '送达时间')]
    public const schema_fields_DELIVERED_AT = 'delivered_at';
    #[Col('timestamp', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    // 发货状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';

    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['shipment_id'];

    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['shipment_id', 'order_id', 'tracking_number'];
}


