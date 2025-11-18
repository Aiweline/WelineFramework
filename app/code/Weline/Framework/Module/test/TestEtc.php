<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Module\test;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Helper\Etc;
use Weline\Framework\UnitTest\TestCore;

class TestEtc extends TestCore
{
    private Etc $etc;

    public function setUp(): void
    {
        $this->etc = ObjectManager::getInstance(Etc::class);
    }

    public function testGetMenuConfig()
    {
        p($this->etc->getMenuConfig('M_Admin'));
    }
}
