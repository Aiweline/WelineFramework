<?php

declare(strict_types=1);

namespace Weline\Admin\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class BackendTopbarUniversalSearchContractTest extends TestCase
{
    public function testTopbarHostsBackendUniversalSearchAndSidebarIsMenuOnly(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $topBar = $moduleRoot . '/view/blocks/backend/public/top-bar.phtml';
        $sidebar = $moduleRoot . '/view/templates/common/left-sidebar.phtml';
        $hook = $moduleRoot . '/hook.php';

        self::assertFileExists($topBar);
        self::assertFileExists($sidebar);
        self::assertFileExists($hook);

        $topBarSrc = (string)\file_get_contents($topBar);
        $sidebarSrc = (string)\file_get_contents($sidebar);
        $hookSrc = (string)\file_get_contents($hook);

        self::assertStringContainsString('w-backend-topbar__center', $topBarSrc);
        self::assertStringContainsString('area="backend"', $topBarSrc);
        self::assertStringContainsString('navigate-hits="true"', $topBarSrc);
        self::assertStringContainsString('w-backend-universal-search', $topBarSrc);
        self::assertStringContainsString('header-search.js', $topBarSrc);

        self::assertStringContainsString('搜索菜单…', $sidebarSrc);
        self::assertStringContainsString('没有匹配的菜单', $sidebarSrc);
        self::assertStringNotContainsString('nav-search-extras', $sidebarSrc);
        self::assertStringNotContainsString('搜索菜单或配置', $sidebarSrc);
        self::assertStringNotContainsString('nav-search-extras', $hookSrc);
    }
}
