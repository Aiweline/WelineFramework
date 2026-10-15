<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\ThemeHeadChromeJsPack;

final class ThemeHeadChromeJsPackContractTest extends TestCase
{
    public function testJsPackGatesOnThemeJsMergeAndExcludesModuleUi(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/ThemeHeadChromeJsPack.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('theme_js_merge', $src);
        self::assertStringContainsString('PACK_EARLY', $src);
        self::assertStringContainsString('PACK_AFTER_CSS', $src);
        self::assertStringContainsString('weline-theme-prepaint.js', $src);
        self::assertStringContainsString('weline-css-ready.js', $src);
        self::assertStringNotContainsString('ui/weline-ui.js', $src);
        self::assertStringContainsString('type=module', $src);
        self::assertSame('early', ThemeHeadChromeJsPack::PACK_EARLY);
        self::assertSame('after-css', ThemeHeadChromeJsPack::PACK_AFTER_CSS);
    }

    public function testDefaultThemeFrontendHeadsAreCssOnlyJsViaModules(): void
    {
        foreach ([
            'view/theme/frontend/partials/head/assets-prefix.phtml',
            'view/theme/frontend/partials/head/assets-suffix.phtml',
            'view/theme/frontend/partials/head/default.phtml',
            'view/theme/frontend/partials/head/minimal.phtml',
        ] as $relative) {
            $src = (string)file_get_contents(dirname(__DIR__, 3) . '/' . $relative);
            self::assertStringNotContainsString('ThemeHeadChromeJsPack', $src, $relative);
            self::assertStringNotContainsString('weline-theme-prepaint.js', $src, $relative);
            self::assertStringNotContainsString('weline-css-ready.js', $src, $relative);
            self::assertStringNotContainsString('<script', $src, $relative);
        }

        $backend = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/backend/partials/head/default.phtml'
        );
        // Backend still owns head chrome JS (out of this storefront Amazon wave).
        self::assertStringContainsString('weline-theme-prepaint.js', $backend);
        self::assertStringContainsString('weline-ui.js', $backend);
    }
}
