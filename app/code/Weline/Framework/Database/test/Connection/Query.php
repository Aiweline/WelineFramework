<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/5/26 23:48:30
 */

namespace Weline\Framework\Database\test\Connection;

use Weline\Framework\Database\DbManager;
use Weline\Framework\Manager\ObjectManager;

class Query extends \Weline\Framework\UnitTest\TestCore
{
    private DbManager $DbManager;

    function setUp(): void
    {
        $this->DbManager = ObjectManager::getInstance(DbManager::class);
    }

    /**
     * @DESC          # 测试主键和联合主键（索引排序）对查询条件的排序优化查询速度
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/5/26 23:50
     * 参数区：
     */
    function testUnitPrimaryKeysSortWhere()
    {
        $query = $this->DbManager->getConnection()->getQuery();
        $table = 'test_unit_primay_keys_sort_where';
        // 创建一张测试表test_unit_primary_keys_sort_where
        $query->query("create table IF NOT EXISTS $table (id int unsigned not null auto_increment, name varchar(32) not null,age varchar(255) not null, PRIMARY KEY (id,name))");

        $query->_index_sort_keys = ['id', 'name'];

        $sql = $query->table($table)->where('name', 'tt')->where('id', 1)->find()->getLastSql();
        $this->assertEquals(array(['id', '=', 1, 'AND'], ['name', '=', 'tt', 'AND']), $query->wheres , '(单表)testUnitPrimayKeysSortWhere:测试主键和联合主键（索引排序）对查询条件的排序优化查询速度');
        $sql = $query->table($table)->where('main_table.name', 'tt')->where('main_table.id', 1)->find()->getLastSql();
        $this->assertEquals(array(['main_table.id', '=', 1, 'AND'], ['main_table.name', '=', 'tt', 'AND']), $query->wheres, '（混合表）testUnitPrimayKeysSortWhere:测试主键和联合主键（索引排序）对查询条件的排序优化查询速度');
        $query->query("drop table $table");
    }
}