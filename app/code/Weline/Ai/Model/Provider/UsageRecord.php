<?php

declare(strict_types=1);

namespace Weline\Ai\Model\Provider;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * AI Provider Usage Record Model
 * 
 * 记录AI供应商账户的使用记录，包括Token消耗、费用等
 * 
 * @package Weline_Ai
 */
class UsageRecord extends Model
{
    public const table = 'ai_provider_usage_record';
    
    /**
     * Primary keys
     */
    public array $_unit_primary_keys = ['id'];
    
    /**
     * Index sort keys
     */
    public array $_index_sort_keys = ['id', 'account_id', 'created_at'];
    
    /**
     * Field name constants
     */
    public const fields_ID = 'id';
    public const fields_ACCOUNT_ID = 'account_id';          // 关联的账户ID
    public const fields_PROVIDER_CODE = 'provider_code';    // 供应商代码
    public const fields_MODEL_CODE = 'model_code';          // 使用的模型代码
    public const fields_MODEL_NAME = 'model_name';          // 模型名称
    public const fields_REQUEST_ID = 'request_id';          // 请求ID
    public const fields_USER_ID = 'user_id';                // 用户ID
    public const fields_USER_NAME = 'user_name';            // 用户名
    public const fields_REQUEST_TYPE = 'request_type';      // 请求类型：chat, completion, embedding等
    public const fields_PROMPT_TOKENS = 'prompt_tokens';    // 输入令牌数
    public const fields_COMPLETION_TOKENS = 'completion_tokens';  // 输出令牌数
    public const fields_TOTAL_TOKENS = 'total_tokens';      // 总令牌数
    public const fields_INPUT_COST = 'input_cost';          // 输入成本
    public const fields_OUTPUT_COST = 'output_cost';        // 输出成本
    public const fields_TOTAL_COST = 'total_cost';          // 总成本
    public const fields_CURRENCY = 'currency';              // 货币单位
    public const fields_REQUEST_TIME = 'request_time';      // 请求耗时（毫秒）
    public const fields_REQUEST_DATA = 'request_data';      // 请求数据摘要
    public const fields_RESPONSE_DATA = 'response_data';    // 响应数据摘要
    public const fields_STATUS = 'status';                  // 状态：success, failed
    public const fields_ERROR_MESSAGE = 'error_message';    // 错误信息
    public const fields_CREATED_AT = 'created_at';

    /**
     * Initialize model
     */
    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    /**
     * 获取主键字段名
     * 
     * @return string
     */
    public function getIdFieldName(): string
    {
        return self::fields_ID;
    }

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级逻辑
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist() === false) {
            $setup->createTable('AI供应商使用记录表')
                ->addColumn(self::fields_ID, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'ID')
                ->addColumn(self::fields_ACCOUNT_ID, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'not null', '账户ID')
                ->addColumn(self::fields_PROVIDER_CODE, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 50, 'not null', '供应商代码')
                ->addColumn(self::fields_MODEL_CODE, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 100, 'not null', '模型代码')
                ->addColumn(self::fields_MODEL_NAME, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 255, 'null', '模型名称')
                ->addColumn(self::fields_REQUEST_ID, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 100, 'null', '请求ID')
                ->addColumn(self::fields_USER_ID, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'null', '用户ID')
                ->addColumn(self::fields_USER_NAME, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 100, 'null', '用户名')
                ->addColumn(self::fields_REQUEST_TYPE, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 50, 'null', '请求类型')
                ->addColumn(self::fields_PROMPT_TOKENS, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '输入令牌数')
                ->addColumn(self::fields_COMPLETION_TOKENS, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '输出令牌数')
                ->addColumn(self::fields_TOTAL_TOKENS, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '总令牌数')
                ->addColumn(self::fields_INPUT_COST, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL, '10,6', 'default 0', '输入成本')
                ->addColumn(self::fields_OUTPUT_COST, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL, '10,6', 'default 0', '输出成本')
                ->addColumn(self::fields_TOTAL_COST, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL, '10,6', 'default 0', '总成本')
                ->addColumn(self::fields_CURRENCY, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 10, 'default \'USD\'', '货币单位')
                ->addColumn(self::fields_REQUEST_TIME, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'null', '请求耗时（毫秒）')
                ->addColumn(self::fields_REQUEST_DATA, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'null', '请求数据摘要')
                ->addColumn(self::fields_RESPONSE_DATA, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'null', '响应数据摘要')
                ->addColumn(self::fields_STATUS, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 20, 'default \'success\'', '状态')
                ->addColumn(self::fields_ERROR_MESSAGE, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'null', '错误信息')
                ->addColumn(self::fields_CREATED_AT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'default 0', '创建时间')
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY, 'idx_account_id', self::fields_ACCOUNT_ID)
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY, 'idx_provider_code', self::fields_PROVIDER_CODE)
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY, 'idx_model_code', self::fields_MODEL_CODE)
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY, 'idx_user_id', self::fields_USER_ID)
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY, 'idx_created_at', self::fields_CREATED_AT)
                ->addIndex(\Weline\Framework\Database\Api\Db\Ddl\TableInterface::index_type_KEY, 'idx_account_created', self::fields_ACCOUNT_ID . ',' . self::fields_CREATED_AT)
                ->create();
        }
    }

    /**
     * 计算费用
     * 
     * @param float $inputPricePerThousand 每千个输入令牌的价格
     * @param float $outputPricePerThousand 每千个输出令牌的价格
     * @return self
     */
    public function calculateCost(float $inputPricePerThousand, float $outputPricePerThousand): self
    {
        $promptTokens = (int)$this->getData(self::fields_PROMPT_TOKENS);
        $completionTokens = (int)$this->getData(self::fields_COMPLETION_TOKENS);
        
        $inputCost = ($promptTokens / 1000) * $inputPricePerThousand;
        $outputCost = ($completionTokens / 1000) * $outputPricePerThousand;
        $totalCost = $inputCost + $outputCost;
        
        $this->setData(self::fields_INPUT_COST, $inputCost);
        $this->setData(self::fields_OUTPUT_COST, $outputCost);
        $this->setData(self::fields_TOTAL_COST, $totalCost);
        
        return $this;
    }
}
