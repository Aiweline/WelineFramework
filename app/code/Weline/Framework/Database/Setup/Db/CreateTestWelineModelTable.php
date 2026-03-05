<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Setup\Db;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\test\Model\WelineModel;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * Creates test_weline_model table for Framework Database module tests.
 * Table DDL moved from WelineModel::install() so Model no longer contains setup/install logic.
 */
class CreateTestWelineModelTable
{
    public static function run(): void
    {
        /** @var WelineModel $model */
        $model = ObjectManager::getInstance(WelineModel::class);
        /** @var ModelSetup $setup */
        $setup = ObjectManager::make(ModelSetup::class);
        $setup->putModel($model);

        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('测试Weline表')
            ->addColumn(WelineModel::schema_fields_ID, TableInterface::column_type_INTEGER, 0, 'primary key auto_increment', '测试ID')
            ->addColumn(WelineModel::schema_fields_stores, TableInterface::column_type_VARCHAR, 60, 'not null', '测试店')
            ->addColumn(WelineModel::schema_fields_name, TableInterface::column_type_VARCHAR, 255, 'not null', '测试名')
            ->create();
    }
}
