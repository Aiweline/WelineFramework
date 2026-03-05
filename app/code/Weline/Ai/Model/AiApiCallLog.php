<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * API调用日志模型
 */
#[Table(comment: 'API调用日志表')]
#[Index(name: 'idx_api_key', columns: ['api_key_id'])]
#[Index(name: 'idx_user', columns: ['user_id'])]
#[Index(name: 'idx_request', columns: ['request_id'], type: 'UNIQUE')]
#[Index(name: 'idx_model', columns: ['model_id'])]
#[Index(name: 'idx_time', columns: ['created_time'])]
#[Index(name: 'idx_status', columns: ['status'])]
class AiApiCallLog extends Model
{
    public const schema_table = 'weline_ai_ai_api_call_log';
    public const schema_primary_key = 'id';


    #[Col('bigint', 20, nullable: false, primaryKey: true, autoIncrement: true, comment: '主键')]
    public const schema_fields_ID = 'id';
    #[Col('int', 11, nullable: false, comment: 'API密钥ID')]
    public const schema_fields_API_KEY_ID = 'api_key_id';
    #[Col('int', 11, nullable: false, comment: '用户ID')]
    public const schema_fields_USER_ID = 'user_id';
    #[Col('varchar', 64, nullable: false, comment: '请求ID')]
    public const schema_fields_REQUEST_ID = 'request_id';
    #[Col('int', 11, nullable: false, comment: '模型ID')]
    public const schema_fields_MODEL_ID = 'model_id';
    #[Col('varchar', 100, nullable: false, comment: '模型代号')]
    public const schema_fields_MODEL_CODE = 'model_code';
    #[Col('varchar', 100, comment: '接口端点')]
    public const schema_fields_ENDPOINT = 'endpoint';
    #[Col('varchar', 10, comment: '请求方法')]
    public const schema_fields_REQUEST_METHOD = 'request_method';
    #[Col('varchar', 50, comment: '请求IP')]
    public const schema_fields_REQUEST_IP = 'request_ip';
    #[Col('int', 11, default: 0, comment: '输入Token数')]
    public const schema_fields_PROMPT_TOKENS = 'prompt_tokens';
    #[Col('int', 11, default: 0, comment: '输出Token数')]
    public const schema_fields_COMPLETION_TOKENS = 'completion_tokens';
    #[Col('int', 11, default: 0, comment: '总Token数')]
    public const schema_fields_TOTAL_TOKENS = 'total_tokens';
    #[Col('decimal', '10,6', default: '0.000000', comment: '输入成本')]
    public const schema_fields_PROMPT_COST = 'prompt_cost';
    #[Col('decimal', '10,6', default: '0.000000', comment: '输出成本')]
    public const schema_fields_COMPLETION_COST = 'completion_cost';
    #[Col('decimal', '10,6', default: '0.000000', comment: '总成本')]
    public const schema_fields_TOTAL_COST = 'total_cost';
    #[Col('decimal', '12,4', comment: '调用前余额')]
    public const schema_fields_BALANCE_BEFORE = 'balance_before';
    #[Col('decimal', '12,4', comment: '调用后余额')]
    public const schema_fields_BALANCE_AFTER = 'balance_after';
    #[Col('int', 11, comment: '响应状态码')]
    public const schema_fields_RESPONSE_STATUS = 'response_status';
    #[Col('int', 11, comment: '响应时间（毫秒）')]
    public const schema_fields_RESPONSE_TIME = 'response_time';
    #[Col('varchar', 30, default: 'success', comment: '调用状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('text', comment: '错误信息')]
    public const schema_fields_ERROR_MESSAGE = 'error_message';
    #[Col('datetime', nullable: false, comment: '创建时间')]
    public const schema_fields_CREATED_TIME = 'created_time';

    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_TIMEOUT = 'timeout';
    public const STATUS_INSUFFICIENT_BALANCE = 'insufficient_balance';

    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = [['api_key_id'], ['user_id'], ['model_id'], ['created_time']];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }
}
