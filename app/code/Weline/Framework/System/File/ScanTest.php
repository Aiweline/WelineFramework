<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\System\File;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;

class ScanTest extends TestCore
{
    public function testScanDirTree()
    {
        /**@var Scanner $scanner */
        $scanner = ObjectManager::getInstance(Scanner::class);
        p($scanner->scan('E:\WelineFramework\app\code\/Weline/Admin/'));
        p($scanner->scanDirTree('E:\WelineFramework\app\code\/Weline/Admin/', 12));
    }
}
