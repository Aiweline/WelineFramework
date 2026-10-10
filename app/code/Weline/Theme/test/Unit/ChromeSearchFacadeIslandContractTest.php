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
 * Chrome lang-shell + header-search facade island: shared bag stores stripped shell;
 * serve path hydrates live search bar (q/type/category) into marked islands.
 */
final class ChromeSearchFacadeIslandContractTest extends TestCase
{
    public function testDefaultHeaderWrapsSearchFacadeInIsland(): void
    {
        $path = BP . 'app/code/Weline/Theme/view/theme/frontend/partials/header/default.phtml';
        $source = (string)\file_get_contents($path);
        self::assertStringContainsString('data-weline-chrome-island="header-search"', $source);
        self::assertStringContainsString("'query' => ''", $source);
        self::assertStringNotContainsString("getParam('q')", $source);
    }

    public function testHeaderCacheKeyIsGuestNeutralWithoutSearchFacade(): void
    {
        $source = (string)\file_get_contents(BP . 'app/code/Weline/Theme/Block/Partials.php');
        self::assertStringContainsString('chrome-partial-v22-layout-agnostic-shell', $source);
        self::assertStringContainsString('hydrateChromeSearchFacadeIslands', $source);
        self::assertStringContainsString('stripChromeSearchFacadeIslands', $source);
        $headerDataStart = \strpos($source, "if (\$type === 'header')");
        self::assertNotFalse($headerDataStart);
        $headerDataChunk = \substr($source, (int)$headerDataStart, 700);
        self::assertStringContainsString('do NOT partition', $headerDataChunk);
        self::assertStringNotContainsString("'q' =>", $headerDataChunk);
        self::assertStringNotContainsString("'category_id'", $headerDataChunk);
        self::assertStringNotContainsString("'blog_category_id'", $headerDataChunk);
    }

    public function testStripThenHydrateKeepsPendingWhenLiveRenderUnavailable(): void
    {
        $partials = (new ReflectionClass(Partials::class))->newInstanceWithoutConstructor();
        $strip = new \ReflectionMethod(Partials::class, 'stripChromeSearchFacadeIslands');
        $strip->setAccessible(true);
        $hydrate = new \ReflectionMethod(Partials::class, 'hydrateChromeSearchFacadeIslands');
        $hydrate->setAccessible(true);

        $shell = '<header><weline-chrome-island data-weline-chrome-island="header-search" style="display:contents">'
            . '<div data-w-search><input name="q" value="hanfu"></div>'
            . '</weline-chrome-island></header>';

        $stripped = (string)$strip->invoke($partials, $shell);
        self::assertStringContainsString('<!--weline-chrome-island-pending:header-search-->', $stripped);
        self::assertStringNotContainsString('value="hanfu"', $stripped);

        $hydrated = (string)$hydrate->invoke($partials, $stripped);
        // Must never re-bake the tip query that was stripped from the shared shell.
        self::assertStringNotContainsString('value="hanfu"', $hydrated);
        self::assertStringContainsString('data-weline-chrome-island="header-search"', $hydrated);
    }

    public function testSharedChromeBuilderStripsSearchFacade(): void
    {
        $source = (string)\file_get_contents(BP . 'app/code/Weline/Theme/Block/Partials.php');
        self::assertStringContainsString('stripChromeSearchFacadeIslands', $source);
        self::assertStringContainsString('rewriteHeaderSearchRoots', $source);
        self::assertStringContainsString('renderHeaderSearchFacadeIslandHtml', $source);
    }
}
