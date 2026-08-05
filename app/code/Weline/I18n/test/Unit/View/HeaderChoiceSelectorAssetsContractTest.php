<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class HeaderChoiceSelectorAssetsContractTest extends TestCase
{
    public function testLanguageOptionClickWritesServerCookieAndReloadsSamePath(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Frontend/header-choice-selector-assets.phtml';
        self::assertFileExists($path);
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('writeLanguagePreference', $content);
        self::assertStringContainsString('WELINE_USER_LANG=', $content);
        self::assertStringContainsString('SameSite=Lax', $content);
        self::assertStringContainsString('samePath', $content);
        self::assertStringContainsString('window.location.reload()', $content);
        self::assertStringContainsString('z-index: 10050', $content);
        self::assertStringContainsString('overflow: visible', $content);
        self::assertStringContainsString('weline-choice-open', $content);
        self::assertStringContainsString('z-index: 10060', $content);
        self::assertStringContainsString('[WelineChoice]', $content);
        self::assertStringContainsString('language-option-click', $content);
        self::assertStringContainsString('hoverBridge: true', $content);
        self::assertStringContainsString('margin-top: 0', $content);
        self::assertStringContainsString('.weline-choice-switcher::after', $content);
    }
}
