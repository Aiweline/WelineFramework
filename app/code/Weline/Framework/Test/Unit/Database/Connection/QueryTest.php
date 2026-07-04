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

class QueryTest extends \Weline\Framework\UnitTest\TestCore
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
        $query = $this->DbManager->getConnection()->getConnector()->getQuery();
        $table = 'test_unit_primay_keys_sort_where';
        $query->_index_sort_keys = ['id', 'name'];

        $sql = $query->table($table)
            ->where('name', 'tt')
            ->where('id', 1)
            ->find()
            ->getSql();
        $this->assertEquals(array(['id', '=', 1, 'AND'], ['name', '=', 'tt', 'AND']), $query->wheres, '(单表)testUnitPrimayKeysSortWhere:测试主键和联合主键（索引排序）对查询条件的排序优化查询速度');
        $sql = $query->reset()->table($table)->identity('main_table.id')
            ->where('main_table.name', 'tt')
            ->where('main_table.id', 1)
            ->find()
            ->getSql();

        $this->assertEquals(array(['`main_table`.`id`', '=', 1, 'AND'], ['`main_table`.`name`', '=', 'tt', 'AND']), $query->wheres, '（混合表）testUnitPrimayKeysSortWhere:测试主键和联合主键（索引排序）对查询条件的排序优化查询速度');
    }
}