<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Event\test;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;

use function PHPUnit\Framework\assertIsArray;

class EventsManagerTest extends TestCore
{
    /**
     * @var mixed|EventsManager|ObjectManager
     */
    private EventsManager $eventsManager;

    public function setUp(): void
    {
        $this->eventsManager = ObjectManager::getInstance(EventsManager::class);
    }

    public function testScanEvents()
    {
        assertIsArray($this->eventsManager->scanEvents(), 'Framework_Event::扫描事件配置成功！');
    }
}
