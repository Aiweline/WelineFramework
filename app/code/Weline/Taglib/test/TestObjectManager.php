<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/7/1 12:43:30
 */

namespace Weline\Taglib\test;

class TestObjectManager extends \Weline\Framework\UnitTest\TestCore
{
    function testT()
    {
        $cache = w_cache('taglib');
        $this->assertNotNull($cache);
    }
}