<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\I18n\Model;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;

class I18nTest extends TestCore
{
    public function testGetLocalByCode()
    {
        /**@var I18n $i18n */
        $i18n = ObjectManager::getInstance(I18n::class);
//        p($i18n->getLocalByCode('zh_Hans_CN'));
        p($i18n->getLocalesWithFlags());
    }
}
