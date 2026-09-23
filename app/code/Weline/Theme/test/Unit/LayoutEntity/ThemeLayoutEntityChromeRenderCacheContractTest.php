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
        self::assertStringContainsString('chrome.rendered.html', $paths);
        self::assertStringContainsString('chromeRenderedHtmlSnapshots', $paths);
        self::assertStringContainsString('chrome.rendered*.html', $paths);
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
        self::assertStringContainsString('publishedChromeRenderedPolicy', $chrome);
        self::assertStringContainsString('peekPolicy(', $chrome);
        self::assertStringContainsString('PostResponseTaskQueue::enqueue', $chrome);
        self::assertStringContainsString('rememberPolicy(', $chrome);
        self::assertStringContainsString('weline-footer--shell', $chrome);
        self::assertStringContainsString('chromeRenderedHtmlSnapshots', $materializer);
        self::assertStringContainsString('@\\unlink($rendered)', $materializer);
    }
}
