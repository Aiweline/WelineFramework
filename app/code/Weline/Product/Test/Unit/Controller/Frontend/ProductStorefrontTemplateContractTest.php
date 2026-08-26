<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Controller\Frontend;

use PHPUnit\Framework\TestCase;

final class ProductStorefrontTemplateContractTest extends TestCase
{
    public function testProductControllerSelectsTheProductListThemeLayout(): void
    {
        $controller = (string)file_get_contents(
            BP . 'app/code/Weline/Product/Controller/Frontend/Catalog.php',
        );

        self::assertStringContainsString('$this->layoutType = \'product_list\'', $controller);
        self::assertStringContainsString("setGet('page_type', 'products')", $controller);
        self::assertStringContainsString("assign('showToolbar', false)", $controller);
        self::assertStringContainsString('StorefrontCategoryListingFilter', $controller);
        self::assertStringContainsString("assign('storefront_offers_unfiltered'", $controller);
        self::assertStringContainsString("assign('storefront_listing_sort_options'", $controller);
    }

    public function testProductControllerDoesNotReachIntoCartConcreteServices(): void
    {
        $controller = (string)file_get_contents(
            BP . 'app/code/Weline/Product/Controller/Frontend/Catalog.php',
        );

        self::assertStringNotContainsString('Weline\\Cart', $controller);
        self::assertStringNotContainsString('ObjectManager', $controller);
        self::assertStringNotContainsString('Cookie::', $controller);
    }

    public function testBrowserObtainsGuestCartTokenThroughThePublishedCartApi(): void
    {
        $template = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/frontend/catalog/index.phtml',
        );

        self::assertStringContainsString("Weline.Api.resource('cart')", $template);
        self::assertStringContainsString("issueGuestToken", $template);
        self::assertStringContainsString("const guestTokenStorageKey = 'weline.cart.guest_token'", $template);
        self::assertStringContainsString('window.sessionStorage.getItem(guestTokenStorageKey)', $template);
        self::assertStringContainsString('window.sessionStorage.setItem(guestTokenStorageKey, guestToken)', $template);
    }

    public function testCartLinkKeepsTheActiveCurrencyAndLocaleRoute(): void
    {
        $template = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/frontend/catalog/index.phtml',
        );

        self::assertStringContainsString('$this->getUrl(\'cart\')', $template);
        self::assertStringNotContainsString('href="/cart"', $template);
    }

    public function testCatalogCardsUseWholeItemHitLinkToProductDetail(): void
    {
        $template = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/frontend/catalog/index.phtml',
        );

        self::assertStringContainsString(
            "\$this->getUrl('product/' . \$productSlug)",
            $template,
        );
        self::assertStringContainsString(
            "\$this->getUrl('product/' . \$productId)",
            $template,
        );
        self::assertStringContainsString('class="product-storefront__card-hit"', $template);
        self::assertStringContainsString('data-testid="storefront-product-card-link"', $template);
        self::assertStringNotContainsString('class="product-storefront__title-link"', $template);
        self::assertStringNotContainsString('href="/product/', $template);
    }

    public function testProductListLayoutUsesRuntimeFiltersSidebarHook(): void
    {
        $layout = (string)file_get_contents(
            BP . 'app/code/Weline/Theme/view/theme/frontend/layouts/product_list/default.phtml',
        );

        self::assertStringContainsString(
            "getHook('Weline_Theme::frontend::layouts::product-list::filters-sidebar', true)",
            $layout,
        );
        self::assertStringNotContainsString(
            'data-placeholder="list-filters"',
            $layout,
        );
        self::assertStringContainsString('grid-template-columns: 240px minmax(0, 1fr)', $layout);
        self::assertStringContainsString('max-width: var(--weline-layout-content-max-width, 1400px)', $layout);
    }

    public function testProductsFiltersSidebarPrefersCurrentListingPath(): void
    {
        $hook = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/hooks/Weline_Theme/frontend/layouts/product-list/filters-sidebar.phtml',
        );

        self::assertStringContainsString('categories|products|product-list', $hook);
        self::assertStringContainsString("\$listingUrl = '/products'", $hook);
    }

    public function testProductsFiltersSidebarExposesCategoryAndPriceContracts(): void
    {
        $hook = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/hooks/Weline_Theme/frontend/layouts/product-list/filters-sidebar.phtml',
        );

        self::assertStringContainsString('data-testid="storefront-products-filter"', $hook);
        self::assertStringContainsString('storefront-products-dept-root', $hook);
        self::assertStringContainsString('storefront-products-price-filter', $hook);
        self::assertStringContainsString('childrenOf($websiteId, 0)', $hook);
        self::assertStringContainsString('priceBucketsWithCounts', $hook);
    }

    public function testBrowserCartMutationDoesNotSubmitAClientOwnedScope(): void
    {
        $template = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/frontend/catalog/index.phtml',
        );

        self::assertStringNotContainsString('scope:', $template);
        self::assertStringNotContainsString('website_id:', $template);
        self::assertStringNotContainsString('store_code:', $template);
    }
}
