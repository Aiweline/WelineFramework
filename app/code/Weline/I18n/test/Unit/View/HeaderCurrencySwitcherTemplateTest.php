<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class HeaderCurrencySwitcherTemplateTest extends TestCase
{
    private function templateSource(): string
    {
        $path = dirname(__DIR__, 3) . '/view/hooks/header-currency-switcher.phtml';
        self::assertFileExists($path);

        return (string)file_get_contents($path);
    }

    public function testCurrencySwitcherDelegatesToIslandBuilder(): void
    {
        $content = $this->templateSource();

        self::assertStringContainsString('CurrencySwitcherIslandBuilder', $content);
        self::assertStringContainsString('renderLiveIslandHtml', $content);
        self::assertStringContainsString('isChromeShellBuild', $content);
        self::assertStringContainsString('pendingIslandHtml', $content);
    }

    public function testCurrencySwitcherOmitsHotPathCatalogAssembly(): void
    {
        $content = $this->templateSource();

        self::assertStringNotContainsString('CurrencyData::getCurrencies', $content);
        self::assertStringNotContainsString('CurrencyDisplayName::forCode', $content);
        self::assertStringNotContainsString('header-currency-switcher-data', $content);
        self::assertStringNotContainsString('EventsManager', $content);
        self::assertStringNotContainsString('aliasDisplayLocales', $content);
        self::assertStringNotContainsString('Symfony\\Component\\Intl\\Currencies', $content);
        self::assertStringNotContainsString('Symfony\Component\Intl\Currencies', $content);
    }

    public function testCurrencySwitcherKeepsIslandMarkerContractViaBuilder(): void
    {
        $builderPath = dirname(__DIR__, 4) . '/Currency/Service/CurrencySwitcherIslandBuilder.php';
        self::assertFileExists($builderPath);
        $builder = (string)file_get_contents($builderPath);

        self::assertStringContainsString('data-weline-chrome-island="currency-switcher"', $builder);
        self::assertStringContainsString('data-currency-switcher="true"', $builder);
        self::assertStringContainsString('SwitcherInstanceId::create', $builder);
        self::assertStringContainsString('w-currency-switcher__trigger', $builder);
        self::assertStringNotContainsString('name="cash"', $builder);
        self::assertStringContainsString('chevron-down', $builder);
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
