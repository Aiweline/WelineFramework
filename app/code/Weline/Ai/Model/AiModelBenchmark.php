<?php

declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

/**
 * AI Model Benchmark Entity
 * 
 * Stores model benchmark test results.
 * 
 * @package Weline_Ai
 */
class AiModelBenchmark extends Model
{
    // 框架自动推导表名：AiModelBenchmark → ai_model_benchmark
    
    /**
     * Unit primary keys
     */
    public array $_unit_primary_keys = ['id'];
    
    /**
     * Index sort keys
     */
    public array $_index_sort_keys = ['id', 'model_id', 'benchmark_type'];
    
    /**
     * Field name constants
     */
    public const fields_ID = 'id';
    public const fields_MODEL_ID = 'model_id';
    public const fields_BENCHMARK_NAME = 'benchmark_name';
    public const fields_BENCHMARK_TYPE = 'benchmark_type';
    public const fields_BENCHMARK_RESULT = 'benchmark_result';
    public const fields_BENCHMARK_SCORE = 'benchmark_score';
    public const fields_TESTED_AT = 'tested_at';

    /**
     * Benchmark type constants
     */
    public const BENCHMARK_TYPE_PERFORMANCE = 'performance';
    public const BENCHMARK_TYPE_ACCURACY = 'accuracy';
    public const BENCHMARK_TYPE_COST = 'cost';

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
            $setup->createTable('AI Model Benchmark')
            ->addColumn(
                self::fields_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '测试ID'
            )
            ->addColumn(
                self::fields_MODEL_ID,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER,
                0,
                'not null',
                '模型ID'
            )
            ->addColumn(
                self::fields_BENCHMARK_NAME,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '基准测试名称'
            )
            ->addColumn(
                self::fields_BENCHMARK_TYPE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR,
                50,
                'not null',
                '测试类型'
            )
            ->addColumn(
                self::fields_BENCHMARK_RESULT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT,
                0,
                'null',
                '测试结果（JSON格式）'
            )
            ->addColumn(
                self::fields_BENCHMARK_SCORE,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL,
                '10,4',
                'null',
                '测试得分'
            )
            ->addColumn(
                self::fields_TESTED_AT,
                \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP,
                0,
                'not null default current_timestamp',
                '测试时间'
            )
            ->addIndex('INDEX', 'idx_model_id', self::fields_MODEL_ID)
            ->addIndex('INDEX', 'idx_benchmark_type', self::fields_BENCHMARK_TYPE)
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
