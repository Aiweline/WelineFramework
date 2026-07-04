<?php

namespace Weline\Framework\Database\test;

use Weline\Framework\UnitTest\TestCore;
use Weline\Framework\Database\Helper\Tool;

class GetSqlTableTest extends TestCore
{
    function testGetSqlTables()
    {
        $sql    = "select count(*) as count from order as a left join user as b on a.user_id = b.user_id where a.title like '%衣服%'";
        $expect = ['select' => ['order', 'user']];
        $this->assertEquals($expect, Tool::sql2table($sql));
        $sql    = "update oc_user as u set name='1' where user_id=1";
        $expect = ['update' => ['oc_user']];
        $this->assertEquals($expect, Tool::sql2table($sql));
        $sql    = 'delete from  oc_user as u where user_id=1';
        $expect = ['delete' => ['oc_user']];
        $this->assertEquals($expect, Tool::sql2table($sql));
        $sql    = "insert into  oc_user as u  set u.name='1' where user_id=1";
        $expect = ['insert' => ['oc_user']];
        $this->assertEquals($expect, Tool::sql2table($sql));
    }
}