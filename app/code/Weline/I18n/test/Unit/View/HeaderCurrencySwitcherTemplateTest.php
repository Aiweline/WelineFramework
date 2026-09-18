<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class HeaderCurrencySwitcherTemplateTest extends TestCase
{
    public function testCurrencyOptionDoesNotRepeatSymbolInMetaText(): void
    {
        $path = dirname(__DIR__, 3) . '/view/hooks/header-currency-switcher.phtml';

        self::assertFileExists($path);
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('<span class="w-currency-switcher__symbol"', $content);
        self::assertStringNotContainsString('<span class="weline-choice-symbol"', $content);

        preg_match('/<span class="w-currency-switcher__copy">(?P<copy>.*?)<\/span>/s', $content, $matches);
        self::assertArrayHasKey('copy', $matches, 'Currency switcher should render a copy block.');
        self::assertStringContainsString('$currencyName', $matches['copy']);
        self::assertStringContainsString('$currencyCode', $matches['copy']);
        self::assertStringNotContainsString('$currencySymbol', $matches['copy']);
        self::assertStringNotContainsString('·', $matches['copy']);
    }

    public function testCurrencyPanelUsesPerRenderInstanceId(): void
    {
        $path = dirname(__DIR__, 3) . '/view/hooks/header-currency-switcher.phtml';
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('SwitcherInstanceId::create(', $content);
        self::assertStringNotContainsString('RequestContext::get(', $content);
        self::assertStringContainsString('$currencySwitcherId', $content);
        self::assertStringContainsString('aria-controls="<?= $escape($currencySwitcherId) ?>"', $content);
        self::assertStringContainsString('id="<?= $escape($currencySwitcherId) ?>"', $content);
        self::assertStringNotContainsString('aria-controls="w-currency-switcher-menu"', $content);
        self::assertStringNotContainsString('id="w-currency-switcher-menu"', $content);
    }

    public function testCurrencySwitcherSkipsNonConvertibleRates(): void
    {
        $path = dirname(__DIR__, 3) . '/view/hooks/header-currency-switcher.phtml';
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('$currencyIsConvertible', $content);
        self::assertStringContainsString('rate<=0', $content);
        self::assertStringContainsString('getBaseCurrency()', $content);
        self::assertStringContainsString('!$currencyIsConvertible($currencyCode, $rate)', $content);
    }

    public function testCurrencyTriggerPrefersSymbolGlyph(): void
    {
        $path = dirname(__DIR__, 3) . '/view/hooks/header-currency-switcher.phtml';
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('$triggerCurrencyLabel', $content);
        self::assertStringContainsString('CurrencySymbol::forCode', $content);
        self::assertStringContainsString('data-currency-symbol=', $content);
        self::assertStringContainsString('w-currency-switcher__code', $content);
        self::assertStringNotContainsString(
            '<span class="w-currency-switcher__current current-currency"><?= $escape($displayCurrentCurrency) ?></span>',
            $content
        );
    }

    public function testSwitcherInstanceIdsStayUniqueWithoutSharedRequestContext(): void
    {
        $first = \Weline\I18n\Helper\SwitcherInstanceId::create('w-currency-switcher-menu');
        $second = \Weline\I18n\Helper\SwitcherInstanceId::create('w-currency-switcher-menu');

        self::assertNotSame($first, $second);
        self::assertMatchesRegularExpression('/^w-currency-switcher-menu-[0-9a-f]{24}$/D', $first);
        self::assertMatchesRegularExpression('/^w-currency-switcher-menu-[0-9a-f]{24}$/D', $second);
    }
}
