<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\TextileHeritageCatalog;
use Weline\Theme\Service\TextileHeritageLabels;

final class TextileHeritageWidgetContractTest extends TestCase
{
    public function testThemeOwnsReusableWidgetWithoutHomepageDefaultInjection(): void
    {
        $widgets = require dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Theme/widget.php';
        $widget = $widgets['textile-heritage'] ?? [];

        self::assertSame('textile-heritage', $widget['code'] ?? null);
        self::assertSame('Weline_Theme::theme/frontend/widgets/content/textile-heritage/default.phtml', $widget['template'] ?? null);
        self::assertSame(['homepage', 'cms_page'], $widget['page_layouts'] ?? null);
        self::assertSame('manual', $widget['placement'] ?? null);
        self::assertArrayNotHasKey('default_injections', $widget);
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
        self::assertStringContainsString('ObjectManager::getInstance(\\Weline\\Framework\\Http\\Url::class)', $template);
        self::assertStringNotContainsString('href="<?= $esc($link) ?>"', $template);
        self::assertStringContainsString('data-testid="textile-heritage"', $template);
        self::assertStringContainsString('repeat(6, minmax(0, 1fr))', $css);
        self::assertStringContainsString('repeat(3, minmax(0, 1fr))', $css);
        self::assertStringContainsString('repeat(2, minmax(0, 1fr))', $css);
        self::assertStringContainsString('white-space: nowrap', $css);
        self::assertStringContainsString('text-overflow: ellipsis', $css);
        self::assertStringContainsString('TextileHeritageLabels::articleTitle', $template);
        self::assertStringContainsString('-webkit-line-clamp: 3', $css);
        self::assertStringNotContainsString('<w:widget type="content" name="textile-heritage"', $homepage);
        self::assertStringContainsString('id="homepage-brands"', $homepage);
        self::assertStringContainsString('<w:widget type="content" name="brand-logos"', $homepage);
        self::assertStringContainsString('Weline_Theme::frontend::layouts::homepage::brands', $homepage);
    }

    public function testHomepageFollowsEcommerceShelfRhythmBeforeBrandsAndSocialProof(): void
    {
        $homepage = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/layouts/homepage/default.phtml'
        );

        $heroPosition = strpos($homepage, 'id="homepage-hero"');
        $categoriesPosition = strpos($homepage, 'id="homepage-categories"');
        $featuredPosition = strpos($homepage, 'id="homepage-featured"');
        $dealsPosition = strpos($homepage, 'id="homepage-deals"');
        $promoPosition = strpos($homepage, 'id="homepage-promo"');
        $newArrivalsPosition = strpos($homepage, 'id="homepage-new-arrivals"');
        $bestsellersPosition = strpos($homepage, 'id="homepage-bestsellers"');
        $brandsPosition = strpos($homepage, 'id="homepage-brands"');
        $testimonialsPosition = strpos($homepage, 'id="homepage-testimonials"');
        $reviewsPosition = strpos($homepage, 'id="homepage-reviews"');
        $videosPosition = strpos($homepage, 'id="homepage-videos"');

        self::assertIsInt($heroPosition);
        self::assertIsInt($categoriesPosition);
        self::assertIsInt($featuredPosition);
        self::assertIsInt($dealsPosition);
        self::assertIsInt($promoPosition);
        self::assertIsInt($newArrivalsPosition);
        self::assertIsInt($bestsellersPosition);
        self::assertIsInt($brandsPosition);
        self::assertIsInt($testimonialsPosition);
        self::assertIsInt($reviewsPosition);
        self::assertIsInt($videosPosition);
        self::assertTrue(
            $heroPosition < $categoriesPosition
            && $categoriesPosition < $featuredPosition
            && $featuredPosition < $dealsPosition
            && $dealsPosition < $promoPosition
            && $promoPosition < $newArrivalsPosition
            && $newArrivalsPosition < $bestsellersPosition
            && $bestsellersPosition < $brandsPosition,
            '电商节奏：Hero → 品类 → 精选 → 特价 → 促销 → 新品 → 畅销 → 品牌；品牌不得插在货架之前。'
        );
        self::assertTrue(
            $brandsPosition < $testimonialsPosition
            && $testimonialsPosition < $reviewsPosition
            && $reviewsPosition < $videosPosition,
            '品牌之后：买家秀图墙 → 买家评价 → YouTube 视频栏。'
        );
        self::assertStringContainsString('<w:widget type="product" name="deals-of-day"', $homepage);
    }

    public function testBlogSlugComesFromArticlePathNotCaption(): void
    {
        self::assertSame('textile-yunjin', TextileHeritageLabels::blogSlugFromLink('blog/textile-yunjin'));
        self::assertSame('textile-yunjin', TextileHeritageLabels::blogSlugFromLink('/en_US/USD/blog/textile-yunjin'));
        self::assertSame('textile-songjin', TextileHeritageLabels::blogSlugFromLink('https://shop.test/blog/textile-songjin?ref=home'));
        self::assertSame('', TextileHeritageLabels::blogSlugFromLink('/search?q=yunjin'));
    }
}
