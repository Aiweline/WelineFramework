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

        self::assertStringContainsString("\$this->layoutType = \$surface['layout_type']", $controller);
        self::assertStringContainsString("setGet('page_type', \$surface['page_type'])", $controller);
        self::assertStringContainsString("setGet('theme_public_route', \$surface['public_route'])", $controller);
        self::assertStringContainsString("assign('showToolbar', false)", $controller);
        self::assertStringContainsString('StorefrontCategoryListingFilter', $controller);
        self::assertStringContainsString("assign('storefront_offers_unfiltered'", $controller);
        self::assertStringContainsString("assign('storefront_listing_sort_options'", $controller);
        self::assertStringContainsString('paginate(', $controller);
        self::assertStringContainsString("assign('storefront_listing_page_options'", $controller);
    }

    public function testCatalogAndCategoryTemplatesExposeListingPager(): void
    {
        $catalog = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/frontend/catalog/index.phtml',
        );
        $category = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/frontend/category/index.phtml',
        );

        self::assertStringContainsString('data-testid="storefront-products-pager"', $catalog);
        self::assertStringContainsString('data-testid="storefront-category-pager"', $category);
        self::assertStringContainsString('storefront_listing_page_options', $catalog);
        self::assertStringContainsString('storefront_listing_page_options', $category);
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

    public function testCatalogDelegatesCartMutationToTheSharedThemePartial(): void
    {
        $template = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/frontend/catalog/index.phtml',
        );

        self::assertStringContainsString('ProductCardAddToCartParams::fetchDictionaryFromOffer', $template);
        self::assertStringContainsString('Weline_Theme::theme/frontend/partials/product/add-to-cart.phtml', $template);
    }

    public function testCartLinkKeepsTheActiveCurrencyAndLocaleRoute(): void
    {
        $template = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/frontend/catalog/index.phtml',
        );

        self::assertStringContainsString('href="@url{\'cart\'}"', $template);
        self::assertStringNotContainsString('href="/cart"', $template);
    }

    public function testCatalogCardsUseWholeItemHitLinkToProductDetail(): void
    {
        $template = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/frontend/catalog/index.phtml',
        );

        self::assertStringContainsString("\$productPath = 'product/' . \$productSlug", $template);
        self::assertStringContainsString("\$productPath = 'product/' . \$productId", $template);
        self::assertStringContainsString("StorefrontOfferDetailQuery::params", $template);
        self::assertStringContainsString('href="@url{$productUrl|$productUrlParams}"', $template);
        self::assertStringContainsString('class="product-storefront__card-hit"', $template);
        self::assertStringContainsString('data-testid="storefront-product-card-link"', $template);
        self::assertStringNotContainsString('class="product-storefront__title-link"', $template);
        self::assertStringNotContainsString('href="/product/', $template);
    }

    public function testProductListLayoutLeavesFiltersSlotForFiltersModuleInjection(): void
    {
        $layout = (string)file_get_contents(
            BP . 'app/code/Weline/Theme/view/theme/frontend/layouts/product_list/default.phtml',
        );

        self::assertStringContainsString('id="list-filters"', $layout);
        self::assertStringContainsString('data-placeholder="list-filters"', $layout);
        self::assertStringContainsString('由 Filters 部件默认注入', $layout);
        self::assertStringNotContainsString(
            "getHook('Weline_Theme::frontend::layouts::product-list::filters-sidebar'",
            $layout,
        );
        self::assertStringContainsString('grid-template-columns: 240px minmax(0, 1fr)', $layout);
        self::assertStringContainsString('max-width: var(--weline-layout-content-max-width, 1400px)', $layout);
        self::assertFileDoesNotExist(
            BP . 'app/code/Weline/Product/view/hooks/Weline_Theme/frontend/layouts/product-list/filters-sidebar.phtml',
        );
    }

    public function testCatalogControllerDispatchesStorefrontOffersFilterEvent(): void
    {
        $controller = (string)file_get_contents(
            BP . 'app/code/Weline/Product/Controller/Frontend/Catalog.php',
        );

        self::assertStringContainsString('Weline_Product::storefront_offers_filter', $controller);
        self::assertStringContainsString('EventsManager', $controller);
        self::assertStringNotContainsString('ObjectManager', $controller);
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
