<?php

namespace Weline\Framework\Database\test;

use Weline\Framework\Database\Connection\Api\Sql\SqlTrait;
use Weline\Framework\UnitTest\TestCore;

class TestSqlTrait extends TestCore
{
    use SqlTrait;
    function testParseSqlField()
    {
        $sql = 'select find delete `main_table`.`parent_source    is  null or   main_table`.`parent_source="123.123=j1j12jj"` main.enable=t.enable  t.hh as h';
        $ok_sql = 'select find delete `main_table`.`parent_source` is null or `main_table`.`parent_source`="123.123=j1j12jj" `main`.`enable`=`t`.`enable` `t`.`hh` as `h`';
//        d($sql);
//        dd(SqlTrait::parserFiled($sql));
        $res1 = SqlTrait::parserFiled($sql)==$ok_sql;
        $where_sql = 'WHERE (`DATE(main_table`.`create_time)` = DATE(CURDATE()-1))';
        $ok_where_sql = 'WHERE (DATE(`main_table`.`create_time`) = DATE(CURDATE()-1))';
//        d($where_sql);
//        dd(SqlTrait::parserFiled($where_sql));
        $res2 = SqlTrait::parserFiled($where_sql)==$ok_where_sql;

        $this->assertTrue($res1 && $res2);
    }
}