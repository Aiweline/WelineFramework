<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;
use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityAssetCollector;
final class WidgetAssetPositionTest extends TestCase
{
    public function testLayoutWinsEvenWhenSourceWasSeenFirst(): void
    {
        $c = new ThemeLayoutEntityAssetCollector(null);
        $m = $c->collectFromNodes([
            ['source' => 'Weline_Theme::js/shared.js', 'source-postion' => 'footer'],
            ['widget_type' => 'header', 'layout_source' => 'Weline_Theme::js/shared.js'],
        ]);
        self::assertSame(['Weline_Theme::js/shared.js'], $m['layout_js']);
        self::assertSame([], $m['source_js']);
    }
    public function testPositionAliasesAndEarliestSourceLocation(): void
    {
        $c = new ThemeLayoutEntityAssetCollector(null);
        $m = $c->collectFromNodes([
            ['source' => 'Weline_Theme::js/a.js', 'source-position' => 'end-body'],
            ['source' => 'Weline_Theme::js/b.js', 'source-postion' => 'footer', 'source-position' => 'head'],
            ['source' => 'Weline_Theme::js/a.js', 'source_position' => 'head'],
        ]);
        self::assertSame('head', $m['source_positions']['Weline_Theme::js/a.js']);
        self::assertSame('footer', $m['source_positions']['Weline_Theme::js/b.js']);
        self::assertSame('body', $c->normalizePosition('end-body'));
    }
    public function testActualHtmlPlacementAndLegacyDefault(): void
    {
        $placer = new \Weline\Theme\Service\LayoutEntity\WidgetAssetHtmlPlacement();
        $tag = static fn(string $url, string $kind, string $position = ''): string => '<script src="/' . $url . '.js" data-weline-widget-asset="' . $kind . '"' . ($position !== '' ? ' data-weline-source-position="' . $position . '"' : '') . '></script>';
        $html = '<html><head></head><body><main></main><footer></footer>' . $tag('a', 'source', 'body') . $tag('b', 'source', 'footer') . $tag('legacy', 'source') . '</body></html>';
        $result = $placer->inject($html, $tag('a', 'layout') . $tag('b', 'source', 'body'));
        self::assertSame(1, substr_count($result, 'src="/a.js"'));
        self::assertSame(1, substr_count($result, 'src="/b.js"'));
        self::assertLessThan(strpos($result, '</head>'), strpos($result, 'src="/a.js"'));
        self::assertLessThan(strpos($result, '</head>'), strpos($result, 'src="/legacy.js"'));
        self::assertGreaterThan(strpos($result, '<footer>'), strpos($result, 'src="/b.js"'));
        self::assertLessThan(strpos($result, '</footer>'), strpos($result, 'src="/b.js"'));
        $noFooter = $placer->inject('<html><head></head><body>x' . $tag('b', 'source', 'footer') . '</body></html>');
        self::assertGreaterThan(strpos($noFooter, '<body>'), strpos($noFooter, 'src="/b.js"'));
        self::assertLessThan(strpos($noFooter, '</body>'), strpos($noFooter, 'src="/b.js"'));
    }
    public function testPageChromeMergePromotesLayoutAndDefaultsLegacyHead(): void
    {
        $paths = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths();
        $service = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutStorefrontHeadAssets(
            new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityConfigStore($paths),
            new ThemeLayoutEntityAssetCollector(null), $paths,
        );
        $m = $service->mergeManifests(
            ['source_js' => ['a.js', 'b.js'], 'source_positions' => ['a.js' => 'footer', 'b.js' => 'body']],
            ['layout_js' => ['a.js'], 'source_js' => ['b.js']],
        );
        self::assertSame(['a.js'], $m['layout_js']);
        self::assertSame(['b.js'], $m['source_js']);
        self::assertSame('head', $m['source_positions']['b.js']);
    }
    public function testDeduplicationPreservesProductCardCacheAdmissionMarker(): void
    {
        $placer = new \Weline\Theme\Service\LayoutEntity\WidgetAssetHtmlPlacement();
        $link = '<link rel="stylesheet" href="/product-card.css" data-weline-widget-asset="source">';
        $marked = str_replace('<link ', '<link data-weline-product-card-css="1" ', $link);
        $html = $placer->inject('<html><head>' . $link . '</head><body><div data-testid="weline-product-card"></div>' . $marked . '</body></html>');
        self::assertSame(1, substr_count($html, 'href="/product-card.css"'));
        self::assertTrue(\Weline\Framework\View\Helper\HtmlCacheAdmission::storefrontProductCardCssOk($html));
        self::assertSame($html, \Weline\Framework\View\Helper\HtmlCacheAdmission::healStorefrontProductCardCss($html));
    }
}
