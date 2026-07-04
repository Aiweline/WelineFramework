<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Router\UnitTest;

use Weline\Framework\UnitTest\Boot;
use PHPUnit\Framework\TestCase;

class CoreTest extends TestCase
{
    use Boot;

    public function testStart()
    {
        p(\Weline\Framework\Router\Core::getInstance()->start());
    }
}
