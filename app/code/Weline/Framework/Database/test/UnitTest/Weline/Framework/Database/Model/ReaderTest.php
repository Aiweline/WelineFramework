<?php
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database\Model;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;

class ReaderTest extends TestCore
{
    private Reader $reader;

    public function setUp(): void
    {
        $this->reader = ObjectManager::getInstance(Reader::class);
    }

    public function testRead()
    {
        $this->reader->read();
    }
}
