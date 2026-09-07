<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Controller\Frontend;

use PHPUnit\Framework\TestCase;

final class ProductCategoryTemplateContractTest extends TestCase
{
    public function testCategoryControllerAppliesListingFilterAndKeepsUnfilteredOffers(): void
    {
        $controller = (string)file_get_contents(
            BP . 'app/code/Weline/Product/Controller/Frontend/Category.php',
        );

        self::assertStringContainsString('StorefrontCategoryListingFilter', $controller);
        self::assertStringContainsString("assign('storefront_offers_unfiltered'", $controller);
        self::assertStringContainsString("assign('storefront_listing_sort_options'", $controller);
        self::assertStringContainsString("assign('storefront_category_siblings'", $controller);
        self::assertStringContainsString("assign('storefront_category_tree'", $controller);
        self::assertStringContainsString("assign('storefront_category_active_path_ids'", $controller);
        self::assertStringContainsString("setGet('path', \$routePath)", $controller);
        self::assertStringContainsString('$this->layoutType = \'category\'', $controller);
        self::assertStringContainsString("\$productIds = \$page['product_ids'];", $controller);
        self::assertStringContainsString('$productIds === []', $controller);
        self::assertStringContainsString('$includeListingDetails = false;', $controller);
        self::assertStringContainsString('getQueryParams()', $controller);
        self::assertStringContainsString("str_starts_with(\$queryKey, 'af_')", $controller);
        self::assertStringContainsString(
            'publishedOffersForProductIds($productIds, 120, $includeListingDetails)',
            $controller,
        );
        self::assertStringContainsString('Weline_Product::storefront_offers_filter', $controller);
        self::assertStringNotContainsString('ObjectManager', $controller);
    }

    public function testCategoryTemplateExposesToolbarAndGridContracts(): void
    {
        $template = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/templates/frontend/category/index.phtml',
        );

        self::assertStringContainsString('data-testid="storefront-category-toolbar"', $template);
        self::assertStringContainsString('data-testid="storefront-category-sort"', $template);
        self::assertStringContainsString('data-testid="storefront-category-grid"', $template);
        self::assertStringContainsString('amz-plp__results-bar', $template);
        self::assertStringContainsString('<w:product:card', $template);
        self::assertStringContainsString('ProductCardRenderer::fromStorefrontOffer', $template);
        self::assertStringContainsString('show-sku="true"', $template);
        self::assertStringContainsString('weline-product-card-shelf', $template);
        self::assertStringContainsString('storefront_category_breadcrumbs', $template);
        self::assertStringNotContainsString('amz-card product-card', $template);
        self::assertStringNotContainsString('ProductCardAddToCartParams::fetchDictionaryFromOffer', $template);
        self::assertStringNotContainsString("button.textContent = '", $template);
        self::assertStringNotContainsString('ObjectManager', $template);
    }

    public function testCategoryLayoutLeavesFiltersSlotForFiltersModuleInjection(): void
    {
        $layout = (string)file_get_contents(
            BP . 'app/code/Weline/Theme/view/theme/frontend/layouts/category/default.phtml',
        );

        self::assertStringContainsString('id="category-filters"', $layout);
        self::assertStringContainsString('data-placeholder="category-filters"', $layout);
        self::assertStringContainsString('由 Filters 部件默认注入', $layout);
        self::assertStringNotContainsString(
            "getHook('Weline_Theme::frontend::layouts::category::filters-sidebar'",
            $layout,
        );
        self::assertStringNotContainsString(
            '<w:widget type="sidebar" name="category-filters"/>',
            $layout,
        );
        self::assertFileDoesNotExist(
            BP . 'app/code/Weline/Product/view/hooks/Weline_Theme/frontend/layouts/category/filters-sidebar.phtml',
        );
    }
}
