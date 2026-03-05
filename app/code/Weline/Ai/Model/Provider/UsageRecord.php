<?php

declare(strict_types=1);

namespace Weline\Ai\Model\Provider;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
/**
 * AI Provider Usage Record Model
 *
 * 记录AI供应商账户的使用记录，包括Token消耗、费用等
 *
 * @package Weline_Ai
 */
#[Table(comment: 'AI供应商使用记录表')]
#[Index(name: 'idx_account_id', columns: ['account_id'])]
#[Index(name: 'idx_provider_code', columns: ['provider_code'])]
#[Index(name: 'idx_model_code', columns: ['model_code'])]
#[Index(name: 'idx_user_id', columns: ['user_id'])]
#[Index(name: 'idx_created_at', columns: ['created_at'])]
#[Index(name: 'idx_account_created', columns: ['account_id', 'created_at'])]
class UsageRecord extends Model
{
    public const schema_table = 'ai_provider_usage_record';
    public const schema_primary_key = 'id';

    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['id', 'account_id', 'created_at'];

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'int', nullable: false, comment: '账户ID')]
    public const schema_fields_ACCOUNT_ID = 'account_id';
    #[Col(type: 'varchar', length: 50, nullable: false, comment: '供应商代码')]
    public const schema_fields_PROVIDER_CODE = 'provider_code';
    #[Col(type: 'varchar', length: 100, nullable: false, comment: '模型代码')]
    public const schema_fields_MODEL_CODE = 'model_code';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '模型名称')]
    public const schema_fields_MODEL_NAME = 'model_name';
    #[Col(type: 'varchar', length: 100, nullable: true, comment: '请求ID')]
    public const schema_fields_REQUEST_ID = 'request_id';
    #[Col(type: 'int', nullable: true, comment: '用户ID')]
    public const schema_fields_USER_ID = 'user_id';
    #[Col(type: 'varchar', length: 100, nullable: true, comment: '用户名')]
    public const schema_fields_USER_NAME = 'user_name';
    #[Col(type: 'varchar', length: 50, nullable: true, comment: '请求类型')]
    public const schema_fields_REQUEST_TYPE = 'request_type';
    #[Col(type: 'int', nullable: true, default: 0, comment: '输入令牌数')]
    public const schema_fields_PROMPT_TOKENS = 'prompt_tokens';
    #[Col(type: 'int', nullable: true, default: 0, comment: '输出令牌数')]
    public const schema_fields_COMPLETION_TOKENS = 'completion_tokens';
    #[Col(type: 'int', nullable: true, default: 0, comment: '总令牌数')]
    public const schema_fields_TOTAL_TOKENS = 'total_tokens';
    #[Col(type: 'decimal', length: '10,6', default: 0, comment: '输入成本')]
    public const schema_fields_INPUT_COST = 'input_cost';
    #[Col(type: 'decimal', length: '10,6', default: 0, comment: '输出成本')]
    public const schema_fields_OUTPUT_COST = 'output_cost';
    #[Col(type: 'decimal', length: '10,6', default: 0, comment: '总成本')]
    public const schema_fields_TOTAL_COST = 'total_cost';
    #[Col(type: 'varchar', length: 10, default: 'USD', comment: '货币单位')]
    public const schema_fields_CURRENCY = 'currency';
    #[Col(type: 'int', nullable: true, comment: '请求耗时（毫秒）')]
    public const schema_fields_REQUEST_TIME = 'request_time';
    #[Col(type: 'text', nullable: true, comment: '请求数据摘要')]
    public const schema_fields_REQUEST_DATA = 'request_data';
    #[Col(type: 'text', nullable: true, comment: '响应数据摘要')]
    public const schema_fields_RESPONSE_DATA = 'response_data';
    #[Col(type: 'varchar', length: 20, default: 'success', comment: '状态')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'text', nullable: true, comment: '错误信息')]
    public const schema_fields_ERROR_MESSAGE = 'error_message';
    #[Col(type: 'int', nullable: true, default: 0, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }

    /** Calculate cost from token counts and per-thousand prices */
    public function calculateCost(float $inputPricePerThousand, float $outputPricePerThousand): self
    {
        $promptTokens = (int)$this->getData(self::schema_fields_PROMPT_TOKENS);
        $completionTokens = (int)$this->getData(self::schema_fields_COMPLETION_TOKENS);

        $inputCost = ($promptTokens / 1000) * $inputPricePerThousand;
        $outputCost = ($completionTokens / 1000) * $outputPricePerThousand;
        $totalCost = $inputCost + $outputCost;

        $this->setData(self::schema_fields_INPUT_COST, $inputCost);
        $this->setData(self::schema_fields_OUTPUT_COST, $outputCost);
        $this->setData(self::schema_fields_TOTAL_COST, $totalCost);

        return $this;
    }
}
