<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit\View;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

final class BackendSidebarCollapsePersistContractTest extends TestCase
{
    public function testBackendShellUsesNativeWelineDrawerContract(): void
    {
        $sidebar = \file_get_contents(BP . '/app/code/Weline/Admin/view/templates/common/left-sidebar.phtml');
        self::assertIsString($sidebar);
        self::assertStringContainsString('id="w-backend-sidebar"', $sidebar);
        self::assertStringContainsString('data-w-component="drawer"', $sidebar);
        self::assertStringContainsString('data-w-keep-mounted="true"', $sidebar);
        self::assertStringNotContainsString('data-simplebar', $sidebar);
        self::assertStringNotContainsString('metismenu', $sidebar);

        $topBar = \file_get_contents(BP . '/app/code/Weline/Admin/view/blocks/backend/public/top-bar.phtml');
        self::assertIsString($topBar);
        self::assertStringContainsString('data-w-action="drawer.open"', $topBar);
        self::assertStringContainsString('data-w-target="#w-backend-sidebar"', $topBar);
        self::assertStringNotContainsString('data-bs-', $topBar);
        self::assertStringNotContainsString('<script>', $topBar);
    }

    public function testBackendSidebarCollapseUsesShellComponentAndPersistedToggle(): void
    {
        $layout = \file_get_contents(BP . '/app/code/Weline/Theme/view/theme/backend/layouts/default/default.phtml');
        self::assertIsString($layout);
        self::assertStringContainsString('data-w-component="backend-sidebar-collapse"', $layout);

        $sidebar = \file_get_contents(BP . '/app/code/Weline/Admin/view/templates/common/left-sidebar.phtml');
        self::assertIsString($sidebar);
        self::assertStringContainsString('w-backend-sidebar-collapse', $sidebar);
        self::assertStringContainsString('data-w-action="backend-sidebar-collapse.toggle"', $sidebar);
        self::assertStringContainsString('data-w-label-expanded', $sidebar);
        self::assertStringContainsString('data-w-label-collapsed', $sidebar);

        $runtime = \file_get_contents(BP . '/app/code/Weline/Theme/view/ui/js/weline-ui.js');
        self::assertIsString($runtime);
        self::assertStringContainsString("define('backend-sidebar-collapse'", $runtime);
        self::assertStringContainsString('weline_backend_sidebar_collapsed', $runtime);
        self::assertStringContainsString('backend-sidebar-collapse.toggle', $runtime);

        $prepaint = \file_get_contents(BP . '/app/code/Weline/Theme/view/ui/js/theme-prepaint.js');
        self::assertIsString($prepaint);
        self::assertStringContainsString('weline_backend_sidebar_collapsed', $prepaint);
        self::assertStringContainsString('backendSidebarCollapsed', $prepaint);

        $backendCss = \file_get_contents(BP . '/app/code/Weline/Theme/view/ui/css/backend.css');
        self::assertIsString($backendCss);
        self::assertStringContainsString('--backend-theme-sidebar-collapsed-width', $backendCss);
        self::assertStringContainsString('[data-sidebar-collapsed="true"]', $backendCss);
        self::assertStringNotContainsString('.w-backend-nav__search:hover', $backendCss);
        self::assertStringNotContainsString('[data-sidebar-overlay="true"]', $backendCss);
        self::assertStringNotContainsString('.w-backend-nav__entry:hover', $backendCss);
        self::assertStringContainsString('expandSidebarForSearch', $runtime);
        self::assertStringContainsString('expandSidebarOverlay', $runtime);
        self::assertStringContainsString('expandTopLevelEntry', $runtime);
        self::assertStringContainsString('expandOverlay', $runtime);
        self::assertStringContainsString('dismissOverlay', $runtime);
        self::assertStringContainsString('overlayActive', $runtime);
        self::assertStringContainsString('const expand = () =>', $runtime);
        self::assertStringContainsString('const collapse = () =>', $runtime);
        self::assertStringContainsString("listen(document, 'pointerdown'", $runtime);
        self::assertStringContainsString('!overlayActive', $runtime);
        self::assertStringContainsString("details.w-backend-nav__disclosure[open]", $runtime);
        self::assertStringContainsString('Open means open', $runtime);
        // Outside click must not auto-collapse a permanently expanded sidebar.
        self::assertDoesNotMatchRegularExpression(
            "/listen\(document, 'pointerdown'[\s\S]*?collapse\(\);/",
            $runtime
        );

        $menuService = \file_get_contents(BP . '/app/code/Weline/Admin/Service/MenuRenderService.php');
        self::assertIsString($menuService);
        self::assertStringContainsString('resolveMenuIconName', $menuService);
        self::assertStringContainsString('LegacyIconNameMap', $menuService);
        self::assertStringContainsString('w-backend-nav__entry--group', $menuService);
    }
}
