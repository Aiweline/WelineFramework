<?php

namespace Weline\Framework\Database\test\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class WelineModel extends Model
{
    public const table = 'test_weline_model';

    public const fields_ID = 'id';
    public const fields_stores = 'stores';
    public const fields_name = 'name';

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // TODO: Implement upgrade() method.
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }

        $setup->createTable('测试Weline表')
            ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'primary key auto_increment', '测试ID')
            ->addColumn(self::fields_stores, TableInterface::column_type_VARCHAR, 60, 'not null', '测试店')
            ->addColumn(self::fields_name, TableInterface::column_type_VARCHAR, 255, 'not null', '测试名')
            ->create();
    }
}