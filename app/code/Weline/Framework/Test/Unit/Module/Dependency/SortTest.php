<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Module\Dependency;

use \Weline\Framework\Test\TestCore;

class SortTest extends TestCore
{
    public function testDependenciesSort(): void
    {
        $aiModule = require dirname(__DIR__, 5) . '/Ai/etc/module.php';
        $requires = array_keys((array)($aiModule['requires'] ?? []));
        self::assertContains('Weline_Admin', $requires, 'Weline_Ai must declare Weline_Admin as a required module.');

        /** @var Sort $sort */
        $sort = $this->getInstance(Sort::class);
        $modules = [
            'Weline_Ai' => ['id' => 'Weline_Ai', 'parents' => $requires],
            'Weline_Framework' => ['id' => 'Weline_Framework', 'parents' => []],
            'Weline_Backend' => ['id' => 'Weline_Backend', 'parents' => ['Weline_Framework']],
            'Weline_Admin' => ['id' => 'Weline_Admin', 'parents' => ['Weline_Backend']],
        ];

        $sorted = $sort->dependenciesSort($modules, 'id', 'parents');
        $moduleIds = array_keys($sorted);
        $adminIndex = array_search('Weline_Admin', $moduleIds, true);
        $aiIndex = array_search('Weline_Ai', $moduleIds, true);

        self::assertIsInt($adminIndex);
        self::assertIsInt($aiIndex);
        self::assertTrue(
            $adminIndex < $aiIndex,
            'Dependency sorting must place Weline_Admin before Weline_Ai even when Ai is scanned first.'
        );
    }
}
