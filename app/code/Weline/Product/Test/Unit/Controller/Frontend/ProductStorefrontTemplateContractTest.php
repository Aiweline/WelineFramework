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
        self::assertStringNotContainsString('theme_public_route', $controller);
        self::assertStringContainsString("assign('showToolbar', false)", $controller);
        self::assertStringContainsString('StorefrontCategoryListingFilter', $controller);
        self::assertStringContainsString('StorefrontListingPager', $controller);
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
        // Heavy-locale packs only prefetch template literals; dynamic __($var) stays Chinese.
        self::assertStringContainsString("__('默认排序')", $catalog);
        self::assertStringContainsString("__('价格从低到高')", $catalog);
        self::assertStringContainsString("__('价格从高到低')", $catalog);
        self::assertStringContainsString("__('名称 A-Z')", $catalog);
        self::assertStringContainsString("__('默认排序')", $category);
        self::assertStringContainsString('storefront_listing_page_options', $catalog);
        self::assertStringContainsString('storefront_listing_page_options', $category);
        self::assertStringContainsString('is-ellipsis', $catalog);
        self::assertStringContainsString('is-ellipsis', $category);
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

    public function testCatalogDelegatesCartMutationToUnifiedProductCard(): void
    {
        $template = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/frontend/catalog/index.phtml',
        );

        self::assertStringContainsString('ProductCardRenderer::projectFromOffers', $template);
        self::assertStringContainsString("'show_add_to_cart' => true", $template);
    }

    public function testCartLinkKeepsTheActiveCurrencyAndLocaleRoute(): void
    {
        $template = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/frontend/catalog/index.phtml',
        );

        self::assertStringContainsString('href="@url{\'cart\'}"', $template);
        self::assertStringNotContainsString('href="/cart"', $template);
    }

    public function testCatalogCardsUseUnifiedProductCardTag(): void
    {
        $template = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/frontend/catalog/index.phtml',
        );

        self::assertStringContainsString('ProductCardRenderer::projectFromOffers', $template);
        self::assertStringContainsString("'show_sku' => true", $template);
        self::assertStringContainsString('weline-product-card-shelf', $template);
        self::assertStringNotContainsString('<w:product:card', $template);
        self::assertStringNotContainsString('class="product-storefront__card-hit"', $template);
        self::assertStringNotContainsString('product-storefront__card product-card', $template);
    }

    public function testProductListLayoutLeavesFiltersSlotForFiltersModuleInjection(): void
    {
        $layout = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/theme/frontend/layouts/products/default.phtml',
        );

        self::assertStringContainsString('id="list-filters"', $layout);
        self::assertStringContainsString('data-placeholder="list-filters"', $layout);
        self::assertStringContainsString('由 Filters 部件默认注入', $layout);
        self::assertStringNotContainsString(
            "getHook('Weline_Theme::frontend::layouts::products::filters-sidebar'",
            $layout,
        );
        self::assertStringContainsString('grid-template-columns: 240px minmax(0, 1fr)', $layout);
        self::assertStringContainsString('width: min(100%, var(--weline-layout-content-max-width));', $layout);
        // No widget override: keep controller content / live catalog fallback (editor clears meta.content).
        self::assertStringContainsString('<elseif condition="content"/>', $layout);
        self::assertStringContainsString('Weline_Product::templates/frontend/catalog/index.phtml', $layout);
        self::assertStringContainsString('publishedListingCandidates', $layout);
        self::assertFileDoesNotExist(
            BP . 'app/code/Weline/Product/view/hooks/Weline_Theme/frontend/layouts/products/filters-sidebar.phtml',
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

    public function testProductEventSpecDeclaresStorefrontOffersFilter(): void
    {
        $spec = require BP . 'app/code/Weline/Product/event.php';
        self::assertIsArray($spec);
        self::assertArrayHasKey('Weline_Product::storefront_offers_filter', $spec);
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
