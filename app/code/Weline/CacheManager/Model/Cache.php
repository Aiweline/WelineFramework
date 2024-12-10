<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\CacheManager\Model;

use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Cache extends \Weline\Framework\Database\Model
{
    public const fields_ID          = 'id';
    public const fields_NAME        = 'name';
    public const fields_Status      = 'status';
    public const fields_Permanently = 'permanently';
    public const fields_Module      = 'module';
    public const fields_IDENTITY    = 'identity';
    public const fields_TYPE        = 'type';
    public const fields_FILE        = 'file';
    public const fields_DESCRIPTION = 'description';

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
        if($setup->tableExist()) $setup->query('TRUNCATE TABLE ' . $this->getTable());
        if (!$setup->tableExist()) {
            $setup->getPrinting()->setup('安装数据表...', $setup->getTable());
            $setup->createTable('缓存')
                  ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'primary key auto_increment', 'ID')
                  ->addColumn(self::fields_NAME, TableInterface::column_type_VARCHAR, 255, 'not null', '名称')
                  ->addColumn(self::fields_Status, TableInterface::column_type_INTEGER, 1, 'not null default 1', '状态:1启用,0禁用')
                  ->addColumn(self::fields_Permanently, TableInterface::column_type_INTEGER, 1, 'not null default 0', '持久化：0不持久化，1持久化')
                  ->addColumn(self::fields_Module, TableInterface::column_type_VARCHAR, 255, '', '模组')
                  ->addColumn(self::fields_TYPE, TableInterface::column_type_INTEGER, 1, 'not null default 0', '类型:0-系统缓存,1-应用缓存')
                  ->addColumn(self::fields_IDENTITY, TableInterface::column_type_VARCHAR, 255, 'not null', '标志')
                  ->addColumn(self::fields_FILE, TableInterface::column_type_VARCHAR, 1000, 'not null', '文件位置')
                  ->addColumn(self::fields_DESCRIPTION, TableInterface::column_type_TEXT, 0, '', '描述')
                  ->create();
        }
    }
}
