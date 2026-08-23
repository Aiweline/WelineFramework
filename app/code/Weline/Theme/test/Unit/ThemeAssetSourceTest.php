<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Taglib\ThemeAssetSource;

class ThemeAssetSourceTest extends TestCase
{
    public function testNormalizeDefaultsToWelineThemeModule(): void
    {
        $this->assertSame(
            'Weline_Theme::frontend/assets/css/theme.css',
            ThemeAssetSource::normalize('frontend/assets/css/theme.css')
        );
    }

    public function testNormalizeKeepsExplicitModulePath(): void
    {
        $this->assertSame(
            'Weline_Other::frontend/assets/js/custom.js',
            ThemeAssetSource::normalize('Weline_Other::frontend/assets/js/custom.js')
        );
    }

    public function testNormalizeKeepsOptionalThemePrefix(): void
    {
        $this->assertSame(
            'Weline_Theme::theme/frontend/assets/css/theme.css',
            ThemeAssetSource::normalize('Weline_Theme::theme/frontend/assets/css/theme.css')
        );
    }
}
