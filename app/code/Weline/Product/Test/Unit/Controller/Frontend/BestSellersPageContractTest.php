<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Controller\Frontend;

use PHPUnit\Framework\TestCase;

final class BestSellersPageContractTest extends TestCase
{
    public function testRouterMapsBestSellersAliases(): void
    {
        $router = (string)file_get_contents(
            BP . 'app/code/Weline/Product/Controller/Router.php',
        );

        self::assertStringContainsString("BEST_SELLERS_ROUTE = 'weline_product/frontend/best-sellers'", $router);
        self::assertStringContainsString("'best-sellers', 'bestsellers', 'best_sellers'", $router);
    }

    public function testControllerSelectsBestSellersLayout(): void
    {
        $controller = (string)file_get_contents(
            BP . 'app/code/Weline/Product/Controller/Frontend/BestSellers.php',
        );

        self::assertStringContainsString("\$this->layoutType = 'best_sellers'", $controller);
        self::assertStringNotContainsString("theme_public_route", $controller);
        self::assertStringContainsString('bestSellerCards', $controller);
        self::assertStringContainsString("Weline_Product::templates/frontend/best-sellers/index.phtml", $controller);
    }

    public function testModuleContributesBestSellersLayout(): void
    {
        $layout = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/theme/frontend/layouts/best_sellers/default.phtml',
        );

        self::assertStringContainsString('data-layout="best_sellers"', $layout);
        self::assertStringContainsString('data-testid="storefront-best-sellers-layout"', $layout);
        self::assertStringContainsString('best-sellers-hero', $layout);
        self::assertStringContainsString('exclusive="true"', $layout);
        self::assertStringContainsString('best-sellers-main', $layout);
        self::assertStringContainsString('<w:widget type="banner" name="best-sellers-hero"', $layout);
        self::assertStringNotContainsString(
            'accept="layout-best-sellers-content,layout-default-content,content,product-list,product-grid,bestsellers,product-carousel,best-sellers-hero,banner"',
            $layout
        );
        self::assertStringContainsString('@meta.name', $layout);
    }

    public function testBestSellersHeroWidgetIsRegisteredAndConfigurable(): void
    {
        $path = BP . 'app/code/Weline/Product/extends/module/Weline_Widget/Weline_Product/widget.php';
        self::assertFileExists($path);
        /** @var array<string, mixed> $widgets */
        $widgets = require $path;
        $widget = $widgets['best-sellers-hero'] ?? [];
        self::assertSame('best-sellers-hero', $widget['code'] ?? null);
        self::assertSame(
            'Weline_Product::templates/frontend/widgets/best-sellers-hero.phtml',
            $widget['template'] ?? null
        );
        self::assertArrayNotHasKey('default_injections', $widget);
        self::assertArrayHasKey('background_image', $widget['params'] ?? []);
        self::assertSame('media_image', $widget['params']['background_image']['type'] ?? null);
        self::assertSame('1920/400', $widget['params']['background_image']['media_options']['aspect_ratio'] ?? null);

        $tpl = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/frontend/widgets/best-sellers-hero.phtml',
        );
        self::assertStringContainsString('@widget.code {best-sellers-hero}', $tpl);
        self::assertStringContainsString('data-testid="storefront-best-sellers-hero"', $tpl);
        self::assertStringContainsString('background_image', $tpl);
        self::assertStringContainsString('count_template', $tpl);
        self::assertStringContainsString('LegacyMediaUrl::sanitize', $tpl);
        self::assertStringContainsString('storefront_best_sellers_count', $tpl);
    }

    public function testBestSellersTemplateUsesUnifiedProductCard(): void
    {
        $template = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/frontend/best-sellers/index.phtml',
        );

        self::assertStringContainsString('data-testid="storefront-best-sellers"', $template);
        self::assertStringContainsString('storefront-best-sellers-grid', $template);
        self::assertStringContainsString('storefront-best-sellers-card', $template);
        self::assertStringContainsString('best-sellers-page__rank', $template);
        self::assertStringContainsString('<w:product:card', $template);
        self::assertStringNotContainsString('best-sellers-hero.phtml', $template);
        self::assertStringContainsString('ProductCardRenderer::emitStylesheetLinkOnce()', $template);
        self::assertStringContainsString('weline-product-card-shelf', $template);
        self::assertStringContainsString('ProductCardUrl::splitForTaglib', $template);
        self::assertStringContainsString('show-sku="true"', $template);
        self::assertStringNotContainsString('best-sellers-page__hero', $template);
        self::assertStringNotContainsString('storefront-best-sellers-podium', $template);
        self::assertStringNotContainsString('ProductCardAddToCartParams::fetchDictionary', $template);
        self::assertStringNotContainsString('add-to-cart.phtml', $template);
    }

    public function testControllerPublishesBestSellerCountForHeroWidget(): void
    {
        $controller = (string)file_get_contents(
            BP . 'app/code/Weline/Product/Controller/Frontend/BestSellers.php',
        );
        self::assertStringContainsString("setData('storefront_best_sellers_count'", $controller);
    }

    public function testWidgetCatalogExposesBestSellerCards(): void
    {
        $service = (string)file_get_contents(
            BP . 'app/code/Weline/Product/Service/StorefrontProductWidgetCatalog.php',
        );

        self::assertStringContainsString('function bestSellerCards(int $limit = 24)', $service);
        self::assertStringContainsString("'sales_count'", $service);
        self::assertStringContainsString("'rank'", $service);
    }
}
