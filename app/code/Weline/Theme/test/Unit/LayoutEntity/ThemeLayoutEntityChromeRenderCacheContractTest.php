<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;

/**
 * Cold chrome scopes with footer-container are too slow for every request; storefront
 * must reuse a durable rendered snapshot invalidated by chrome.phtml / config mtime.
 */
final class ThemeLayoutEntityChromeRenderCacheContractTest extends TestCase
{
    public function testRenderCurrentPersistsAndReusesRenderedHtmlSnapshot(): void
    {
        $chromePath = \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityChrome.php';
        $pathsPath = \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityPaths.php';
        $materializerPath = \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityMaterializer.php';

        self::assertFileExists($chromePath);
        self::assertFileExists($pathsPath);
        self::assertFileExists($materializerPath);

        $chrome = (string)\file_get_contents($chromePath);
        $paths = (string)\file_get_contents($pathsPath);
        $materializer = (string)\file_get_contents($materializerPath);

        self::assertStringContainsString('function chromeRenderedHtml', $paths);
        self::assertStringContainsString('ThemeVersionIdentity $identity', $paths);
        // Disk snapshot path is version-rooted rendered/{artifact}/{vary}.html (not legacy chrome.rendered.html).
        self::assertTrue(
            \str_contains($paths, "'rendered'") || \str_contains($paths, '"rendered"'),
            'chromeRenderedHtml must place files under a rendered/ segment',
        );
        self::assertStringContainsString('readRenderedCache', $chrome);
        self::assertStringContainsString('writeRenderedCache', $chrome);
        self::assertStringContainsString('finalizePublishedChromeRenderedHtml', $chrome);
        self::assertStringContainsString('isIncompleteRequiredChromeRendered', $chrome);
        self::assertStringContainsString('forceResolidifyRenderedSnapshot', $chrome);
        self::assertStringContainsString('renderedCachePath', $chrome);

        $filler = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntitySlotFiller.php'
        );
        self::assertStringContainsString('function finalizePublishedChromeRenderedHtml', $filler);
        self::assertStringContainsString('replaceBlankAttributeSlotInners', $filler);
        // Snapshot must sit next to the resolved chrome.phtml (published may inherit
        // an ancestor version — never key cache by the leaf request scope alone).
        self::assertStringContainsString('dirname($chromePhtmlPath)', $chrome);
        // Must key by storefront locale or zh freeze poisons every EN/HI page.
        self::assertStringContainsString('chrome.rendered.', $chrome);
        self::assertStringContainsString('WidgetI18n::storefrontLocale', $chrome);
        self::assertStringContainsString('State::setRequestLanguageOverride', $chrome);
        self::assertStringContainsString('delivery-line-1">Ship to', $chrome);
        self::assertStringContainsString('isLocalePoisonedChrome', $chrome);
        self::assertStringContainsString('定制与合作', $chrome);
        self::assertStringContainsString('支付与账户', $chrome);
        self::assertStringContainsString('publishedChromeRenderedPolicy', $chrome);
        self::assertStringContainsString('peekPolicy(', $chrome);
        self::assertStringContainsString('PostResponseTaskQueue::enqueue', $chrome);
        self::assertStringContainsString('rememberPolicy(', $chrome);
        self::assertStringContainsString('weline-footer--shell', $chrome);
        // Materializer no longer owns flat chrome.rendered.html snapshots; Paths+Chrome do.
        self::assertStringContainsString('function chromeRenderedHtml', $paths);
        self::assertStringContainsString('writeRenderedCache', $chrome);
        self::assertStringContainsString('readRenderedCache', $chrome);

        // Task 5: HotCache logical keys include owner V/mode/R identity cacheKey.
        self::assertStringContainsString("SNAPSHOT_FORMAT = 'v3'", $chrome);
        self::assertStringContainsString('identity->cacheKey()', $chrome);
        self::assertStringContainsString('if ($preview)', $chrome);
        self::assertStringContainsString('chrome.slot.projection.v5|', $filler);
        self::assertStringContainsString('page.location.v4|', $filler);
        self::assertStringContainsString('identity->cacheKey()', $filler);

        $identityApi = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Api/Version/ThemeVersionIdentity.php'
        );
        self::assertStringContainsString('function cacheKey', $identityApi);

        $publication = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/Version/ThemeVersionPublicationService.php'
        );
        self::assertStringContainsString("'invalidation'", $publication);
        self::assertStringContainsString('changed_owners', $publication);

        $cleaner = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/ThemeRuntimeCacheCleaner.php'
        );
        self::assertStringContainsString('invalidateAfterVersionPublish', $cleaner);
        self::assertStringContainsString('sweepOrphanLayoutEntityDerivatives', $cleaner);
    }
}
