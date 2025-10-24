<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * AI Usage Log Entity
 * 
 * Records detailed API call logs.
 * 
 * @package Weline_Ai
 */
class AiUsageLog extends Model
{
    // 框架自动推导表名：AiUsageLog → ai_usage_log
    
    /**
     * Unit primary keys
     */
    public array $_unit_primary_keys = ['id'];
    
    /**
     * Index sort keys
     */
    public array $_index_sort_keys = ['id', 'api_key_id', 'model_code', 'created_at'];
    
    /**
     * Field name constants
     */
    public const fields_ID = 'id';
    public const fields_API_KEY_ID = 'api_key_id';
    public const fields_TENANT_ID = 'tenant_id';
    public const fields_MODEL_CODE = 'model_code';
    public const fields_REQUEST_DATA = 'request_data';
    public const fields_RESPONSE_DATA = 'response_data';
    public const fields_TOTAL_TOKENS = 'total_tokens';
    public const fields_TOTAL_COST = 'total_cost';
    public const fields_STATUS = 'status';
    public const fields_CREATED_AT = 'created_at';

    /**
     * Status constants
     */
    public const STATUS_SUCCESS = 'success';
    public const STATUS_ERROR = 'error';

    /**
     * Install database table
     *
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        $this->useMainDbMaster();
        
        if ($setup->tableExist() === false) {
            $setup->createTable('AI Usage Log')
            ->addColumn(
                self::fields_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '日志ID'
            )
            ->addColumn(
                self::fields_API_KEY_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'not null',
                'API密钥ID'
            )
            ->addColumn(
                self::fields_TENANT_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'null',
                '租户ID'
            )
            ->addColumn(
                self::fields_MODEL_CODE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                100,
                'not null',
                '使用的模型'
            )
            ->addColumn(
                self::fields_REQUEST_DATA,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT,
                0,
                'null',
                '请求数据'
            )
            ->addColumn(
                self::fields_RESPONSE_DATA,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT,
                0,
                'null',
                '响应数据'
            )
            ->addColumn(
                self::fields_TOTAL_TOKENS,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'not null default 0',
                '总令牌数'
            )
            ->addColumn(
                self::fields_TOTAL_COST,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL,
                '10,6',
                'not null default 0',
                '总成本'
            )
            ->addColumn(
                self::fields_STATUS,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                20,
                'not null',
                '状态'
            )
            ->addColumn(
                self::fields_CREATED_AT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP,
                0,
                'not null default current_timestamp',
                '创建时间'
            )
            ->addIndex('INDEX', 'idx_api_key_id', self::fields_API_KEY_ID)
            ->addIndex('INDEX', 'idx_tenant_id', self::fields_TENANT_ID)
            ->addIndex('INDEX', 'idx_model_code', self::fields_MODEL_CODE)
            ->addIndex('INDEX', 'idx_created_at', self::fields_CREATED_AT)
            ->create();
        }
    }

    /**
     * Setup database table
     *
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * Upgrade database table
     *
     * @param ModelSetup $setup
     * @param Context $context
     * @return void
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // Future upgrades will be added here
    }
}
