<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Module\test;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Helper\Scanner;
use Weline\Framework\UnitTest\TestCore;

class TestScanner extends TestCore
{
    private Scanner $scanner;

    public function setUp(): void
    {
        $this->scanner = ObjectManager::getInstance(Scanner::class);
    }

    public function testScanner()
    {
        p($this->scanner->getEtcFile('M_Admin'));
    }
}
