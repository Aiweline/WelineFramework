<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/12/29 20:17:16
 */

namespace Weline\I18n\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Dictionary extends \Weline\Framework\Database\Model
{

    const fields_ID = 'word';
    const fields_WORD = 'word';
    const fields_IS_BACKEND = 'is_backend';
    const fields_MODULE = 'module';

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
            $setup->createTable()
                ->addColumn(self::fields_ID, TableInterface::column_type_TEXT, null, 'not null', '词')
                ->addColumn(self::fields_IS_BACKEND, TableInterface::column_type_INTEGER, 1, 'not null default 0', '是否后端：0，前端；1、后端')
                ->addColumn(self::fields_MODULE, TableInterface::column_type_VARCHAR, 128, 'default null', '模组名')
                ->addIndex(\Weline\Framework\Database\Api\Db\TableInterface::index_type_KEY, 'idx_module', self::fields_MODULE, '模组索引')
                ->addIndex(\Weline\Framework\Database\Api\Db\TableInterface::index_type_KEY, 'idx_is_backend', self::fields_IS_BACKEND, '前后端标识索引')
                ->create();
        }
    }
}