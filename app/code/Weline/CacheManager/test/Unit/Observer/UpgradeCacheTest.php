<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\CacheManager\Observer;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;

class UpgradeCacheTest extends TestCore
{
    public function testExecute()
    {
        $event = ObjectManager::getInstance('Weline\Framework\Event\Event');
        $event->setData([]);
        $cache = ObjectManager::getInstance('Weline\CacheManager\Observer\UpgradeCache');
        $cache->execute($event);
        $this->assertTrue(true);
    }
}
