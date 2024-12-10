<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/12/22 14:42:04
 */

namespace Weline\I18n\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\I18n\Model\Countries\Locale\Name;

class Countries extends \Weline\Framework\Database\Model
{
    public const fields_ID = 'code';
    public const fields_CODE = 'code';
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
                ->addColumn(self::fields_ID, TableInterface::column_type_VARCHAR, 10, 'primary key', '国家码')
                ->addColumn(self::fields_FLAG, TableInterface::column_type_TEXT, 20000, 'not null', '国旗')
                ->addColumn(self::fields_IS_ACTIVE, TableInterface::column_type_SMALLINT, 1, 'not null default 0', '启用状态')
                ->addColumn(self::fields_IS_INSTALL, TableInterface::column_type_SMALLINT, 1, 'not null default 0', '是否安装')
                ->addIndex(TableInterface::index_type_KEY, 'idx_code', self::fields_CODE, '国家码索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_is_active', self::fields_IS_ACTIVE, '状态索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_is_install', self::fields_IS_INSTALL, '安装状态索引')
                ->create();
        }
    }

    public function getLocaleNameModel(): Name
    {
        return ObjectManager::getInstance(Name::class);
    }

    public function getLocaleModel(): Locale
    {
        return ObjectManager::getInstance(Locale::class);
    }
}
