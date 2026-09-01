<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;

final class HeaderNavFragmentBypassTest extends TestCase
{
    public function testThemePreviewContentAllowsEditorModeFragmentCache(): void
    {
        $source = \file_get_contents(
            \dirname(__DIR__, 3) . '/Helper/HeaderNavFragment.php'
        );
        self::assertIsString($source);
        self::assertStringContainsString('theme/frontend/theme-preview/content', $source);
        self::assertStringContainsString('if (!$isThemePreviewContent)', $source);
    }
}
