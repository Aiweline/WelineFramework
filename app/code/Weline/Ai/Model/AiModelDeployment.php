<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * AI Model Deployment Entity
 * 
 * Records model deployment information.
 * 
 * @package Weline_Ai
 */
class AiModelDeployment extends Model
{
    // 框架自动推导表名：AiModelDeployment → ai_model_deployment
    
    /**
     * Unit primary keys
     */
    public array $_unit_primary_keys = ['id'];
    
    /**
     * Index sort keys
     */
    public array $_index_sort_keys = ['id', 'model_id', 'deployment_status'];
    
    /**
     * Field name constants
     */
    public const fields_ID = 'id';
    public const fields_MODEL_ID = 'model_id';
    public const fields_DEPLOYMENT_NAME = 'deployment_name';
    public const fields_DEPLOYMENT_TYPE = 'deployment_type';
    public const fields_DEPLOYMENT_STATUS = 'deployment_status';
    public const fields_DEPLOYMENT_URL = 'deployment_url';
    public const fields_DEPLOYED_AT = 'deployed_at';
    public const fields_CREATED_AT = 'created_at';

    /**
     * Deployment type constants
     */
    public const DEPLOYMENT_TYPE_CLOUD = 'cloud';
    public const DEPLOYMENT_TYPE_LOCAL = 'local';
    public const DEPLOYMENT_TYPE_HYBRID = 'hybrid';

    /**
     * Deployment status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_DEPLOYING = 'deploying';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_FAILED = 'failed';

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
            $setup->createTable('AI Model Deployment')
            ->addColumn(
                self::fields_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '部署ID'
            )
            ->addColumn(
                self::fields_MODEL_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'not null',
                '模型ID'
            )
            ->addColumn(
                self::fields_DEPLOYMENT_NAME,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '部署名称'
            )
            ->addColumn(
                self::fields_DEPLOYMENT_TYPE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                50,
                'not null',
                '部署类型'
            )
            ->addColumn(
                self::fields_DEPLOYMENT_STATUS,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                50,
                'not null default \'pending\'',
                '部署状态'
            )
            ->addColumn(
                self::fields_DEPLOYMENT_URL,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                500,
                'null',
                '部署URL'
            )
            ->addColumn(
                self::fields_DEPLOYED_AT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP,
                0,
                'null',
                '部署时间'
            )
            ->addColumn(
                self::fields_CREATED_AT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP,
                0,
                'not null default current_timestamp',
                '创建时间'
            )
            ->addIndex('INDEX', 'idx_model_id', self::fields_MODEL_ID)
            ->addIndex('INDEX', 'idx_deployment_status', self::fields_DEPLOYMENT_STATUS)
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
