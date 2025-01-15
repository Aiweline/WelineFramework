<?php

namespace WeShop\Product\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Test extends Model
{
    public string $table='gvanda_test';
    public const fields_ID   = 'test_id';
    public const fields_name = 'name';

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // TODO: Implement upgrade() method.
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('测试表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    0,
                    'primary key auto_increment',
                    '测试ID'
                )
                ->addColumn(
                    self::fields_name,
                    TableInterface::column_type_VARCHAR,
                    255,
                    '',
                    '测试名称'
                )
                ->create();
        }
    }

    function getName(): string
    {
        return $this->getData(self::fields_name);
    }

    function setName(string $name): static
    {
        $this->setData(self::fields_name, $name);
        return $this;
    }
}