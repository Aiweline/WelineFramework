<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Theme no longer registers storefront you-may-like; Product owns it.
 */
final class YouMayLikeDefaultInjectionContractTest extends TestCase
{
    public function testThemeNoLongerRegistersYouMayLikeWidgetPath(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Theme/widget.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringNotContainsString(
            'widgets/product/you-may-like/default.phtml',
            $source,
            'Theme must not register you-may-like; Product owns storefront default injection.',
        );

        $legacyTemplate = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/product/you-may-like/default.phtml';
        self::assertFileDoesNotExist(
            $legacyTemplate,
            'Theme demo you-may-like template must be removed so file catalog cannot re-register it.',
        );
    }
}
