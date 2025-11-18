<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace I18n\Config;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\I18n\Config\Reader;

class ReaderTest extends TestCore
{
    public function testGetAllI18ns()
    {
        /**@var Reader $reader */
        $reader = ObjectManager::getInstance(Reader::class);
        $i18ns  = $reader->getAllI18ns();
        $this->assertTrue(is_array($i18ns));
    }
}
