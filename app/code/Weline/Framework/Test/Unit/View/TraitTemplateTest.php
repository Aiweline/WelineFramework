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
    public function testProcessModuleSourceFilePath(): void
    {
        /**@var Template $ob */
        $ob   = self::getInstance(Template::class);
        $data = $ob->processModuleSourceFilePath('hooks', 'Weline_DeveloperWorkspace::hooks/title.phtml');

        self::assertSame(
            ['Weline_DeveloperWorkspace::hooks/title.phtml', 'Weline_DeveloperWorkspace'],
            $data
        );
    }

    public function testFetchTagSource(): void
    {
        /**@var Template $ob */
        $ob   = self::getInstance(Template::class);
        $data = $ob->fetchTagSource('statics', 'Weline_Framework::css/test.css', false);

        self::assertIsString($data);
        self::assertStringEndsWith('/css/test.css', $data);
    }
}
