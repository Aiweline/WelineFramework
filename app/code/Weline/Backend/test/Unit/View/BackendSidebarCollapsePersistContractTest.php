<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit\View;

use PHPUnit\Framework\TestCase;

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
}
