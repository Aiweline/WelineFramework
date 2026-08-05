<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class BackendCompactLayoutContractTest extends TestCase
{
    public function testBackendThemeCssCompactsSmallScreenGutters(): void
    {
        $root = dirname(__DIR__, 6);
        $themeCss = $root . '/app/code/Weline/Theme/view/theme/backend/assets/css/theme.css';
        $layoutsScss = $root . '/app/code/Weline/Admin/view/statics/assets/scss/custom/structure/_layouts.scss';

        self::assertFileExists($themeCss);
        self::assertFileExists($layoutsScss);

        $theme = (string)file_get_contents($themeCss);
        $layouts = (string)file_get_contents($layoutsScss);

        self::assertStringContainsString('@media (max-width: 991.98px)', $theme);
        self::assertStringContainsString('margin-left: 0 !important;', $theme);
        self::assertStringContainsString('margin-right: 0 !important;', $theme);
        self::assertStringContainsString('body[data-layout-size="boxed"] #layout-wrapper,', $theme);
        self::assertStringContainsString('max-width: 100%;', $theme);
        self::assertStringContainsString('padding-left: 0.5rem;', $theme);
        self::assertStringContainsString('padding-right: 0.5rem;', $theme);
        self::assertStringContainsString('@media (max-width: 575.98px)', $theme);
        self::assertStringContainsString('padding-left: 0.25rem;', $theme);
        self::assertStringContainsString('padding-right: 0.25rem;', $theme);

        self::assertStringContainsString('@media (max-width: 991.98px)', $layouts);
        self::assertStringContainsString('body[data-layout-size="boxed"]', $layouts);
        self::assertStringContainsString('max-width: 100%;', $layouts);
    }
}
