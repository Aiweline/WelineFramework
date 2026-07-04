<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\View;

use Weline\Framework\UnitTest\TestCore;

class TraitTemplateTest extends TestCore
{
    public function testProcessModuleSourceFilePath()
    {
        /**@var Template $ob */
        $ob   = self::getInstance(Template::class);
        $data = $ob->processModuleSourceFilePath('hooks', 'Weline_DeveloperWorkspace::hooks/title.phtml');
        p($data);
    }

    public function testFetchTagSource()
    {
        /**@var Template $ob */
        $ob   = self::getInstance(Template::class);
        $data = $ob->fetchTagSource('hooks', 'Weline_DeveloperWorkspace::hooks/title.phtml');
        p($data);
    }
}
