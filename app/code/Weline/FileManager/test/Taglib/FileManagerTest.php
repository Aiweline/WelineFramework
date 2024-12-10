<?php

namespace Weline\FileManager\test\Taglib;

use Weline\FileManager\Taglib\FileManager;
use Weline\FileManager\test\NoReturn;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;

class FileManagerTest extends TestCore
{
    private ?FileManager $fileManager = null;

    public function setUp(): void
    {
        $this->fileManager = ObjectManager::getInstance(FileManager::class);
    }

    public function testCallback()
    {
        $result = $this->fileManager::callback();
        $result = $result('file-manager', [], [], []);
        self::assertIsString($result);
    }
}
