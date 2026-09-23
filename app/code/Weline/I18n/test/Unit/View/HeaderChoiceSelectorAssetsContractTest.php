<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class HeaderChoiceSelectorAssetsContractTest extends TestCase
{
    public function testHeaderChoiceAssetsDeclareI18nAndCurrencyModules(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Frontend/header-choice-selector-assets.phtml';
        self::assertFileExists($path);
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('data-weline-load="i18n,currency"', $content);
        self::assertStringNotContainsString('weline-choice-selector.css', $content);
        self::assertStringNotContainsString('weline-choice-selector.js', $content);
        self::assertStringNotContainsString('@static(Weline_Theme::ui/components/weline-choice-selector.js)', $content);
        self::assertStringNotContainsString('@static(Weline_Currency::js/currency.js)', $content);
        self::assertStringNotContainsString('@static(Weline_I18n::js/i18n.js)', $content);
        self::assertStringNotContainsString('WELINE_USER_LANG=', $content);
        self::assertStringNotContainsString('document.cookie', $content);
        self::assertStringNotContainsString('writeLanguagePreference', $content);
    }

    public function testChoiceFilterRegistersIdempotentlyAndExportsRegister(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/js/header-choice-selector.js';
        self::assertFileExists($path);
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('export function register(UI)', $content);
        self::assertStringContainsString("UI.define('choice-filter'", $content);
        self::assertStringContainsString('already (defined|registered)', $content);
        self::assertStringNotContainsString('if (window.Weline?.UI) register', $content);
    }

    public function testCurrencySwitcherDedupesAssetsWithRequestContext(): void
    {
        $path = dirname(__DIR__, 3) . '/view/hooks/header-currency-switcher.phtml';
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('view.i18n.header_choice_assets_rendered', $content);
        self::assertStringContainsString('RequestContext::get($assetsFlagKey)', $content);
        self::assertStringContainsString('RequestContext::set($assetsFlagKey, true)', $content);
        self::assertStringContainsString('data-weline-load="currency"', $content);
        self::assertStringNotContainsString('$GLOBALS[$assetsFlagKey]', $content);
        self::assertStringNotContainsString('$this->getData($assetsFlagKey)', $content);
    }
}
