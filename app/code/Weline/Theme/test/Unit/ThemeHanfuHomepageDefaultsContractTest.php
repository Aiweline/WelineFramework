<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class ThemeHanfuHomepageDefaultsContractTest extends TestCase
{
    public function testHomepageMetadataNamesTheHanfuStorefront(): void
    {
        $layout = $this->readProjectFile('view/theme/frontend/layouts/homepage/default.phtml');

        self::assertStringContainsString(
            '@meta.name {default="水墨汉服商城首页"',
            $layout
        );
        self::assertStringContainsString(
            '@param.title {default="云裳汉服 · Hanfu Atelier"',
            $layout
        );
        self::assertStringNotContainsString('default="Homepage Default"', $layout);
        self::assertStringNotContainsString('default="Home"', $layout);
        self::assertStringContainsString('WidgetI18n::label($source)', $layout);
        self::assertStringContainsString('$useEnglishCopy = !$isChineseLocale;', $layout);
        self::assertStringNotContainsString('$this->setData(\'meta_title\'', $layout);
        self::assertStringNotContainsString('$this->setData(\'meta_description\'', $layout);
        self::assertSame(1, substr_count($layout, '<h1 class="w-visually-hidden">'));

        $provider = $this->readProjectFile(
            'extends/module/Weline_Seo/SeoProfileProvider/HanfuHomepageSeoProfileProvider.php'
        );
        self::assertStringContainsString('implements SeoProfileProviderInterface', $provider);
        self::assertStringContainsString('Ink-Wash Hanfu Boutique', $provider);
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
            '\'button_text\' => $translateDefaultCopy(\'浏览系列\', \'Explore collection\')',
            $hero
        );
        self::assertStringNotContainsString('$useEnglishCopy ?', $hero);
    }

    public function testDefaultCategoryGridUsesLocaleCatalogForNonEnglishLocales(): void
    {
        $widget = $this->readProjectFile('view/theme/frontend/widgets/category/category-grid/default.phtml');

        self::assertStringContainsString('$translateDefaultCopy = static function', $widget);
        self::assertStringContainsString(
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

        self::assertSame(3, substr_count($hero, "'link' => '/products'"));
        self::assertStringContainsString('ProductCardUrl::splitForTaglib($slideLink)', $hero);
        self::assertStringContainsString('$this->getUrl($slideLinkParts[\'url_path\'])', $hero);
    }

    public function testLayoutSeederUsesHanfuSpecificBilingualCommerceCopy(): void
    {
        $seeder = $this->readProjectFile('Service/DefaultLayoutSeeder.php');

        foreach ([
            '云裳汉服 · Hanfu Atelier',
            '东方衣冠，为日常与礼仪而作 · Made for modern rituals',
            '本季精选 · Seasonal Edit',
            '新裳入藏 · New Arrivals',
            '同风格推荐 · You May Also Like',
            '最近浏览 · Recently Viewed',
            '典藏热选 · Best Sellers',
            '为你推荐 · Recommended',
            '按形制继续探索 · Explore More',
            '搭配成套 · Complete the Look',
            '人气汉服 · Popular Hanfu',
        ] as $copy) {
            self::assertStringContainsString($copy, $seeder);
        }

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
        self::assertStringContainsString('<w:widget type="content" name="textile-heritage"', $homepage);
        self::assertStringContainsString('textile-heritage', $homepage);
        self::assertStringContainsString('default_injections', $homepage);
        self::assertStringNotContainsString('<w:widget type="content" name="brand-logos"', $homepage);
        self::assertStringContainsString('Weline_Theme::frontend::layouts::homepage::brands', $homepage);
    }

    public function testDefaultFooterLocalizesBrandAndCopyright(): void
    {
        $footer = $this->readProjectFile('view/theme/frontend/widgets/container/footer/default.phtml');

        self::assertStringContainsString(
            '$localizedSiteLogoText = trim((string)__($defaultSiteLogoText))',
            $footer
        );
        self::assertStringContainsString(
            '$localizedSiteLogoText . \'. \' . (string)__(\'保留所有权利\')',
            $footer
        );
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
