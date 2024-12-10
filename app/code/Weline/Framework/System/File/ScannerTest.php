<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\System\File;

use PHPUnit\Framework\TestCase;

class ScannerTest extends TestCase
{
    public function testScanAppVendors()
    {
        $scanner = new Scanner();
        $scanner->scanAppVendors();
        $this->assertTrue(true);
    }

    //scanVendorModulesWithFiles
    public function testScanVendorModulesWithFiles()
    {
        $scanner = new Scanner();
        $scanner->scanVendorModulesWithFiles();
        $this->assertTrue(true);
    }
}
