<?php
declare(strict_types=1);

namespace Weline\Server\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WLS 流量聚合指标')]
#[Index(name: 'uk_bucket', columns: ['instance', 'host', 'bucket_ts', 'metric_type'], type: 'UNIQUE')]
#[Index(name: 'idx_bucket_ts', columns: ['bucket_ts'])]
#[Index(name: 'idx_instance_host', columns: ['instance', 'host'])]
class ServerTrafficMetric extends Model
{
    public const schema_table = 'weline_server_traffic_metric';
    public const schema_primary_key = 'metric_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'metric_id';
    #[Col('varchar', 64, default: 'default', comment: '实例名')]
    public const schema_fields_INSTANCE = 'instance';
    #[Col('varchar', 255, default: '*', comment: 'Host 分组')]
    public const schema_fields_HOST = 'host';
    #[Col('int', 11, nullable: false, comment: '时间桶(秒时间戳)')]
    public const schema_fields_BUCKET_TS = 'bucket_ts';
    #[Col('varchar', 32, nullable: false, comment: '指标类型')]
    public const schema_fields_METRIC_TYPE = 'metric_type';
    #[Col('bigint', 20, default: 0, comment: '请求总数')]
    public const schema_fields_REQUEST_COUNT = 'request_count';
    #[Col('bigint', 20, default: 0, comment: '状态>=500数')]
    public const schema_fields_ERROR_COUNT = 'error_count';
    #[Col('bigint', 20, default: 0, comment: '响应字节总和')]
    public const schema_fields_BYTES_OUT = 'bytes_out';
    #[Col('bigint', 20, default: 0, comment: '延迟总和(ms)')]
    public const schema_fields_LATENCY_TOTAL_MS = 'latency_total_ms';
    #[Col('bigint', 20, default: 0, comment: '峰值延迟(ms)')]
    public const schema_fields_LATENCY_MAX_MS = 'latency_max_ms';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';
}
