<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * AI Third Party Integration Entity
 * 
 * Manages third-party service integration configurations.
 * 
 * @package Weline_Ai
 */
class AiThirdPartyIntegration extends Model
{
    // 框架自动推导表名：AiThirdPartyIntegration → ai_third_party_integration
    
    /**
     * Unit primary keys
     */
    public array $_unit_primary_keys = ['id'];
    
    /**
     * Index sort keys
     */
    public array $_index_sort_keys = ['id', 'integration_type', 'status'];
    
    /**
     * Field name constants
     */
    public const fields_ID = 'id';
    public const fields_INTEGRATION_NAME = 'integration_name';
    public const fields_INTEGRATION_TYPE = 'integration_type';
    public const fields_CONFIG = 'config';
    public const fields_STATUS = 'status';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    /**
     * Integration type constants
     */
    public const INTEGRATION_TYPE_OAUTH = 'oauth';
    public const INTEGRATION_TYPE_API = 'api';
    public const INTEGRATION_TYPE_WEBHOOK = 'webhook';

    /**
     * Status constants
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
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
            $setup->createTable('AI Third Party Integration')
            ->addColumn(
                self::fields_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '集成ID'
            )
            ->addColumn(
                self::fields_INTEGRATION_NAME,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '集成名称'
            )
            ->addColumn(
                self::fields_INTEGRATION_TYPE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                50,
                'not null',
                '集成类型'
            )
            ->addColumn(
                self::fields_CONFIG,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT,
                0,
                'not null',
                '集成配置（JSON）'
            )
            ->addColumn(
                self::fields_STATUS,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                20,
                'not null default \'active\'',
                '状态'
            )
            ->addColumn(
                self::fields_CREATED_AT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP,
                0,
                'not null default current_timestamp',
                '创建时间'
            )
            ->addColumn(
                self::fields_UPDATED_AT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP,
                0,
                'not null default current_timestamp',
                '更新时间'
            )
            ->addIndex('INDEX', 'idx_integration_type', self::fields_INTEGRATION_TYPE)
            ->addIndex('INDEX', 'idx_status', self::fields_STATUS)
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
