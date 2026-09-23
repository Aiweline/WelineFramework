<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;

final class HeaderNavFragmentBypassTest extends TestCase
{
    public function testEditorModeBypassesFragmentCacheOnStorefrontCanvas(): void
    {
        $source = \file_get_contents(
            \dirname(__DIR__, 3) . '/Helper/HeaderNavFragment.php'
        );
        self::assertIsString($source);
        self::assertStringContainsString("getParam('editor_mode'", $source);
        self::assertStringNotContainsString('theme/frontend/theme-preview/content', $source);
        self::assertStringNotContainsString('$isThemePreviewContent', $source);
    }

    public function testNavigationFragmentsExposeCacheAndRenderTimingPhases(): void
    {
        $source = \file_get_contents(
            \dirname(__DIR__, 3) . '/Helper/HeaderNavFragment.php'
        );
        self::assertIsString($source);
        self::assertStringContainsString("'theme.header.mega_panel.cache'", $source);
        self::assertStringContainsString("'theme.header.mega_panel.render'", $source);
        self::assertStringContainsString("'theme.header.sidebar.cache'", $source);
        self::assertStringContainsString("'theme.header.horizontal.cache'", $source);
        self::assertStringContainsString("'theme.header.horizontal.render'", $source);
        self::assertStringContainsString('rememberCategoriesHorizontalNav', $source);
    }
}
