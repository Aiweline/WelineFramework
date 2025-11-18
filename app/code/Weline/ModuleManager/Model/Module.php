<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\ModuleManager\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Module extends \Weline\Framework\Database\Model
{
    public const fields_ID             = 'module_id';
    public const fields_NAME           = 'name';
    public const fields_STATUS         = 'status';
    public const fields_DESCRIPTION    = 'description';
    public const fields_POSITION       = 'position';
    public const fields_NAMESPACE_PATH = 'namespace_path';
    public const fields_BASE_PATH      = 'base_path';
    public const fields_PATH           = 'path';
    public const fields_VERSION        = 'version';
    public const fields_LAST_VERSION   = 'last_version';
    public const fields_ROUTER         = 'router';

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
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', '模组ID')
                ->addColumn(self::fields_NAME, TableInterface::column_type_VARCHAR, 64, 'not null unique', '模组名')
                ->addColumn(self::fields_STATUS, TableInterface::column_type_SMALLINT, 1, 'not null default 0', '状态')
                ->addColumn(self::fields_DESCRIPTION, TableInterface::column_type_TEXT, null, '', '描述')
                ->addColumn(self::fields_POSITION, TableInterface::column_type_VARCHAR, 20, 'not null', '位置')
                ->addColumn(self::fields_NAMESPACE_PATH, TableInterface::column_type_VARCHAR, 128, 'not null', '命名空间')
                ->addColumn(self::fields_BASE_PATH, TableInterface::column_type_VARCHAR, 255, 'not null', '基础路径')
                ->addColumn(self::fields_PATH, TableInterface::column_type_VARCHAR, 255, 'not null', '路径')
                ->addColumn(self::fields_VERSION, TableInterface::column_type_VARCHAR, 18, 'not null', '版本')
                ->addColumn(self::fields_LAST_VERSION, TableInterface::column_type_VARCHAR, 18, "", '上一个版本')
                ->addColumn(self::fields_ROUTER, TableInterface::column_type_VARCHAR, 64, 'not null', '路由')
                ->create();
        }
    }
}
