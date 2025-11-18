<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\System\File\App;

use PHPUnit\Framework\TestCase;

class ScannerTest extends TestCase
{
    public function testScanModules()
    {
        $scanner = new Scanner();
        $modules = $scanner->scanAppModules();
        $this->assertTrue(is_array($modules));
        $this->assertTrue(count($modules) > 0);
    }
}
