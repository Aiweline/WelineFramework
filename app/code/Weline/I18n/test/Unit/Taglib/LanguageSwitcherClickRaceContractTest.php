<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;

/**
 * LIVE/path clicks must not preventDefault+assign (Chromium blank docs).
 * Emit/preview mode still cancels the anchor and broadcasts the locale.
 */
final class LanguageSwitcherClickRaceContractTest extends TestCase
{
    public function testTaglibPathModeUsesNativeAuthoritativeHrefNavigation(): void
    {
        $path = dirname(__DIR__, 3) . '/Taglib/LanguageSwitcher.php';
        self::assertFileExists($path);
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('|markup=v2-lang-native-path-nav-9', $content);
        self::assertStringContainsString('if(navigation==="emit"){', $content);
        self::assertStringContainsString('writeLanguagePreference(code)', $content);
        self::assertStringContainsString('// Native navigation — do not preventDefault.', $content);
        self::assertStringContainsString(
            'preventDefault + location.assign races',
            $content
        );
        // Fallback JS navigation only for missing href / same-path reload.
        self::assertStringContainsString('i18n.switchLang(code,href)', $content);
        self::assertStringNotContainsString(
            'window.setTimeout(function(){window.location.assign(href);},0);',
            $content
        );
    }

    public function testRequestEntryStaysPinnedInLongLists(): void
    {
        $path = dirname(__DIR__, 3) . '/Taglib/LanguageSwitcher.php';
        $content = (string) file_get_contents($path);
        self::assertStringContainsString('overflow:hidden;flex-direction:column', $content);
        self::assertStringContainsString('weline-language-request-entry{flex:0 0 auto', $content);
        self::assertStringContainsString('position:sticky;bottom:0', $content);
        self::assertStringContainsString('display","flex","important"', $content);
    }
}
