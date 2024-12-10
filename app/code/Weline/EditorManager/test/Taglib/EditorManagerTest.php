<?php

namespace Weline\EditorManager\test\Taglib;

use Weline\EditorManager\Taglib\EditorManager;
use Weline\EditorManager\test\NoReturn;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;

class EditorManagerTest extends TestCore
{
    private ?EditorManager $fileManager = null;

    public function setUp(): void
    {
        $this->fileManager = ObjectManager::getInstance(EditorManager::class);
    }

    public function testCallback()
    {
        $result = $this->fileManager::callback();
        self::assertIsCallable($result);
    }
}
