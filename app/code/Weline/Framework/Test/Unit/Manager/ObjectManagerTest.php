<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Manager\UnitTest;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;

class ObjectManagerTest extends TestCore
{
    public function testGetInstance(): void
    {
        $instance = ObjectManager::getInstance();

        self::assertSame($instance, ObjectManager::getInstance());
    }
}
