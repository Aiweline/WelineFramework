<?php
declare(strict_types=1);

namespace Weline\Ai\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * 模型基准测试模型
 */
class AiModelBenchmark extends Model
{
    public const table = 'ai_model_benchmark';
    
    public const fields_ID = 'id';
    public const fields_MODEL_ID = 'model_id';
    public const fields_SCORE = 'score';
    public const fields_METRICS = 'metrics';
    public const fields_CREATED_TIME = 'created_time';

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 升级逻辑 - 目前无需升级
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 11, 'primary key auto_increment', 'ID')
                ->addColumn(self::fields_MODEL_ID, TableInterface::column_type_INTEGER, 11, 'not null', '模型ID')
                ->addColumn(self::fields_SCORE, TableInterface::column_type_DECIMAL, '10,4', 'not null', '基准分数')
                ->addColumn(self::fields_METRICS, TableInterface::column_type_TEXT, null, 'null', '测试指标')
                ->addColumn(self::fields_CREATED_TIME, TableInterface::column_type_INTEGER, 11, 'not null', '创建时间')
                ->create();
        }
    }
}
