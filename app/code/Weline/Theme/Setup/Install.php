<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Setup;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Db\Ddl\Table\Create;
use Weline\Framework\Setup\Data;
use Weline\Framework\Setup\InstallInterface;

class Install implements InstallInterface
{
    public const table_THEME = 'weline_theme';

    public function setup(Data\Setup $setup, Data\Context $context): void
    {
        $printer = $setup->getPrinter();
        $printer->note('安装开始...');
        $db = $setup->getDb();
        /*主题表*/
        $printer->warning('安装数据库表：' . self::table_THEME);
        if (!$db->tableExist(self::table_THEME)) {
            $setup->getDb()->createTable(
                self::table_THEME,
                '主题表'
            )->addColumn(
                'id',
                TableInterface::column_type_INTEGER,
                11,
                'primary key NOT NULL AUTO_INCREMENT',
                'ID'
            )->addColumn(
                'module_name',
                TableInterface::column_type_VARCHAR,
                '60',
                'UNIQUE NOT NULL ',
                '主题模块名'
            )->addColumn(
                'name',
                TableInterface::column_type_VARCHAR,
                '60',
                'UNIQUE NOT NULL ',
                '主题名'
            )->addColumn(
                'path',
                TableInterface::column_type_VARCHAR,
                '128',
                'UNIQUE NOT NULL ',
                '主题路径'
            )->addColumn(
                'parent_id',
                TableInterface::column_type_INTEGER,
                11,
                '',
                '父级主题'
            )->addColumn(
                'is_active',
                TableInterface::column_type_INTEGER,
                11,
                '',
                '是否激活'
            )->addColumn(
                'create_time',
                TableInterface::column_type_DATETIME,
                null,
                'NOT NULL DEFAULT CURRENT_TIMESTAMP',
                '安装时间'
            )->addColumn(
                'update_time',
                TableInterface::column_type_DATETIME,
                null,
                'NOT NULL DEFAULT CURRENT_TIMESTAMP',
                '更新时间'
            )->addIndex(
                TableInterface::index_type_DEFAULT,
                'parent_id',
                'parent_id'
            )->create();
        }
//        $printer->warning('正在写入默认主题...');
//        /**@var WelineTheme $welineTheme*/
//        $welineTheme = ObjectManager::getInstance(WelineTheme::class);
//        $welineTheme->setData(Env::default_theme_DATA)
//            ->save();
        $printer->note('安装结束...');
    }
}
