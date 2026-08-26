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
        self::assertStringContainsString('amz-card', $template);
        self::assertStringContainsString('class="amz-card__hit"', $template);
        self::assertStringContainsString('data-testid="storefront-category-product-card-link"', $template);
        self::assertStringContainsString("\$this->getUrl('product/' . \$productId)", $template);
        self::assertStringContainsString('storefront_category_breadcrumbs', $template);
        self::assertStringNotContainsString('ObjectManager', $template);
    }

    public function testCategoryLayoutUsesRuntimeFiltersHookInsteadOfCompileBakedWidget(): void
    {
        $layout = (string)file_get_contents(
            BP . 'app/code/Weline/Theme/view/theme/frontend/layouts/category/default.phtml',
        );

        self::assertStringContainsString(
            "getHook('Weline_Theme::frontend::layouts::category::filters-sidebar', true)",
            $layout,
        );
        self::assertStringNotContainsString(
            '<w:widget type="sidebar" name="category-filters"/>',
            $layout,
        );
    }

    public function testFiltersSidebarPrefersAssignedCategoryData(): void
    {
        $hook = (string)file_get_contents(
            BP . 'app/code/Weline/Product/view/hooks/Weline_Theme/frontend/layouts/category/filters-sidebar.phtml',
        );

        self::assertStringContainsString("getData('storefront_category')", $hook);
        self::assertStringContainsString('amz-filter', $hook);
        self::assertStringContainsString('buildListingUrl', $hook);
        self::assertStringContainsString('priceBucketsWithCounts', $hook);
        self::assertStringContainsString('storefront-category-dept-root', $hook);
        self::assertStringContainsString('storefront-category-dept-tree', $hook);
        self::assertStringContainsString('storefront-category-dept-node', $hook);
        self::assertStringContainsString("getData('storefront_category_tree')", $hook);
        self::assertStringContainsString("getData('storefront_category_active_path_ids')", $hook);
        self::assertStringContainsString('nestedRoots', $hook);
        self::assertStringContainsString('/categories', $hook);
        self::assertStringContainsString('$tree', $hook);
    }
}
