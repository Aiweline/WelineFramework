<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Cache\Test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Scanner;

use function PHPUnit\Framework\assertIsArray;

class ScannerTest extends TestCase
{
    public function testScanAppCaches()
    {
        $scanner = new Scanner();
        $data    = $scanner->scanAppCaches();
        assertIsArray($data);
    }

    public function testScanFrameworkCaches()
    {
        $scanner = new Scanner();
        $data    = $scanner->scanFrameworkCaches();
        assertIsArray($data);
    }
}
