<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * SSE 桥事件队列：浏览器与后端之间的可重放事件。
 */
#[Table(comment: 'AI获客浏览器桥事件')]
#[Index(name: 'idx_ai_acquisition_bridge_run_status', columns: ['run_id', 'status', 'id'])]
#[Index(name: 'idx_ai_acquisition_bridge_expires', columns: ['expires_at'])]
class BridgeEvent extends Model
{
    public const schema_table = 'ai_acquisition_bridge_event';
    public const schema_primary_key = 'id';

    public const DIRECTION_SERVER_TO_BROWSER = 'server_to_browser';
    public const DIRECTION_BROWSER_TO_SERVER = 'browser_to_server';

    public const STATUS_PENDING = 'pending';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CONSUMED = 'consumed';

    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col('int', nullable: false, comment: '运行ID')]
    public const schema_fields_RUN_ID = 'run_id';
    #[Col('varchar', 20, default: 'server_to_browser', comment: '方向')]
    public const schema_fields_DIRECTION = 'direction';
    #[Col('varchar', 64, nullable: false, comment: '事件类型')]
    public const schema_fields_TYPE = 'type';
    #[Col('text', nullable: true, comment: '事件负载JSON')]
    public const schema_fields_PAYLOAD_JSON = 'payload_json';
    #[Col('varchar', 16, default: 'pending', comment: '投递状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('int', default: 0, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('int', default: 0, comment: '投递时间')]
    public const schema_fields_DELIVERED_AT = 'delivered_at';
    #[Col('int', default: 0, comment: '消费时间')]
    public const schema_fields_CONSUMED_AT = 'consumed_at';
    #[Col('int', default: 0, comment: '过期时间')]
    public const schema_fields_EXPIRES_AT = 'expires_at';

    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['id', 'run_id', 'created_at'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }
}
