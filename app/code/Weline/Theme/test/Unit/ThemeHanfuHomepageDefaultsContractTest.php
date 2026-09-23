<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class ThemeHanfuHomepageDefaultsContractTest extends TestCase
{
    public function testHomepageMetadataUsesGenericShellName(): void
    {
        $layout = $this->readProjectFile('view/theme/frontend/layouts/homepage/default.phtml');

        self::assertStringContainsString(
            '@meta.name {default="首页"',
            $layout
        );
        self::assertStringContainsString(
            '@param.title {default=""',
            $layout
        );
        self::assertStringContainsString('resolveFrontendSiteName()', $layout);
        self::assertStringNotContainsString('水墨汉服商城首页', $layout);
        self::assertStringNotContainsString('default="Homepage Default"', $layout);
        self::assertStringContainsString('WidgetI18n::label($source)', $layout);
        self::assertStringContainsString('$useEnglishCopy = !$isChineseLocale;', $layout);
        self::assertStringNotContainsString('$this->setData(\'meta_title\'', $layout);
        self::assertStringNotContainsString('$this->setData(\'meta_description\'', $layout);
        self::assertSame(1, substr_count($layout, '<h1 class="w-visually-hidden">'));

        $provider = $this->readProjectFile(
            'extends/module/Weline_Seo/SeoProfileProvider/HanfuHomepageSeoProfileProvider.php'
        );
        self::assertStringContainsString('implements SeoProfileProviderInterface', $provider);
        self::assertStringContainsString("PAGE_LABEL_EN = 'Home'", $provider);
        self::assertStringContainsString('SiteBrand', $provider);
        self::assertStringNotContainsString('changan.hanfu', $provider);
        self::assertStringNotContainsString("SITE_NAME_ZH = '", $provider);
    }

    public function testDefaultHeroUsesLocaleCatalogBeforeReviewedEnglishFallback(): void
    {
        $hero = $this->readProjectFile('view/theme/frontend/widgets/banner/hero-slider/default.phtml');

        self::assertStringContainsString('$isChineseLocale = $normalizedLocale === \'\' || str_starts_with($normalizedLocale, \'zh\');', $hero);
        self::assertStringContainsString('$translateDefaultCopy = static function', $hero);
        self::assertStringContainsString('WidgetI18n::label($source)', $hero);
        self::assertStringContainsString(
            '\'title\' => $translateDefaultCopy($default[\'title_zh\'], $default[\'title_en\'])',
            $hero
        );
        self::assertStringContainsString(
            '\'button_text\' => $translateDefaultCopy(\'浏览精选\', \'Shop Featured\')',
            $hero
        );
        self::assertStringContainsString(
            '\'secondary_button_text\' => $translateDefaultCopy(\'按场景选\', \'Shop by Occasion\')',
            $hero
        );
        self::assertStringContainsString("'link' => '#homepage-featured'", $hero);
        self::assertStringContainsString("'secondary_link' => '/category/hanfu/occasion'", $hero);
        self::assertStringContainsString('data-hero-cta="primary"', $hero);
        self::assertStringContainsString('data-hero-cta="secondary"', $hero);
        self::assertStringNotContainsString(
            '\'button_text\' => $translateDefaultCopy(\'浏览系列\', \'Explore collection\')',
            $hero
        );
        self::assertStringNotContainsString('$useEnglishCopy ?', $hero);
    }

    public function testDefaultCategoryGridUsesLocaleCatalogForNonEnglishLocales(): void
    {
        $widget = $this->readProjectFile('view/theme/frontend/widgets/category/category-grid/default.phtml');

        self::assertStringContainsString('$translateDefaultCopy = static function', $widget);
        self::assertStringContainsString('categoryDisplayNamesByCode()', $widget);
        self::assertStringNotContainsString(
            '\'name\' => $translateDefaultCopy($category[\'name_zh\'], $category[\'name_en\'])',
            $widget
        );
        self::assertStringNotContainsString('$isEnglish ?', $widget);
    }

    public function testDefaultHeroRoutesCuratedSlidesToLocalizedCatalogInsteadOfMissingProducts(): void
    {
        $hero = $this->readProjectFile('view/theme/frontend/widgets/banner/hero-slider/default.phtml');

        foreach ([
            '/product/taoyuan-qingmeng',
            '/product/shenlong-yin-zhuanghua-mamian',
            '/product/zuimeng-xifeng-xianhe-mamian',
        ] as $missingProductUrl) {
            self::assertStringNotContainsString($missingProductUrl, $hero);
        }

        self::assertSame(3, substr_count($hero, "'link' => '#homepage-featured'"));
        self::assertStringContainsString('ProductCardUrl::splitForTaglib($href)', $hero);
        self::assertStringContainsString('$this->getUrl($parts[\'url_path\'])', $hero);
        self::assertStringContainsString("'kicker' => \$translateDefaultCopy(\$default['kicker_zh'], \$default['kicker_en'])", $hero);
        self::assertStringContainsString('data-tone="<?= $esc($tone) ?>"', $hero);
        self::assertStringContainsString('.slide.active .slide-subtitle', $hero);
        self::assertStringNotContainsString('backdrop-filter: blur', $hero);
    }

    public function testLayoutSeederUsesChineseSourceCommerceCopy(): void
    {
        $seeder = $this->readProjectFile('Service/DefaultLayoutSeeder.php');

        foreach ([
            'resolveWebsiteBrandTitle()',
            '为日常与仪式感而作',
            '本季精选',
            '新品上市',
            '同风格推荐',
            '最近浏览',
            '热卖精选',
            '为你推荐',
            '继续探索',
            '搭配成套',
            '人气商品',
        ] as $copy) {
            self::assertStringContainsString($copy, $seeder);
        }

        // WO-HP-P1-04：禁止布局种子中英并写；英文靠模块 CSV
        self::assertStringNotContainsString(' · ', $seeder);
        self::assertStringNotContainsString('Seasonal Edit', $seeder);
        self::assertStringNotContainsString('New Arrivals', $seeder);
        self::assertStringNotContainsString('Best Sellers', $seeder);
        self::assertStringNotContainsString('You May Also Like', $seeder);
        self::assertStringNotContainsString('Recently Viewed', $seeder);
        self::assertStringNotContainsString('Recommended', $seeder);
        self::assertStringNotContainsString('Explore More', $seeder);
        self::assertStringNotContainsString('Complete the Look', $seeder);
        self::assertStringNotContainsString('Popular Picks', $seeder);
        self::assertStringNotContainsString('Made for everyday rituals', $seeder);

        self::assertStringNotContainsString('东方衣冠', $seeder);
        self::assertStringNotContainsString('新裳入藏', $seeder);
        self::assertStringNotContainsString('人气汉服', $seeder);
        self::assertStringNotContainsString("'title' => '长安汉服 · Hanfu Atelier'", $seeder);
        self::assertStringNotContainsString('欢迎来到我们的商店', $seeder);
        self::assertStringNotContainsString('发现最新产品和优惠', $seeder);
    }

    public function testEmptyBrandListUsesThemeTextileHeritageCatalog(): void
    {
        $widget = $this->readProjectFile('view/theme/frontend/widgets/content/brand-logos/default.phtml');
        $homepage = $this->readProjectFile('view/theme/frontend/layouts/homepage/default.phtml');

        self::assertStringNotContainsString('\\Weline\\Product\\Service\\TextileHeritageCatalog', $widget);
        self::assertStringContainsString('TextileHeritageCatalog::items()', $widget);
        self::assertStringContainsString('$usesCraftDirectory = $brands === [];', $widget);
        self::assertStringContainsString("'image' => \$item['image']", $widget);
        self::assertStringContainsString("'link' => \$item['link']", $widget);
        self::assertStringNotContainsString('<w:widget type="content" name="textile-heritage"', $homepage);
        self::assertStringContainsString('<w:widget type="content" name="brand-logos"', $homepage);
        self::assertStringContainsString('id="homepage-deals"', $homepage);
        self::assertStringContainsString('<w:widget type="product" name="deals-of-day"', $homepage);
        self::assertStringContainsString('id="homepage-testimonials"', $homepage);
        self::assertStringContainsString('id="homepage-reviews"', $homepage);
        self::assertStringContainsString('id="homepage-videos"', $homepage);
        self::assertStringContainsString('<w:widget type="content" name="image-gallery"', $homepage);
        self::assertStringContainsString('"variant":"looks"', $homepage);
        self::assertStringContainsString('"title":"买家秀"', $homepage);
        self::assertStringContainsString('"cta_link":"/product/543#product-reviews"', $homepage);
        self::assertStringContainsString('detail-03-c2e91b039ebb.jpg', $homepage);
        self::assertStringNotContainsString('"items":[]', $homepage);
        self::assertStringNotContainsString('穿后感言', $homepage);
        self::assertStringContainsString('<w:widget type="testimonial" name="testimonials"', $homepage);
        self::assertStringContainsString('"title":"买家评价"', $homepage);
        self::assertStringContainsString('<w:widget type="video" name="video-carousel"', $homepage);
        self::assertStringContainsString('accept="layout-homepage-videos,video,video-player,video-carousel,content"', $homepage);
        self::assertStringNotContainsString('<w:widget type="video" name="video-player"', $homepage);
        self::assertSame(1, substr_count($homepage, '<w:widget type="testimonial" name="testimonials"'));
        self::assertStringNotContainsString('required default_injection', $homepage);
        self::assertStringContainsString('Weline_Theme::frontend::layouts::homepage::brands', $homepage);

        $heroPosition = strpos($homepage, 'id="homepage-hero"');
        $trustPosition = strpos($homepage, 'id="homepage-trust"');
        $featuredPosition = strpos($homepage, 'id="homepage-featured"');
        $categoriesPosition = strpos($homepage, 'id="homepage-categories"');
        $brandsPosition = strpos($homepage, 'id="homepage-brands"');
        self::assertIsInt($heroPosition);
        self::assertIsInt($trustPosition);
        self::assertIsInt($featuredPosition);
        self::assertIsInt($categoriesPosition);
        self::assertIsInt($brandsPosition);
        self::assertTrue(
            $heroPosition < $trustPosition
            && $trustPosition < $featuredPosition
            && $featuredPosition < $categoriesPosition
            && $categoriesPosition < $brandsPosition,
            'Wave-3 商城节奏：Hero → 信任条 → 精选 → 品类磁贴 → … → 品牌；品牌不得插在货架之前。'
        );
    }

    public function testDefaultFooterLocalizesBrandAndCopyright(): void
    {
        $footer = $this->readProjectFile('view/theme/frontend/widgets/container/footer/default.phtml');

        self::assertStringContainsString('resolveFrontendWordmark', $footer);
        self::assertStringContainsString('SiteBrand', $footer);
        self::assertStringContainsString("\$localizedRights = WidgetI18n::label('保留所有权利')", $footer);
        self::assertStringContainsString("\$localizedSiteLogoText . '. ' . \$localizedRights", $footer);
    }

    public function testBestsellerDemoCatalogIsLimitedToEditorPreview(): void
    {
        $widget = $this->readProjectFile('view/theme/frontend/widgets/product/bestsellers/default.phtml');

        self::assertStringContainsString('if ($products === [] && $isPreviewMode)', $widget);
        self::assertStringContainsString('data-testid="bestsellers-empty"', $widget);
        self::assertStringNotContainsString(
            "if (\$products === []) {\n    \$products = ThemeDemoCatalog::products",
            $widget
        );
    }

    public function testProductLabelsAvoidRepeatedStyleBlocks(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/components/product-label.phtml';
        self::assertFileExists($path);

        $renderer = new class {
            /** @var array<string, mixed> */
            private array $data = [];

            /** @param array<string, mixed> $data */
            public function render(string $path, array $data): string
            {
                $this->data = $data;
                ob_start();
                include $path;

                return (string)ob_get_clean();
            }

            public function getData(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }
        };

        $html = $renderer->render($path, ['kind' => 'new', 'text' => 'NEW'])
            . $renderer->render($path, ['kind' => 'sale', 'text' => 'SALE']);

        self::assertSame(2, substr_count($html, 'class="w-product-label '));
        self::assertSame(0, substr_count($html, '<style>'));
        self::assertStringContainsString(
            'style="background: var(--w-product-label-new-bg',
            $html
        );
        self::assertStringContainsString(
            'style="background: var(--w-product-label-sale-bg',
            $html
        );
    }

    private function readProjectFile(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . ltrim($relativePath, '/');
        self::assertFileExists($path);

        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }
}
