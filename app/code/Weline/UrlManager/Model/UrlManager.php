<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\UrlManager\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class UrlManager extends \Weline\Framework\Database\Model
{
    public const fields_ID        = 'url_id';
    public const fields_PATH      = 'path';
    public const fields_IDENTIFY  = 'identify';
    public const fields_MODULE_ID = 'module_id';
    public const fields_IS_DELETE = 'is_deleted';
    public const fields_TYPE      = 'type';
    public const fields_DATA      = 'data';

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

    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
//        $setup->dropTable();
        if (!$setup->tableExist()) {
            $setup->createTable()
                  ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'URL ID')
                  ->addColumn(self::fields_PATH, TableInterface::column_type_VARCHAR, 255, 'not null', 'URL路径')
                  ->addColumn(self::fields_IDENTIFY, TableInterface::column_type_VARCHAR, 255, 'not null', 'URI指纹')
                  ->addColumn(self::fields_MODULE_ID, TableInterface::column_type_INTEGER, null, 'not null', '所属模块ID')
                  ->addColumn(self::fields_TYPE, TableInterface::column_type_VARCHAR, 20, 'not null', '路由类型')
                  ->addColumn(self::fields_IS_DELETE, TableInterface::column_type_SMALLINT, 1, 'default 0 not null ', '是否已删除')
                  ->addColumn(self::fields_DATA, TableInterface::column_type_TEXT, null, '', '路由数据')
                  ->addIndex(TableInterface::index_type_UNIQUE, self::fields_IDENTIFY, self::fields_IDENTIFY, 'path路径指纹不能重复')
                  ->create();
        }
    }
}
