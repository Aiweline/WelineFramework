<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database\test\Model;

use Weline\Framework\Database\Model\Reader;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;

class ReaderTest extends TestCore
{
    private Reader $model;

    public function setUp(): void
    {
        $this->model = ObjectManager::getInstance(Reader::class);
    }

    public function testReader()
    {
        $result = $this->model->getFileList();
        self::assertIsArray($result);
    }
}
