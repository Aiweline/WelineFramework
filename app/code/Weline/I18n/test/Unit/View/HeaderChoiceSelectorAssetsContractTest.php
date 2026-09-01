<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class HeaderChoiceSelectorAssetsContractTest extends TestCase
{
    public function testPointerCanCrossFromChoiceTriggerIntoDropdownPanel(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Frontend/header-choice-selector-assets.phtml';
        self::assertFileExists($path);
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('weline-choice-selector.css', $content);
        self::assertStringContainsString('weline-choice-selector.js', $content);
        self::assertStringContainsString('Weline_Currency::js/currency.js', $content);
    }

    public function testHeaderChoiceAssetsPreferSharedSwitcherScriptsWithoutLangCookieWrite(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Frontend/header-choice-selector-assets.phtml';
        self::assertFileExists($path);
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('@static(Weline_Theme::ui/components/weline-choice-selector.js)', $content);
        self::assertStringContainsString('@static(Weline_Currency::js/currency.js)', $content);
        self::assertStringNotContainsString('WELINE_USER_LANG=', $content);
        self::assertStringNotContainsString('document.cookie', $content);
        self::assertStringNotContainsString('writeLanguagePreference', $content);
    }
}
