<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\System\File;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;

class CompressTest extends TestCore
{
    public function testAddStringIsFluent(): void
    {
        /**@var $compress Compress */
        $compress = ObjectManager::getInstance(Compress::class);

        self::assertSame($compress, $compress->addString('fixture.txt', 'fixture'));
        self::assertInstanceOf(\ZipArchive::class, $compress->getDriver());
    }
}
