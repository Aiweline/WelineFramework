<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\I18n\Test\Unit\Config;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\I18n\Config\Reader;

use function PHPUnit\Framework\assertIsArray;

class ReaderTest extends TestCore
{
    public function testGetAllI18ns()
    {
        /**@var Reader $i18nReader */
        $i18nReader = ObjectManager::getInstance(Reader::class);
        assertIsArray($i18nReader->getAllI18ns(), __('Weline_I18n:语言包读取'));
    }
}
