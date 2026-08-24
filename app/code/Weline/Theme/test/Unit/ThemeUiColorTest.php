<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\ThemeUiColor;

class ThemeUiColorTest extends TestCase
{
    public function testAcceptsThemeVar(): void
    {
        self::assertTrue(ThemeUiColor::isValid('var(--weline-theme-primary)'));
        self::assertTrue(ThemeUiColor::isValid('var(--weline-theme-danger-surface, #fdecef)'));
    }

    public function testSanitizeFallsBack(): void
    {
        self::assertSame(
            'var(--weline-theme-primary)',
            ThemeUiColor::sanitize('var(--weline-theme-primary)', '#000')
        );
        self::assertSame(
            '#000',
            ThemeUiColor::sanitize('not-a-color', '#000')
        );
    }
}
