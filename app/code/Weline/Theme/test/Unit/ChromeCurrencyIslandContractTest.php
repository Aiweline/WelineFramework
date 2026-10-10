<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Theme\Block\Partials;

\defined('BP') || \define('BP', \dirname(__DIR__, 6) . \DIRECTORY_SEPARATOR);
\defined('DS') || \define('DS', \DIRECTORY_SEPARATOR);

require_once BP . 'app/autoload.php';
require_once BP . 'app/code/Weline/Theme/Block/Partials.php';

/**
 * Chrome lang-shell + currency-switcher island: shared bag stores stripped shell;
 * serve path hydrates live switcher HTML into marked islands.
 */
final class ChromeCurrencyIslandContractTest extends TestCase
{
    public function testDefaultHeaderWrapsCurrencySwitcherInIsland(): void
    {
        $path = BP . 'app/code/Weline/Theme/view/theme/frontend/partials/header/default.phtml';
        $source = (string)\file_get_contents($path);
        self::assertStringContainsString('data-weline-chrome-island="currency-switcher"', $source);
        self::assertGreaterThanOrEqual(
            3,
            \substr_count($source, 'data-weline-chrome-island="currency-switcher"'),
        );
    }

    public function testStripThenHydrateRestoresIslandBody(): void
    {
        $partials = (new ReflectionClass(Partials::class))->newInstanceWithoutConstructor();
        $strip = new \ReflectionMethod(Partials::class, 'stripChromeCurrencyIslands');
        $strip->setAccessible(true);
        $hydrate = new \ReflectionMethod(Partials::class, 'hydrateChromeCurrencyIslands');
        $hydrate->setAccessible(true);

        $shell = '<nav><weline-chrome-island data-weline-chrome-island="currency-switcher" style="display:contents">'
            . '<button data-currency="EUR">EUR</button>'
            . '</weline-chrome-island></nav>';

        $stripped = (string)$strip->invoke($partials, $shell);
        self::assertStringContainsString('<!--weline-chrome-island-pending:currency-switcher-->', $stripped);
        self::assertStringNotContainsString('data-currency="EUR"', $stripped);

        // Without a live Template hook, hydrate keeps the pending marker (fail-closed:
        // never inject empty and never re-bake EUR into the shared shell).
        $hydrated = (string)$hydrate->invoke($partials, $stripped);
        self::assertStringContainsString('<!--weline-chrome-island-pending:currency-switcher-->', $hydrated);
        self::assertStringNotContainsString('data-currency="EUR"', $hydrated);
        self::assertStringContainsString('data-weline-chrome-island="currency-switcher"', $hydrated);
    }

    public function testPartialsSharedChromeBuilderStripsIslands(): void
    {
        $source = (string)\file_get_contents(BP . 'app/code/Weline/Theme/Block/Partials.php');
        self::assertStringContainsString('renderStorefrontChromeShellForSharedBag', $source);
        self::assertStringContainsString('stripChromeCurrencyIslands', $source);
        self::assertStringContainsString('hydrateChromeCurrencyIslands', $source);
        self::assertStringContainsString('finalizeStorefrontChromeHtml', $source);
        self::assertStringContainsString('rewriteCurrencySwitcherRoots', $source);
        self::assertStringContainsString('findHtmlElementRange', $source);
    }

    public function testI18nCurrencySwitcherHookOwnsIslandMarker(): void
    {
        $path = BP . 'app/code/Weline/I18n/view/hooks/header-currency-switcher.phtml';
        $source = (string)\file_get_contents($path);
        self::assertStringContainsString('data-weline-chrome-island="currency-switcher"', $source);
        self::assertStringContainsString('data-currency-switcher="true"', $source);
    }

    public function testRewriteCurrencySwitcherRootsWrapsBareSwitcher(): void
    {
        $partials = (new ReflectionClass(Partials::class))->newInstanceWithoutConstructor();
        $rewrite = new \ReflectionMethod(Partials::class, 'rewriteCurrencySwitcherRoots');
        $rewrite->setAccessible(true);

        $bare = '<nav><div class="w-currency-switcher" data-currency-switcher="true"><span>EUR</span></div></nav>';
        $out = (string)$rewrite->invoke($partials, $bare, '<!--pending-->');
        self::assertStringContainsString('data-weline-chrome-island="currency-switcher"', $out);
        self::assertStringContainsString('<!--pending-->', $out);
        self::assertStringNotContainsString('>EUR<', $out);
    }

    public function testRewriteSkipsRootsAlreadyInsideIslandWithoutLooping(): void
    {
        $partials = (new ReflectionClass(Partials::class))->newInstanceWithoutConstructor();
        $rewrite = new \ReflectionMethod(Partials::class, 'rewriteCurrencySwitcherRoots');
        $rewrite->setAccessible(true);

        $wrapped = '<weline-chrome-island data-weline-chrome-island="currency-switcher" style="display:contents">'
            . '<div data-currency-switcher="true"><span>EUR</span></div>'
            . '</weline-chrome-island>';
        $out = (string)$rewrite->invoke($partials, $wrapped, 'LIVE');
        self::assertSame($wrapped, $out);
    }
}
