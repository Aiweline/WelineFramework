<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class BackendCompactLayoutContractTest extends TestCase
{
    public function testBackendThemeCssCompactsSmallScreenGutters(): void
    {
        $root = dirname(__DIR__, 6);
        $themeCss = $root . '/app/code/Weline/Theme/view/ui/css/backend.css';

        self::assertFileExists($themeCss);

        $theme = (string)file_get_contents($themeCss);
        self::assertStringContainsString('@media (max-width: 63.99rem)', $theme);
        self::assertStringContainsString('grid-template-columns: minmax(0, 1fr)', $theme);
        self::assertStringContainsString('.w-backend-sidebar { position: fixed;', $theme);
        self::assertStringContainsString('.w-backend-main { padding: var(--weline-space-4); }', $theme);
        self::assertStringContainsString('@media (max-width: 47.99rem)', $theme);
        self::assertStringContainsString('.w-backend-topbar { padding-inline: var(--weline-space-3); }', $theme);
    }
}
