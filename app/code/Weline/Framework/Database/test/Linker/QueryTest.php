<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database\test\Connection;

use Weline\Framework\Database\Connection;
use Weline\Framework\Manager\ObjectManager;

class QueryTest extends \Weline\Framework\UnitTest\TestCore
{
    public function testWhere()
    {
        /**@var \Weline\Framework\Database\DbManager $dbManager */
        $dbManager = ObjectManager::getInstance(\Weline\Framework\Database\DbManager::class);
        /**@var Connection\QueryInterface $query */
        $query = $dbManager->create()->getQuery();
        # 增
//        pp($query->table('weline')
//            ->insert(['stores'=>4])->fetch()
//        );
        # 删
//        pp($query->table('store_user')
//            ->where('id', 3)
//            ->order('id')
//            ->delete()->fetch()
//        );
        # 改
        pp(
            $query->table('weline')
//            ->alias('a')
//            ->where('a.id', 3)
                  ->update([
                               ['id'     => 1,
                                'stores' => 1,],
                               /* ['id' => 2,
                                    'stores' => 2,],
                                ['id' => 3,
                                    'stores' => 3,]*/
                           ])->fetch()
//            ],'id')->getLastSql() # 默认条件更新
//            ],'id1')->getLastSql() #自定义非默认字段时
//            ])->getLastSql() # 普通条件更新时
        );
        # 查
//        pp($query->table('weline')->alias('a')->where("(a.stores = '1') OR (a.stores like '%1%')")->where('a.id=1')->where('a.id',1)->find()->fetch());
//        pp($query->table('weline')->alias('a')->where([['a.stores', 1], ['a.id', 1]])->find()->fetch());
//        pp($query->table('weline')->alias('a')->where([ ['a.stores', '=', '1', 'OR'], ['a.stores', 'like', '%1%'] ])->find()->fetch());
//        pp($query->table('weline')->alias('a')->fields('a.`id`,a.`stores`')->where('id',1)->where('stores',1)->find()->fetch());
//        pp($query->table('weline')
//            ->alias('a')
//            ->join('store_user su', 'su.store_id=a.stores','left')
//            ->where('a.id', 1)
//            ->where('a.stores', 1)
//            ->find()
//            ->fetch()
//        );
        # 联查
//        pp($query->table('weline')
//            ->alias('a')
//            ->join('store_user u', 'u.store_id=a.stores','left')
//            ->where('a.id', 1)
//            ->order('a.id')
//            ->find()->fetch()
//        );

        p($query->getLastSql());
    }
}
