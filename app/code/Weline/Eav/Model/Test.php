<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/3/14 20:35:28
 */

namespace Weline\Eav\Model;

use Weline\Eav\EavModel;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Test extends EavModel
{
    public string $table = 'eav_test';
    public const fields_ID   = 'test_id';
    public const fields_name = 'name';

    public string $entity_code = 'test';
    public string $entity_name = '测试';
    public string $eav_entity_id_field_type = TableInterface::column_type_INTEGER;
    public int $eav_entity_id_field_length = 11;

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
//        $setup->dropTable();
        if (!$setup->tableExist()) {
            $setup->createTable('测试Eav表')
                  ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'primary key auto_increment', '测试ID')
                  ->addColumn(self::fields_name, TableInterface::column_type_VARCHAR, 60, 'not null', '测试名')
                  ->create();
            $this->setData('name', 'test1')
                 ->save();
        }
    }
}