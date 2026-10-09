<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;
use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\WidgetAssetHtmlPlacement;
use Weline\Theme\Service\LayoutEntity\WidgetAssetOptimizer;
use Weline\Theme\Service\LayoutEntity\WidgetAssetArtifactPublisher;
final class WidgetAssetOptimizationTest extends TestCase
{
    public function testNestedAndEmptyWidgetsCountAndEarlySharedAssetStaysIndependent(): void
    {
        $publisher = new class extends WidgetAssetArtifactPublisher {
            public array $batches = [];
            public function canMerge(array $asset): bool { return true; }
            public function publish(array $assets, bool $minify): ?string {
                $this->batches[] = array_column($assets, 'url');
                return '/static/bundle-' . count($this->batches) . '.js';
            }
        };
        $optimizer = new WidgetAssetOptimizer($publisher);
        $tag = static fn(string $name): string => '<script defer src="/' . $name . '.js" data-weline-widget-asset="source"></script>';
        $begin = '<!--weline-widget:start-->';
        $end = '<!--weline-widget:end-->';
        $html = '<html><head></head><body>' . $begin . $tag('shared') . $begin . $end . $end;
        for ($i = 3; $i <= 7; $i++) { $html .= $begin . ($i >= 6 ? $tag('shared') . $tag('w' . $i) : '') . $end; }
        $html .= '</body></html>';
        $result = (new WidgetAssetHtmlPlacement($optimizer))->inject($html, '', ['js_merge' => true, 'js_merge_start_widget' => 6]);
        // Under PROD, singleton early shared is published alone; late widgets still merge.
        self::assertSame([['/shared.js'], ['/w6.js', '/w7.js']], $publisher->batches);
        self::assertStringContainsString('/static/bundle-1.js', $result);
        self::assertStringContainsString('/static/bundle-2.js', $result);
        self::assertStringNotContainsString('weline-widget:start', $result);
        self::assertLessThan(\strpos($result, 'bundle-2.js'), \strpos($result, 'bundle-1.js') !== false ? \strpos($result, 'bundle-1.js') : \PHP_INT_MAX);
        // Early shared must not join the late merge batch.
        self::assertNotContains('/shared.js', $publisher->batches[1]);
    }
    public function testMixedCssJsStreamsKeepJsOrderAndLayoutIndependent(): void
    {
        $publisher = new class extends WidgetAssetArtifactPublisher {
            public array $batches = [];
            public function canMerge(array $asset): bool { return !str_contains($asset['url'], 'boundary'); }
            public function publish(array $assets, bool $minify): ?string {
                $this->batches[] = array_column($assets, 'url');
                return '/static/bundle-' . count($this->batches) . '.' . $assets[0]['type'];
            }
        };
        $make = static fn(string $url, string $type = 'js', string $kind = 'source'): array => [
            'url' => $url, 'tag' => $type === 'js' ? '<script defer src="' . $url . '"></script>' : '<link href="' . $url . '">',
            'position' => 'head', 'kind' => $kind, 'first_widget_index' => 6,
        ];
        $result = (new WidgetAssetOptimizer($publisher))->transform([
            $make('/layout.js', 'js', 'layout'), $make('/a.js'), $make('/a.css', 'css'),
            $make('/b.js'), $make('/b.css', 'css'), $make('/boundary.js'), $make('/c.js'),
        ], [
            'js_merge' => true,
            'css_merge' => true,
            'js_merge_start_widget' => 6,
            'css_merge_start_widget' => 6,
        ]);
        // Merge path always publishes (incl. singletons); layout/boundary/c stay independent of source merges.
        self::assertSame([
            ['/layout.js'],
            ['/a.js', '/b.js'],
            ['/boundary.js'],
            ['/c.js'],
            ['/a.css', '/b.css'],
        ], $publisher->batches);
        self::assertSame([
            '/static/bundle-1.js',
            '/static/bundle-2.js',
            '/static/bundle-5.css',
            '/static/bundle-3.js',
            '/static/bundle-4.js',
        ], \array_column($result, 'url'));
    }

    public function testLayoutCssMergesIntoSingleBlockingPackIgnoringStart(): void
    {
        $publisher = new class extends WidgetAssetArtifactPublisher {
            public array $batches = [];
            public function canMerge(array $asset): bool { return true; }
            public function publish(array $assets, bool $minify): ?string {
                $this->batches[] = array_column($assets, 'url');
                return '/static/layout-pack.css';
            }
        };
        $make = static fn(string $url, int $index = 1): array => [
            'url' => $url,
            'tag' => '<link rel="stylesheet" href="' . $url . '">',
            'position' => 'head',
            'kind' => 'layout',
            'first_widget_index' => $index,
        ];
        $result = (new WidgetAssetOptimizer($publisher))->transform([
            $make('/layout-a.css', 1),
            $make('/layout-b.css', 2),
            $make('/layout-c.css', 40),
        ], ['css_merge' => true, 'css_merge_start_widget' => 25]);
        self::assertSame([['/layout-a.css', '/layout-b.css', '/layout-c.css']], $publisher->batches);
        self::assertCount(1, $result);
        self::assertStringContainsString('data-weline-layout-pack="1"', $result[0]['tag']);
        self::assertStringNotContainsString('media="print"', $result[0]['tag']);
    }

    public function testLateSourceCssGetsDeferredMediaAndNoscript(): void
    {
        $publisher = new class extends WidgetAssetArtifactPublisher {
            public function canMerge(array $asset): bool { return true; }
            public function publish(array $assets, bool $minify): ?string {
                return '/static/late-source.css';
            }
        };
        $result = (new WidgetAssetOptimizer($publisher))->transform([[
            'url' => '/late.css',
            'tag' => '<link rel="stylesheet" href="/late.css">',
            'position' => 'head',
            'kind' => 'source',
            'first_widget_index' => 25,
        ]], ['css_merge' => true, 'css_merge_start_widget' => 25]);
        self::assertCount(1, $result);
        self::assertStringContainsString('media="print"', $result[0]['tag']);
        self::assertStringContainsString("onload=\"this.media='all'\"", $result[0]['tag']);
        self::assertStringContainsString('<noscript>', $result[0]['tag']);
    }

    public function testUnwrappedTopLevelDeclarationsKeepTheirFileBoundary(): void
    {
        self::assertFalse(WidgetAssetArtifactPublisher::isIsolatedJavaScript('globalThis.before = typeof later;'));
        self::assertFalse(WidgetAssetArtifactPublisher::isIsolatedJavaScript('function later(){}'));
        self::assertTrue(WidgetAssetArtifactPublisher::isIsolatedJavaScript('(function () { const value = "x"; window.x = value; })();'));
        self::assertTrue(WidgetAssetArtifactPublisher::isIsolatedJavaScript("window.WelineWidgetAssets.register('x', function (anchor) { anchor.hidden = true; });"));
        self::assertFalse(WidgetAssetArtifactPublisher::isIsolatedJavaScript('(function () {})(); function later(){}'));
    }

    public function testCssUrlTokensDoNotRewriteStringsAndSupportQuotedParentheses(): void
    {
        $publisher = new WidgetAssetArtifactPublisher();
        $css = '.x{background:url("images/icon(2).png")} .x::after{content:"url(icon.png)"}/* url(ignore.png) */';
        $output = $publisher->rewriteCssUrls($css, 'https://shop.test/static/theme/css/widget.css');
        self::assertStringContainsString('url("https://shop.test/static/theme/css/images/icon(2).png")', $output);
        self::assertStringContainsString('content:"url(icon.png)"', $output);
        self::assertStringContainsString('/* url(ignore.png) */', $output);
    }

    public function testCssRelativeUrlsPreserveOriginalBase(): void
    {
        $publisher = new WidgetAssetArtifactPublisher();
        $css = 'a{background:url(../img/a.png)}b{src:url("/fixed.woff")}c{background:url(data:image/png;base64,xyz)}';
        $rewritten = $publisher->rewriteCssUrls($css, 'https://cdn.example.test/pkg/css/a.css?v=1');
        self::assertStringContainsString('https://cdn.example.test/pkg/img/a.png', $rewritten);
        self::assertStringContainsString('url("/fixed.woff")', $rewritten);
        self::assertStringContainsString('url(data:image/png;base64,xyz)', $rewritten);
    }
}
