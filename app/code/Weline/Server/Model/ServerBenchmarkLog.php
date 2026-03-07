<?php
declare(strict_types=1);

namespace Weline\Server\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'WLS 压测结果日志')]
#[Index(name: 'idx_instance', columns: ['instance'])]
#[Index(name: 'idx_created_at', columns: ['created_at'])]
class ServerBenchmarkLog extends Model
{
    public const schema_table = 'weline_server_benchmark_log';
    public const schema_primary_key = 'benchmark_id';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'benchmark_id';
    #[Col('varchar', 64, default: 'default', comment: '实例名')]
    public const schema_fields_INSTANCE = 'instance';
    #[Col('varchar', 255, nullable: false, comment: '目标URL')]
    public const schema_fields_TARGET_URL = 'target_url';
    #[Col('int', 11, default: 100, comment: '并发')]
    public const schema_fields_CONCURRENCY = 'concurrency';
    #[Col('int', 11, default: 10000, comment: '请求数')]
    public const schema_fields_REQUESTS = 'requests';
    #[Col('decimal', '10,2', default: '0.00', comment: 'QPS')]
    public const schema_fields_QPS = 'qps';
    #[Col('decimal', '8,2', default: '0.00', comment: '错误率')]
    public const schema_fields_ERROR_RATE = 'error_rate';
    #[Col('decimal', '10,3', default: '0.000', comment: '平均延迟ms')]
    public const schema_fields_LATENCY_AVG = 'latency_avg';
    #[Col('decimal', '10,3', default: '0.000', comment: 'P95延迟ms')]
    public const schema_fields_LATENCY_P95 = 'latency_p95';
    #[Col('decimal', '10,3', default: '0.000', comment: 'P99延迟ms')]
    public const schema_fields_LATENCY_P99 = 'latency_p99';
    #[Col('text', comment: '详情JSON')]
    public const schema_fields_RESULT_JSON = 'result_json';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
}
