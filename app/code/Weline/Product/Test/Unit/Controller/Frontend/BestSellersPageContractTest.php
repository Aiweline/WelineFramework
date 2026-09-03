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
        self::assertStringContainsString("setGet('theme_public_route', 'best-sellers')", $controller);
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
        self::assertStringContainsString('best-sellers-main', $layout);
        self::assertStringContainsString('@meta.name', $layout);
    }

    public function testBestSellersTemplateExposesRankingContracts(): void
    {
        $template = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/frontend/best-sellers/index.phtml',
        );

        self::assertStringContainsString('data-testid="storefront-best-sellers"', $template);
        self::assertStringContainsString('storefront-best-sellers-podium', $template);
        self::assertStringContainsString('storefront-best-sellers-list', $template);
        self::assertStringContainsString('storefront-best-sellers-card', $template);
        self::assertStringContainsString('ProductCardAddToCartParams::fetchDictionary', $template);
        self::assertStringContainsString('ProductCardUrl::splitForTaglib', $template);
        self::assertStringContainsString("\$this->getUrl(\$productLink['url_path'])", $template);
        self::assertStringNotContainsString('href="@url{$route}"', $template);
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
