<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * API调用日志模型
 */
class AiApiCallLog extends \Weline\Framework\Database\Model
{
    // 字段常量
    public const fields_ID = 'id';
    public const fields_API_KEY_ID = 'api_key_id';
    public const fields_USER_ID = 'user_id';
    public const fields_REQUEST_ID = 'request_id';
    public const fields_MODEL_ID = 'model_id';
    public const fields_MODEL_CODE = 'model_code';
    public const fields_ENDPOINT = 'endpoint';
    public const fields_REQUEST_METHOD = 'request_method';
    public const fields_REQUEST_IP = 'request_ip';
    public const fields_PROMPT_TOKENS = 'prompt_tokens';
    public const fields_COMPLETION_TOKENS = 'completion_tokens';
    public const fields_TOTAL_TOKENS = 'total_tokens';
    public const fields_PROMPT_COST = 'prompt_cost';
    public const fields_COMPLETION_COST = 'completion_cost';
    public const fields_TOTAL_COST = 'total_cost';
    public const fields_BALANCE_BEFORE = 'balance_before';
    public const fields_BALANCE_AFTER = 'balance_after';
    public const fields_RESPONSE_STATUS = 'response_status';
    public const fields_RESPONSE_TIME = 'response_time';
    public const fields_STATUS = 'status';
    public const fields_ERROR_MESSAGE = 'error_message';
    public const fields_CREATED_TIME = 'created_time';
    
    // 状态常量
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_TIMEOUT = 'timeout';
    public const STATUS_INSUFFICIENT_BALANCE = 'insufficient_balance';
    
    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        // 主表安装在install方法中
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
        $this->_unit_primary_keys = [self::fields_ID];
        $this->_index_sort_keys = [
            [self::fields_API_KEY_ID],
            [self::fields_USER_ID],
            [self::fields_MODEL_ID],
            [self::fields_CREATED_TIME],
        ];
        
        if (!$setup->tableExist($setup->getTableName())) {
            $setup->createTable('API调用日志表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_BIGINT,
                    20,
                    'primary key auto_increment',
                    '主键'
                )
                ->addColumn(
                    self::fields_API_KEY_ID,
                    TableInterface::column_type_INT,
                    11,
                    'not null',
                    'API密钥ID'
                )
                ->addColumn(
                    self::fields_USER_ID,
                    TableInterface::column_type_INT,
                    11,
                    'not null',
                    '用户ID'
                )
                ->addColumn(
                    self::fields_REQUEST_ID,
                    TableInterface::column_type_VARCHAR,
                    64,
                    'not null',
                    '请求ID'
                )
                ->addColumn(
                    self::fields_MODEL_ID,
                    TableInterface::column_type_INT,
                    11,
                    'not null',
                    '模型ID'
                )
                ->addColumn(
                    self::fields_MODEL_CODE,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'not null',
                    '模型代码'
                )
                ->addColumn(
                    self::fields_ENDPOINT,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'null',
                    '接口端点'
                )
                ->addColumn(
                    self::fields_REQUEST_METHOD,
                    TableInterface::column_type_VARCHAR,
                    10,
                    'null',
                    '请求方法'
                )
                ->addColumn(
                    self::fields_REQUEST_IP,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'null',
                    '请求IP'
                )
                ->addColumn(
                    self::fields_PROMPT_TOKENS,
                    TableInterface::column_type_INT,
                    11,
                    'default 0',
                    '输入Token数'
                )
                ->addColumn(
                    self::fields_COMPLETION_TOKENS,
                    TableInterface::column_type_INT,
                    11,
                    'default 0',
                    '输出Token数'
                )
                ->addColumn(
                    self::fields_TOTAL_TOKENS,
                    TableInterface::column_type_INT,
                    11,
                    'default 0',
                    '总Token数'
                )
                ->addColumn(
                    self::fields_PROMPT_COST,
                    TableInterface::column_type_DECIMAL . '(10,6)',
                    0,
                    'default 0.000000',
                    '输入成本'
                )
                ->addColumn(
                    self::fields_COMPLETION_COST,
                    TableInterface::column_type_DECIMAL . '(10,6)',
                    0,
                    'default 0.000000',
                    '输出成本'
                )
                ->addColumn(
                    self::fields_TOTAL_COST,
                    TableInterface::column_type_DECIMAL . '(10,6)',
                    0,
                    'default 0.000000',
                    '总成本'
                )
                ->addColumn(
                    self::fields_BALANCE_BEFORE,
                    TableInterface::column_type_DECIMAL . '(12,4)',
                    0,
                    'null',
                    '调用前余额'
                )
                ->addColumn(
                    self::fields_BALANCE_AFTER,
                    TableInterface::column_type_DECIMAL . '(12,4)',
                    0,
                    'null',
                    '调用后余额'
                )
                ->addColumn(
                    self::fields_RESPONSE_STATUS,
                    TableInterface::column_type_INT,
                    11,
                    'null',
                    '响应状态码'
                )
                ->addColumn(
                    self::fields_RESPONSE_TIME,
                    TableInterface::column_type_INT,
                    11,
                    'null',
                    '响应时间（毫秒）'
                )
                ->addColumn(
                    self::fields_STATUS,
                    TableInterface::column_type_VARCHAR,
                    30,
                    'default "success"',
                    '调用状态'
                )
                ->addColumn(
                    self::fields_ERROR_MESSAGE,
                    TableInterface::column_type_TEXT,
                    0,
                    'null',
                    '错误信息'
                )
                ->addColumn(
                    self::fields_CREATED_TIME,
                    TableInterface::column_type_DATETIME,
                    0,
                    'default current_timestamp',
                    '创建时间'
                )
                ->addIndex(
                    TableInterface::index_type_NORMAL,
                    'idx_api_key',
                    self::fields_API_KEY_ID
                )
                ->addIndex(
                    TableInterface::index_type_NORMAL,
                    'idx_user',
                    self::fields_USER_ID
                )
                ->addIndex(
                    TableInterface::index_type_UNIQUE,
                    'idx_request',
                    self::fields_REQUEST_ID
                )
                ->addIndex(
                    TableInterface::index_type_NORMAL,
                    'idx_model',
                    self::fields_MODEL_ID
                )
                ->addIndex(
                    TableInterface::index_type_NORMAL,
                    'idx_time',
                    self::fields_CREATED_TIME
                )
                ->addIndex(
                    TableInterface::index_type_NORMAL,
                    'idx_status',
                    self::fields_STATUS
                )
                ->create();
        }
    }
    
    /**
     * 初始化方法
     */
    public function _init(): void
    {
        $this->useMainDbMaster();
    }
}

