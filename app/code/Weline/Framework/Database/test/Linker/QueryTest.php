<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database\test\Linker;

use Weline\Framework\App\Debug;
use Weline\Framework\Database\Connection;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Manager\ObjectManager;

class QueryTest extends \Weline\Framework\UnitTest\TestCore
{
    public function testWhere()
    {
        /**@var \Weline\Framework\Database\DbManager $dbManager */
        $dbManager = ObjectManager::getInstance(\Weline\Framework\Database\DbManager::class);
        /**@var QueryInterface $query */
        $query = $dbManager->create()->getQuery();
        // 创建测试表 test_weline_for_query
        $sql = $query->query("drop table IF EXISTS test_weline_for_query;create table IF NOT EXISTS test_weline_for_query (
        id int primary key, 
        name varchar(32) not null,
        stores int not null
        );")->fetch();

        # 增
        self::assertTrue(1 == $query->table('test_weline_for_query')->insert(['name' => 'test', 'stores' => 1])->fetch());
        self::assertTrue(2 == $query->table('test_weline_for_query')->insert(['name' => 'test2', 'stores' => 2])->fetch());
        self::assertTrue(3 == $query->table('test_weline_for_query')->insert(['name' => 'test3', 'stores' => 3])->fetch());
        # 查
        self::assertEquals(1, $query->table('test_weline_for_query')->where('id', 1)->find('id')->fetch());
        self::assertEquals(2, $query->table('test_weline_for_query')->where('id', 2)->find('id')->fetch());
        self::assertEquals(3, $query->table('test_weline_for_query')->where('id', 3)->find('id')->fetch());
        # 删
        $query->table('test_weline_for_query')->where('id', 3)->delete()->fetch();
        self::assertEquals(null, $query->table('test_weline_for_query')->where('id', 3)->find('id')->fetch());
        $query->table('test_weline_for_query')->insert(['name' => 'test3', 'stores' => 3])->fetch();
        # 改条件
        $sql = $query->reset()->table('test_weline_for_query')
            ->alias('a')
            ->where('a.id', 3)
            ->update([
                ['stores' => 333,],
            ])->fetch();
        self::assertTrue($query->table('test_weline_for_query')->where('id', 3)->find('stores')->fetch() == 333);
        # 改
        $query->table('test_weline_for_query')
            ->alias('a')
            ->update([
                ['id' => 1,
                    'stores' => 111,],
                ['id' => 2,
                    'stores' => 222,],
                ['id' => 3,
                    'stores' => 333,],
            ])->fetch();
        self::assertTrue($query->table('test_weline_for_query')->where('id', 1)->find('stores')->fetch() == 111);
        self::assertTrue($query->table('test_weline_for_query')->where('id', 2)->find('stores')->fetch() == 222);
        self::assertTrue($query->table('test_weline_for_query')->where('id', 3)->find('stores')->fetch() == 333);
        # 改
        $query->table('test_weline_for_query')
            ->alias('a')
            ->update([
                ['id' => 1,
                    'stores' => 1,],
                ['id' => 2,
                    'stores' => 2,],
                ['id' => 3,
                    'stores' => 3,]
            ])->fetch();
        self::assertTrue($query->table('test_weline_for_query')->where('id', 1)->find('stores')->fetch() == 1);
        self::assertTrue($query->table('test_weline_for_query')->where('id', 2)->find('stores')->fetch() == 2);
        self::assertTrue($query->table('test_weline_for_query')->where('id', 3)->find('stores')->fetch() == 3);

        # 查
        self::assertTrue(1 == $query->table('test_weline_for_query')
                ->alias('a')
                ->where("(a.stores = '1') OR (a.stores like '%1%')")
                ->where('a.id=1')->where('a.id', 1)
                ->find('id')->fetch());
        self::assertTrue(1 == $query->table('test_weline_for_query')->alias('a')->where([['a.stores', 1], ['a.id', 1]])->find('id')->fetch());
        self::assertTrue(1 == $query->table('test_weline_for_query')->alias('a')->where([['a.stores', '=', '1', 'OR'], ['a.stores', 'like', '%1%']])->find('id')->fetch());
        self::assertTrue(1 == $query->table('test_weline_for_query')->alias('a')->fields('a.`id`,a.`stores`')->where('id', 1)->where('stores', 1)->find('id')->fetch());

        # 联查
        # 创建test_weline_for_store_user
        $query->query("DROP table IF EXISTS `test_weline_for_store_user`;CREATE TABLE IF NOT EXISTS `test_weline_for_store_user` (
            `id` int(11) NOT NULL PRIMARY KEY,
            `store_id` int(11) NOT NULL,
            `user_id` int(11) NOT NULL
        )")->fetch();
        $query->query("INSERT INTO `test_weline_for_store_user` (`id`, `store_id`, `user_id`) VALUES
            (1, 1, 1),
            (2, 1, 2),
            (3, 1, 3);")->fetch();
        Debug::env('pre_fetch', false);
        Debug::env('dd', false);
        self::assertTrue(1 == $query->table('test_weline_for_query')
                ->alias('a')
                ->join('test_weline_for_store_user su', 'su.store_id=a.stores', 'left')
                ->where('a.id', 1)
                ->where('a.stores', 1)
                ->find('a.id')
                ->fetch());
        self::assertTrue(1 == $query->table('test_weline_for_query')
                ->alias('a')
                ->join('test_weline_for_store_user u', 'u.store_id=a.stores', 'left')
                ->where('a.id', 1)
                ->order('a.id')
                ->find('a.id')->fetch()
        );

        # 卸载表
        $query->query("DROP TABLE IF EXISTS `test_weline_for_query`")->fetch();
        $query->query("DROP TABLE IF EXISTS `test_weline_for_store_user`")->fetch();
    }
}
