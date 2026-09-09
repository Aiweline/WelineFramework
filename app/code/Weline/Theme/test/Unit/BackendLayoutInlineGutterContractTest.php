<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class BackendLayoutInlineGutterContractTest extends TestCase
{
    public function testBackendCssExposesSharedInlineGutterToken(): void
    {
        $root = dirname(__DIR__, 6);
        $backendCss = $root . '/app/code/Weline/Theme/view/ui/css/backend.css';
        $themeCss = $root . '/app/code/Weline/Theme/view/theme/backend/assets/css/theme.css';

        self::assertFileExists($backendCss);
        self::assertFileExists($themeCss);

        $backend = (string)file_get_contents($backendCss);
        self::assertStringContainsString('--backend-layout-inline:', $backend);
        self::assertStringContainsString('--backend-layout-block:', $backend);
        self::assertStringContainsString('@layer tokens', $backend);
        self::assertStringContainsString('.w-backend-topbar { grid-area: topbar;', $backend);
        self::assertStringContainsString('padding-inline: var(--backend-layout-inline', $backend);
        self::assertStringContainsString('.w-backend-main { grid-area: main;', $backend);
        self::assertStringContainsString('padding-block: var(--backend-layout-block', $backend);

        $theme = (string)file_get_contents($themeCss);
        self::assertStringContainsString('--backend-layout-inline:', $theme);
        self::assertStringContainsString('padding-inline: var(--backend-layout-inline)', $theme);
        self::assertStringContainsString('#page-topbar .navbar-header', $theme);
        self::assertStringNotContainsString('padding-left: 0.25rem;', $theme);
    }
}
