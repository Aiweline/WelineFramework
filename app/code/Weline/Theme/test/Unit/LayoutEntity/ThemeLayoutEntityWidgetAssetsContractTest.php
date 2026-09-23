<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityAssetCollector;

final class ThemeLayoutEntityWidgetAssetsContractTest extends TestCase
{
    public function testPathsExposeAssetsSidecars(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityPaths.php'
        );
        self::assertStringContainsString('function pageAssetsJson', $src);
        self::assertStringContainsString('function chromeAssetsJson', $src);
        self::assertStringContainsString('page-assets.json', $src);
        self::assertStringContainsString('chrome-assets.json', $src);
    }

    public function testCollectorDedupesAndSplitsBuckets(): void
    {
        $collector = new ThemeLayoutEntityAssetCollector(null);
        $manifest = $collector->collectFromNodes([
            [
                'node_uid' => str_repeat('a', 32),
                'widget_module' => 'Weline_Theme',
                'widget_code' => 'default-header',
                'widget_type' => 'header',
                'is_active' => true,
                'layout_source' => 'Weline_Theme:css/widgets/header-chrome-amazon.css,Weline_Theme:js/widgets/header.js',
                'source' => 'Weline_Theme:css/widgets/header-chrome-amazon.css,Weline_Theme:css/widgets/header-search-amazon.css',
            ],
            [
                'node_uid' => str_repeat('b', 32),
                'widget_module' => 'Weline_Theme',
                'widget_code' => 'featured-products',
                'widget_type' => 'product',
                'is_active' => true,
                'layout_source' => 'Weline_Theme:css/widgets/should-skip-layout.css',
                'source' => 'Weline_Theme:css/widgets/featured-products.css',
            ],
        ], true);

        self::assertContains('Weline_Theme::css/widgets/header-chrome-amazon.css', $manifest['layout_css']);
        self::assertContains('Weline_Theme::js/widgets/header.js', $manifest['layout_js']);
        self::assertContains('Weline_Theme::css/widgets/header-search-amazon.css', $manifest['source_css']);
        self::assertContains('Weline_Theme::css/widgets/featured-products.css', $manifest['source_css']);
        self::assertNotContains('Weline_Theme::css/widgets/should-skip-layout.css', $manifest['layout_css']);
        // Deduped out of source because already in layout.
        self::assertNotContains('Weline_Theme::css/widgets/header-chrome-amazon.css', $manifest['source_css']);
        self::assertNotSame('', $manifest['fp']);
    }

    public function testEmitHtmlContainsMarkerAndTags(): void
    {
        $collector = new ThemeLayoutEntityAssetCollector(null);
        $html = $collector->emitHtml([
            'layout_css' => [],
            'layout_js' => [],
            'source_css' => [],
            'source_js' => [],
            'fp' => 'deadbeef',
        ]);
        self::assertStringContainsString('data-weline-widget-assets-fp="deadbeef"', $html);
    }

    public function testHeadAssetsFallsBackToChromeWithoutPagePointer(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutStorefrontHeadAssets.php'
        );
        self::assertStringContainsString('resolveActiveThemeScope', $src);
        self::assertStringContainsString('chromeScopeCandidates', $src);
        self::assertStringContainsString('readChromeAssets', $src);
    }

    public function testChromeMaterializeUsesRegistryBaseline(): void
    {
        $materializer = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityMaterializer.php'
        );
        $collector = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityAssetCollector.php'
        );
        self::assertStringContainsString('withChromeRegistryBaseline', $materializer);
        self::assertStringContainsString('function withChromeRegistryBaseline', $collector);
    }

    public function testTaglibDeclaresSourceAttrs(): void
    {
        $widgetTaglib = \dirname(__DIR__, 4) . '/Widget/Taglib/Widget.php';
        self::assertFileExists($widgetTaglib);
        $src = (string)\file_get_contents($widgetTaglib);
        self::assertStringContainsString("'layout-source'", $src);
        self::assertStringContainsString("'source'", $src);
    }

    public function testDocsRequireBakeContract(): void
    {
        $spec = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/doc/部件静态资源固化规范.md'
        );
        self::assertStringContainsString('layout-source', $spec);
        self::assertStringContainsString('page-assets.json', $spec);
    }
}
