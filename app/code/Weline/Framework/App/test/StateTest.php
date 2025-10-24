<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\App\test;

use Weline\Framework\App\State;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;

class StateTest extends TestCore
{
    public function testGetStateCode()
    {
        /**@var $ob State */
        $ob = ObjectManager::getInstance(State::class);
        self::assertIsObject($ob);
    }
}
