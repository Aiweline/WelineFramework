<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * AI A/B Test Entity
 * 
 * Manages model A/B testing experiments.
 * 
 * @package Weline_Ai
 */
class AiAbTest extends Model
{
    // 框架自动推导表名：AiAbTest → ai_ab_test
    
    /**
     * Unit primary keys
     */
    public array $_unit_primary_keys = ['id'];
    
    /**
     * Index sort keys
     */
    public array $_index_sort_keys = ['id', 'model_a_id', 'model_b_id'];
    
    /**
     * Field name constants
     */
    public const fields_ID = 'id';
    public const fields_TEST_NAME = 'test_name';
    public const fields_MODEL_A_ID = 'model_a_id';
    public const fields_MODEL_B_ID = 'model_b_id';
    public const fields_TEST_CRITERIA = 'test_criteria';
    public const fields_TEST_RESULT = 'test_result';
    public const fields_WINNER_MODEL = 'winner_model';
    public const fields_STATUS = 'status';
    public const fields_STARTED_AT = 'started_at';
    public const fields_COMPLETED_AT = 'completed_at';
    public const fields_CREATED_AT = 'created_at';

    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Winner model constants
     */
    public const WINNER_MODEL_A = 'A';
    public const WINNER_MODEL_B = 'B';
    public const WINNER_TIE = 'TIE';

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
            $setup->createTable('AI A/B Test')
            ->addColumn(
                self::fields_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '测试ID'
            )
            ->addColumn(
                self::fields_TEST_NAME,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '测试名称'
            )
            ->addColumn(
                self::fields_MODEL_A_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'not null',
                '模型A ID'
            )
            ->addColumn(
                self::fields_MODEL_B_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'not null',
                '模型B ID'
            )
            ->addColumn(
                self::fields_TEST_CRITERIA,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT,
                0,
                'null',
                '测试标准（JSON）'
            )
            ->addColumn(
                self::fields_TEST_RESULT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT,
                0,
                'null',
                '测试结果（JSON）'
            )
            ->addColumn(
                self::fields_WINNER_MODEL,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                10,
                'null',
                '获胜模型'
            )
            ->addColumn(
                self::fields_STATUS,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                20,
                'not null default \'pending\'',
                '状态'
            )
            ->addColumn(
                self::fields_STARTED_AT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP,
                0,
                'null',
                '开始时间'
            )
            ->addColumn(
                self::fields_COMPLETED_AT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP,
                0,
                'null',
                '完成时间'
            )
            ->addColumn(
                self::fields_CREATED_AT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP,
                0,
                'not null default current_timestamp',
                '创建时间'
            )
            ->addIndex('INDEX', 'idx_model_a', self::fields_MODEL_A_ID)
            ->addIndex('INDEX', 'idx_model_b', self::fields_MODEL_B_ID)
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
