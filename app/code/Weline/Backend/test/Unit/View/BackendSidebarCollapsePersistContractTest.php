<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class BackendSidebarCollapsePersistContractTest extends TestCase
{
    public function testAdminAppPersistsVerticalMenuCollapseToThemeConfig(): void
    {
        $appJs = \file_get_contents(BP . '/app/code/Weline/Admin/view/statics/assets/js/app.js');
        self::assertIsString($appJs);
        self::assertStringContainsString('function persistVerticalMenuCollapsedState()', $appJs);
        self::assertStringContainsString("window.setThemeConfig({", $appJs);
        self::assertStringContainsString("'class': collapsed ? 'vertical-collpsed' : ''", $appJs);
        self::assertStringContainsString("'data-keep-enlarged': collapsed ? 'true' : ''", $appJs);
        self::assertStringContainsString('persistVerticalMenuCollapsedState();', $appJs);
        self::assertStringContainsString('}, false);', $appJs);

        $sidebar = \file_get_contents(BP . '/app/code/Weline/Admin/view/templates/common/left-sidebar.phtml');
        self::assertIsString($sidebar);
        self::assertStringContainsString('function persistVerticalMenuCollapsedState()', $sidebar);
        self::assertStringContainsString('Remember sidebar collapse/expand in the current user', $sidebar);
        self::assertStringContainsString('persistVerticalMenuCollapsedState();', $sidebar);

        // Real clicks are handled by the capture-phase topbar listener (stopImmediatePropagation).
        $topBar = \file_get_contents(BP . '/app/code/Weline/Admin/view/blocks/backend/public/top-bar.phtml');
        self::assertIsString($topBar);
        self::assertStringContainsString("window.setThemeConfig({", $topBar);
        self::assertStringContainsString("'class': collapsed ? 'vertical-collpsed' : ''", $topBar);
        self::assertStringContainsString("'data-keep-enlarged': collapsed ? 'true' : ''", $topBar);
        self::assertStringContainsString('}, false);', $topBar);

        $topBarAlt = \file_get_contents(BP . '/app/code/Weline/Admin/view/blocks/backend/public/topbar.phtml');
        self::assertIsString($topBarAlt);
        self::assertStringContainsString("window.setThemeConfig({", $topBarAlt);
        self::assertStringContainsString("'class': collapsed ? 'vertical-collpsed' : ''", $topBarAlt);
        self::assertStringContainsString("'data-keep-enlarged': collapsed ? 'true' : ''", $topBarAlt);
        self::assertStringContainsString('}, false);', $topBarAlt);
    }
}
