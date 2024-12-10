<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/12/21 22:05:23
 */

namespace Weline\I18n\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Locals extends \Weline\Framework\Database\Model
{
    public const fields_ID = 'code';
    public const fields_CODE = 'code';
    public const fields_TARGET_CODE = 'target_code';
    public const fields_NAME = 'name';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_IS_INSTALL = 'is_install';
    public const fields_FLAG = 'flag';

    public array $_unit_primary_keys = ['code', 'target_code'];

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
                ->addColumn(self::fields_ID, TableInterface::column_type_VARCHAR, 10, 'not null', '地方代码')
                ->addColumn(self::fields_TARGET_CODE, TableInterface::column_type_VARCHAR, 10, 'not null', '展示的地方代码')
                ->addColumn(self::fields_NAME, TableInterface::column_type_VARCHAR, 60, 'not null', '展示的地方代码对应地方代码名称')
                ->addColumn(self::fields_IS_ACTIVE, TableInterface::column_type_SMALLINT, 1, 'not null default 0', '启用状态')
                ->addColumn(self::fields_IS_INSTALL, TableInterface::column_type_SMALLINT, 1, 'not null default 0', '是否安装')
                ->addColumn(self::fields_FLAG, TableInterface::column_type_TEXT, 20000, '', 'svg国旗')
                ->addIndex(TableInterface::index_type_KEY, 'idx_code', self::fields_CODE, '区码索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_target_code', self::fields_TARGET_CODE, '目标区码索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_name', self::fields_NAME, '名字索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_is_active', self::fields_IS_ACTIVE, '状态索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_is_install', self::fields_IS_INSTALL, '安装索引')
                ->create();
        }
    }
}
