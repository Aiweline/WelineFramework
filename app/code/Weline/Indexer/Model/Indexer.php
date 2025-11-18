<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Indexer\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Indexer extends Model
{
    public string $table = 'db_indexer';
    public const fields_ID     = 'indexer_id';
    public const fields_NAME   = 'name';
    public const fields_MODULE = 'module_name';
    public const fields_MODEL  = 'module_model';
    public const fields_TABLE  = 'module_table';

    public const indexer = 'weline_indexer';

    public function setup(ModelSetup $setup, Context $context): void
    {
//         $setup->dropTable();
        $this->install($setup, $context);
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            # 创建索引表
            $setup->createTable()
                  ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, null, 'primary key auto_increment', '索引ID')
                  ->addColumn(self::fields_NAME, TableInterface::column_type_VARCHAR, 255, 'not null', '索引名')
                  ->addColumn(self::fields_MODULE, TableInterface::column_type_VARCHAR, 255, 'not null', '模块名')
                  ->addColumn(self::fields_MODEL, TableInterface::column_type_VARCHAR, 255, 'not null', 'Model模型类名')
                  ->addColumn(self::fields_TABLE, TableInterface::column_type_VARCHAR, 255, 'not null', 'Model模型表名')
                  ->create();
        }
    }
}
