<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\ModuleRouter\Config;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;

use function p;

class ModuleRouterReaderTest extends TestCase
{
    public function testRead()
    {
        /**@var ModuleRouterReader $obj */
        $obj = ObjectManager::getInstance(ModuleRouterReader::class);
        p($obj->read());
    }
}
