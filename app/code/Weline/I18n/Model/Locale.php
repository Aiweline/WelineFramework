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
use Weline\I18n\Model\Locale\Name;

class Locale extends \Weline\Framework\Database\Model
{
    public const fields_ID = 'code';
    public const fields_CODE = 'code';
    public const fields_COUNTRY_CODE = 'country_code';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_IS_INSTALL = 'is_install';
    public const fields_FLAG = 'flag';

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
                ->addColumn(self::fields_ID, TableInterface::column_type_VARCHAR, 12, 'primary key', '地方代码')
                ->addColumn(self::fields_COUNTRY_CODE, TableInterface::column_type_VARCHAR, 2, 'not null', '国家码')
                ->addColumn(self::fields_IS_ACTIVE, TableInterface::column_type_SMALLINT, 1, 'not null default 0', '启用状态')
                ->addColumn(self::fields_IS_INSTALL, TableInterface::column_type_SMALLINT, 1, 'not null default 0', '是否安装')
                ->addColumn(self::fields_FLAG, TableInterface::column_type_TEXT, 100000, '', '国旗')
                ->addIndex(TableInterface::index_type_KEY, 'idx_code', self::fields_COUNTRY_CODE, '国家码索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_is_active', self::fields_IS_ACTIVE, '状态索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_is_install', self::fields_IS_INSTALL, '安装索引')
                ->create();
        }
    }
}
