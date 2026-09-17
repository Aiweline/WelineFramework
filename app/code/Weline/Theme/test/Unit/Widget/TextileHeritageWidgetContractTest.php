<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\TextileHeritageCatalog;

final class TextileHeritageWidgetContractTest extends TestCase
{
    public function testThemeOwnsReusableWidgetAndHomepageDefaultInjection(): void
    {
        $widgets = require dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Theme/widget.php';
        $widget = $widgets['textile-heritage'] ?? [];

        self::assertSame('textile-heritage', $widget['code'] ?? null);
        self::assertSame('Weline_Theme::theme/frontend/widgets/content/textile-heritage/default.phtml', $widget['template'] ?? null);
        self::assertSame(['homepage', 'cms_page'], $widget['page_layouts'] ?? null);
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('homepage', $injection['layout_type'] ?? null);
        self::assertSame('default', $injection['layout_option'] ?? null);
        self::assertSame('homepage-brands', $injection['slot'] ?? null);
        self::assertTrue((bool)($injection['required'] ?? false));
    }

    public function testCatalogMapsExactlySixRealRasterAssetsWithProvenance(): void
    {
        $items = TextileHeritageCatalog::items();
        self::assertCount(6, $items);
        self::assertSame(['云锦', '宋锦', '蜀锦', '苏绣', '妆花', '花罗'], array_column($items, 'name'));
        $root = dirname(__DIR__, 3);
        foreach ($items as $item) {
            $image = (string)$item['image'];
            self::assertStringStartsWith('/Weline/Theme/view/statics/images/textile-heritage/', $image);
            self::assertDoesNotMatchRegularExpression('/\.svg(?:$|\?)/i', $image);
            self::assertMatchesRegularExpression('/\.(?:jpe?g|png|webp)$/i', $image);
            self::assertStringStartsWith('https://', (string)$item['source_url']);
            self::assertNotSame('', trim((string)$item['object_title']));
            self::assertNotSame('', trim((string)($item['object_title_en'] ?? '')));
            self::assertArrayHasKey('creator_en', $item);
            self::assertNotSame('', trim((string)$item['collection']));
            self::assertNotSame('', trim((string)$item['license']));
            self::assertStringStartsWith('https://', (string)$item['license_url']);
            self::assertFileExists($root . '/view/statics/images/textile-heritage/' . basename($image));
            self::assertStringStartsWith('blog/textile-', (string)$item['link']);
            self::assertStringNotContainsString('/search?', (string)$item['link']);
            self::assertFalse(str_starts_with((string)$item['link'], '/'));
        }
    }

    public function testTemplateUsesScopedResponsiveMarkupWithoutInlineScript(): void
    {
        $root = dirname(__DIR__, 3);
        $template = (string)file_get_contents($root . '/view/theme/frontend/widgets/content/textile-heritage/default.phtml');
        $css = (string)file_get_contents($root . '/view/statics/css/widgets/textile-heritage.css');
        $homepage = (string)file_get_contents($root . '/view/theme/frontend/layouts/homepage/default.phtml');

        self::assertStringContainsString('@widget.code {textile-heritage}', $template);
        self::assertStringContainsString('weline-code="<?= $esc($scope->code) ?>"', $template);
        self::assertStringContainsString('data-testid="textile-heritage"', $template);
        self::assertStringContainsString('object_title_en', $template);
        self::assertStringContainsString('creator_en', $template);
        self::assertStringContainsString('decoding="async"', $template);
        self::assertStringContainsString('heritage-source', $template);
        self::assertStringNotContainsString('<script', $template);
        self::assertStringContainsString('Catalog owns article deep links', $template);
        self::assertStringContainsString("\$catalogLink !== '' ? \$catalogLink", $template);
        self::assertStringContainsString('@url{$linkPath}', $template);
        self::assertStringNotContainsString('href="<?= $esc($link) ?>"', $template);
        self::assertStringContainsString('data-testid="textile-heritage"', $template);
        self::assertStringContainsString('repeat(6, minmax(0, 1fr))', $css);
        self::assertStringContainsString('repeat(3, minmax(0, 1fr))', $css);
        self::assertStringContainsString('repeat(2, minmax(0, 1fr))', $css);
        self::assertStringContainsString('white-space: nowrap', $css);
        self::assertStringContainsString('text-overflow: ellipsis', $css);
        self::assertStringContainsString('<w:widget type="content" name="textile-heritage"', $homepage);
        self::assertStringContainsString('id="homepage-brands"', $homepage);
        self::assertStringContainsString('Theme textile-heritage default_injections', $homepage);
    }

    public function testHomepageSurfacesTextileHeritageImmediatelyAfterHero(): void
    {
        $homepage = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/layouts/homepage/default.phtml'
        );

        $heroPosition = strpos($homepage, 'id="homepage-hero"');
        $heritagePosition = strpos($homepage, 'id="homepage-brands"');
        $featuredPosition = strpos($homepage, 'id="homepage-featured"');

        self::assertIsInt($heroPosition);
        self::assertIsInt($heritagePosition);
        self::assertIsInt($featuredPosition);
        self::assertTrue(
            $heroPosition < $heritagePosition && $heritagePosition < $featuredPosition,
            '织艺谱系应紧跟首页 Hero 出现，不应被商品列表埋到页尾。'
        );
    }
}
