<?php
declare(strict_types=1);

namespace Weline\Server\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WLS 容量顾问时序采样')]
#[Index(name: 'idx_instance_sampled', columns: ['instance', 'sampled_at'])]
#[Index(name: 'idx_sampled_at', columns: ['sampled_at'])]
class ServerCapacitySample extends Model
{
    public const schema_table = 'weline_server_capacity_sample';
    public const schema_primary_key = 'sample_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'sample_id';
    #[Col('varchar', 64, default: 'default', comment: '实例名')]
    public const schema_fields_INSTANCE = 'instance';
    #[Col('datetime', nullable: false, comment: '采样时间')]
    public const schema_fields_SAMPLED_AT = 'sampled_at';
    #[Col('decimal', '8,4', default: '0.0000', comment: '主机压力')]
    public const schema_fields_HOST_PRESSURE = 'host_pressure';
    #[Col('decimal', '8,4', default: '0.0000', comment: 'Worker 平均压力')]
    public const schema_fields_WORKER_PRESSURE_AVG = 'worker_pressure_avg';
    #[Col('decimal', '8,4', default: '0.0000', comment: 'Worker 峰值压力')]
    public const schema_fields_WORKER_PRESSURE_MAX = 'worker_pressure_max';
    #[Col('int', 11, default: 0, comment: '连接数合计')]
    public const schema_fields_CONNECTIONS_SUM = 'connections_sum';
    #[Col('int', 11, default: 0, comment: '活跃请求合计')]
    public const schema_fields_ACTIVE_REQUESTS_SUM = 'active_requests_sum';
    #[Col('int', 11, default: 0, comment: '窗内 compact 增量')]
    public const schema_fields_COMPACT_DELTA = 'compact_delta';
    #[Col('text', comment: '建议/原因码 JSON')]
    public const schema_fields_ADVICE_CODES = 'advice_codes';
    #[Col('decimal', '8,4', default: '0.7000', comment: '配置 soft 参考线')]
    public const schema_fields_CONFIG_SOFT_PRESSURE = 'config_soft_pressure';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
}
