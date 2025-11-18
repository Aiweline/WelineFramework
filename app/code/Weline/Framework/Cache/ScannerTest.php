<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Cache;

use PHPUnit\Framework\TestCase;

class ScannerTest extends TestCase
{
    public function testScanAppCaches()
    {
        $scanner = new Scanner();
        $data    = $scanner->scanAppCaches();
        $this->assertTrue(true);
    }

    public function testScanFrameworkCaches()
    {
        $scanner = new Scanner();
        $data    = $scanner->scanFrameworkCaches();
        p($data);
        $this->assertTrue(true);
    }
}
