<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;
use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityAssetCollector;
final class WidgetAssetPositionTest extends TestCase
{
    public function testSelectedDesignTemplateKeepsItsAssetsAlongsideExplicitSources(): void
    {
        $path = BP . 'app/design/Weline/hanfu/frontend/widgets/container/footer/default.phtml';
        $html = (new \Weline\Theme\Service\LayoutEntity\WidgetAssetRenderer())->render(
            ['type' => 'container', 'code' => 'footer'],
            ['_source' => 'Weline_Theme::js/widgets/widget-assets-runtime.js'], $path,
        );
        self::assertStringContainsString('widget-hanfu-container-footer-default-0.js', $html);
        self::assertStringContainsString('widget-assets-runtime.js', $html);
    }
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

    public function testSourceQueryStringDoesNotDropCssFromBucket(): void
    {
        $c = new ThemeLayoutEntityAssetCollector(null);
        $m = $c->collectFromNodes([
            [
                'widget_type' => 'content',
                'widget_code' => 'checkout-shipping-address',
                'source' => 'Weline_Shipping::css/widgets/checkout-shipping-address.css?v=20260926-save-loading',
                'source_position' => 'head',
            ],
        ]);
        self::assertSame(
            ['Weline_Shipping::css/widgets/checkout-shipping-address.css'],
            $m['source_css'],
        );
        self::assertSame(
            'head',
            $m['source_positions']['Weline_Shipping::css/widgets/checkout-shipping-address.css'],
        );
        $m2 = $c->collectFromNodes([
            ['source' => 'Weline_Theme::js/a.js?v=1#frag,Weline_Theme::css/b.css?cache=1'],
        ]);
        self::assertSame(['Weline_Theme::css/b.css'], $m2['source_css']);
        self::assertSame(['Weline_Theme::js/a.js'], $m2['source_js']);
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

    public function testLayoutPackInsertsAfterThemeLinkNotInsideAfterCssScript(): void
    {
        $placer = new \Weline\Theme\Service\LayoutEntity\WidgetAssetHtmlPlacement();
        $layout = '<link data-weline-layout-pack="1" data-weline-widget-asset="layout" rel="stylesheet" href="/layout.css">';
        $head = '<style data-weline-layout-critical></style>'
            . '<link rel="stylesheet" data-weline-theme-head-pack="storefront" href="/theme.css">'
            . '<link rel="stylesheet" data-weline-theme-disk="1" href="/disk.css">'
            . '<link rel="stylesheet" data-weline-theme-head-pack="overlay" data-weline-theme-overlay="1" href="/overlay.css">'
            . '<script data-weline-theme-head-pack="early">/*early*/</script>'
            . '<script src="/after-css.js" defer data-weline-theme-head-pack="after-css"></script>';
        $html = $placer->inject('<html><head>' . $head . '</head><body>' . $layout . '</body></html>');
        $overlayPos = strpos($html, 'data-weline-theme-overlay=');
        $layoutPos = strpos($html, 'data-weline-layout-pack=');
        $afterCssPos = strpos($html, 'data-weline-theme-head-pack="after-css"');
        self::assertNotFalse($overlayPos);
        self::assertNotFalse($layoutPos);
        self::assertNotFalse($afterCssPos);
        self::assertLessThan($layoutPos, $overlayPos); // layout after overlay marker
        self::assertLessThan($afterCssPos, $layoutPos); // and before after-css script
        // Must not sit inside any <script>…</script> (swallowed → never loads).
        $scriptOpen = strrpos(substr($html, 0, $layoutPos), '<script');
        if ($scriptOpen !== false) {
            $scriptClose = strpos($html, '</script>', $scriptOpen);
            self::assertNotFalse($scriptClose);
            self::assertFalse($scriptOpen < $layoutPos && $layoutPos < $scriptClose);
        }
        self::assertSame(1, substr_count($html, 'href="/layout.css"'));
    }

    public function testLayoutPackPrefersExplicitHeadLayoutAssetsSlot(): void
    {
        $placer = new \Weline\Theme\Service\LayoutEntity\WidgetAssetHtmlPlacement();
        $layout = '<link data-weline-layout-pack="1" data-weline-widget-asset="layout" rel="stylesheet" href="/layout.css">';
        $head = '<link rel="stylesheet" data-weline-theme-head-pack="storefront" href="/theme.css">'
            . '<!--weline:head-layout-assets-->'
            . '<script defer src="/runtime.js"></script>'
            . '<link rel="stylesheet" data-weline-theme-overlay="1" href="/late-overlay.css">';
        $html = $placer->inject('<html><head>' . $head . '</head><body>' . $layout . '</body></html>');
        $themePos = strpos($html, 'href="/theme.css"');
        $layoutPos = strpos($html, 'data-weline-layout-pack=');
        $runtimePos = strpos($html, 'src="/runtime.js"');
        $overlayPos = strpos($html, 'href="/late-overlay.css"');
        self::assertNotFalse($themePos);
        self::assertNotFalse($layoutPos);
        self::assertNotFalse($runtimePos);
        self::assertNotFalse($overlayPos);
        self::assertLessThan($layoutPos, $themePos);
        self::assertLessThan($runtimePos, $layoutPos);
        self::assertLessThan($overlayPos, $layoutPos);
        self::assertStringNotContainsString('weline:head-layout-assets', $html);
        self::assertSame(1, substr_count($html, 'href="/layout.css"'));
    }
}
