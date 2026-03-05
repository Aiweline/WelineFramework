<?php
declare(strict_types=1);
namespace WeShop\Logistics\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * 物流追踪模型
 */
#[Table(comment: 'WeShop物流追踪表')]
#[Index(name: 'idx_order_id', columns: ['order_id'], comment: '订单ID索引')]
#[Index(name: 'idx_tracking_number', columns: ['tracking_number'], comment: '物流单号索引')]
class Tracking extends Model
{
    public const schema_table = 'weshop_tracking';
    public const schema_primary_key = 'tracking_id';
    public string $indexer = 'tracking_indexer';
    public array $_unit_primary_keys = ['tracking_id'];
    public array $_index_sort_keys = ['order_id', 'tracking_number', 'carrier', 'status', 'tracked_at'];
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '追踪ID')]
    public const schema_fields_ID = 'tracking_id';
    #[Col(type: 'int', nullable: false, comment: '订单ID')]
    public const schema_fields_order_id = 'order_id';
    #[Col(type: 'varchar', length: 100, nullable: false, comment: '物流单号')]
    public const schema_fields_tracking_number = 'tracking_number';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: '承运商')]
    public const schema_fields_carrier = 'carrier';
    #[Col(type: 'varchar', length: 50, nullable: true, comment: '状态')]
    public const schema_fields_status = 'status';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '位置')]
    public const schema_fields_location = 'location';
    #[Col(type: 'text', nullable: true, comment: '描述')]
    public const schema_fields_description = 'description';
    #[Col(type: 'datetime', nullable: true, comment: '追踪时间')]
    public const schema_fields_tracked_at = 'tracked_at';
    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_created_at = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_updated_at = 'updated_at';
}
