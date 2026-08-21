<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;

/**
 * LIVE/path clicks must not preventDefault+assign (Chromium blank docs).
 * Emit/preview mode still cancels the anchor and broadcasts the locale.
 * Theme UI owns open/close; switcher injects i18n.js + preference/same-path handlers.
 */
final class LanguageSwitcherClickRaceContractTest extends TestCase
{
    public function testTaglibPathModeUsesNativeAuthoritativeHrefNavigation(): void
    {
        $path = dirname(__DIR__, 3) . '/Taglib/LanguageSwitcher.php';
        self::assertFileExists($path);
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('|markup=weline-ui-2-lang-native-path-nav-11', $content);
        self::assertStringContainsString('if(navigation==="emit"){', $content);
        self::assertStringContainsString('writeLanguagePreference(code)', $content);
        self::assertStringContainsString('// Native navigation — do not preventDefault.', $content);
        self::assertStringContainsString(
            'preventDefault + location.assign races',
            $content
        );
        // Fallback JS navigation only for missing href / same-path reload.
        self::assertStringContainsString('i18n.switchLang(code,href)', $content);
        self::assertStringContainsString('data-weline-i18n-runtime="1"', $content);
        self::assertStringContainsString('/Weline/I18n/view/statics/js/i18n.js?v=', $content);
        self::assertStringNotContainsString(
            'window.setTimeout(function(){window.location.assign(href);},0);',
            $content
        );
    }

    public function testThemeUiMenuOwnsOpenCloseWithoutLegacyPortalScripts(): void
    {
        $path = dirname(__DIR__, 3) . '/Taglib/LanguageSwitcher.php';
        $content = (string) file_get_contents($path);
        self::assertStringContainsString('data-w-component="menu"', $content);
        self::assertStringContainsString('data-w-menu-trigger', $content);
        self::assertStringContainsString('data-w-menu-panel', $content);
        self::assertStringContainsString('[data-language-option]', $content);
        self::assertStringNotContainsString('mountPanelToBody', $content);
        self::assertStringNotContainsString('weline-i18n-switcher-panel', $content);
    }

    public function testLiveHttpRequestBeatsStaleThemeDataBackendArea(): void
    {
        $path = dirname(__DIR__, 3) . '/Taglib/LanguageSwitcher.php';
        $content = (string) file_get_contents($path);
        self::assertStringContainsString(
            'Live HTTP request is authoritative',
            $content
        );
        self::assertStringContainsString(
            'ThemeData area can remain',
            $content
        );
    }
}
