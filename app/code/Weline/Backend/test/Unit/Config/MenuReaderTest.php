<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Backend\Config;

use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;

class MenuReaderTest extends TestCore
{
    /**
     * @throws \ReflectionException
     * @throws Exception
     */
    public function test__construct()
    {
        $menu_xml = ObjectManager::getInstance(MenuXmlReader::class)->read();
        self::assertIsArray($menu_xml,'扫描XML菜单文件');
    }
}
