<?php

namespace Shopify\Site\Model;

use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Site extends Model
{
    public const fields_ID = 'id';
    public const fields_name = 'name';
    public const fields_url = 'url';

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {

    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist()) {
            return;
        }
        $setup->createTable()
            ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', 'ID')
            ->addColumn(self::fields_name, TableInterface::column_type_VARCHAR, 255, 'not null', '站点名称')
            ->addColumn(self::fields_url, TableInterface::column_type_VARCHAR, 255, 'not null', '站点URL')
            ->create();
    }
}