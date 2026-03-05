<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * AI Usage Log Entity
 *
 * Records detailed API call logs.
 *
 * @package Weline_Ai
 */
#[Table(comment: 'AI Usage Log')]
#[Index(name: 'idx_api_key_id', columns: ['api_key_id'])]
#[Index(name: 'idx_tenant_id', columns: ['tenant_id'])]
#[Index(name: 'idx_model_code', columns: ['model_code'])]
#[Index(name: 'idx_created_at', columns: ['created_at'])]
class AiUsageLog extends Model
{

    public const schema_table = 'ai_usage_log';
    public const schema_primary_key = 'id';

    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['id', 'api_key_id', 'model_code', 'created_at'];

    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: '日志ID')]
    public const schema_fields_ID = 'id';
    #[Col('int', 0, nullable: false, comment: 'API密钥ID')]
    public const schema_fields_API_KEY_ID = 'api_key_id';
    #[Col('int', 0, comment: '租户ID')]
    public const schema_fields_TENANT_ID = 'tenant_id';
    #[Col('varchar', 100, nullable: false, comment: '使用的模型')]
    public const schema_fields_MODEL_CODE = 'model_code';
    #[Col('text', comment: '请求数据')]
    public const schema_fields_REQUEST_DATA = 'request_data';
    #[Col('text', comment: '响应数据')]
    public const schema_fields_RESPONSE_DATA = 'response_data';
    #[Col('int', 0, nullable: false, default: 0, comment: '总令牌数')]
    public const schema_fields_TOTAL_TOKENS = 'total_tokens';
    #[Col('decimal', '10,6', nullable: false, default: 0, comment: '总成本')]
    public const schema_fields_TOTAL_COST = 'total_cost';
    #[Col('varchar', 20, nullable: false, comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('timestamp', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    public const STATUS_SUCCESS = 'success';
    public const STATUS_ERROR = 'error';
}

