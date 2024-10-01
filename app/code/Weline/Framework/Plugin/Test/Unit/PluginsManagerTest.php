<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Plugin\Test\Unit;

use Weline\Framework\Plugin\PluginsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;

class PluginsManagerTest extends TestCore
{
    /**
     * @var mixed|PluginsManager|ObjectManager
     */
    private PluginsManager $pluginsManager;

    public function setUp(): void
    {
        $this->pluginsManager = ObjectManager::getInstance(PluginsManager::class);
    }

    public function testScanPlugins()
    {
//        p($this->pluginsManager->scanPlugins());
//        p($this->pluginsManager->getPluginInstanceList('Aiweline\Index\Controller\Index'));
//        p($this->pluginsManager->generatorInterceptor('Aiweline\Index\Controller\Index'));
//        p($this->pluginsManager->generatorInterceptor());
        self::assertIsObject($this->pluginsManager->generatorInterceptor(), 'Framework_Plugin::测试生成插件器。');
    }
}
