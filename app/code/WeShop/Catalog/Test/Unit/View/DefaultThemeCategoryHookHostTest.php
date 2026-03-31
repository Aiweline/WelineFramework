<?php

declare(strict_types=1);

namespace WeShop\Catalog\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class DefaultThemeCategoryHookHostTest extends TestCase
{
    public function testCategoryPageHostsCanonicalCatalogAndFilterHooks(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../../../../design/WeShop/default/frontend/pages/catalog/category.phtml');
        $this->assertIsString($template);

        $expectedHooks = [
            'Weline_Theme::frontend::layouts::category::filters-sidebar',
            'WeShop_Filters::frontend::partials::filters::container',
            'WeShop_Filters::frontend::partials::filters::header',
            'WeShop_Filters::frontend::partials::filters::applied',
            'WeShop_Filters::frontend::partials::filters::footer',
            'WeShop_Catalog::frontend::layouts::category::products-content',
        ];

        foreach ($expectedHooks as $hook) {
            $this->assertStringContainsString($hook, $template);
        }

        $this->assertStringContainsString('category-products product-list-container', $template);
        $this->assertStringContainsString('data-weshop-filter-product-host', $template);
        $this->assertStringContainsString('filter-result-count products-count', $template);
        $this->assertStringContainsString('id="product-grid"', $template);
        $this->assertStringContainsString('products-grid', $template);
    }

    public function testProductListingLayoutsHostCanonicalCatalogAndFilterHooks(): void
    {
        foreach ([1, 2, 3, 4] as $variant) {
            $template = file_get_contents(__DIR__ . "/../../../../../../design/WeShop/default/frontend/layouts/product_list/product_listing_page_{$variant}.phtml");
            $this->assertIsString($template);

            $this->assertStringContainsString('Weline_Theme::frontend::layouts::category::filters-sidebar', $template);
            $this->assertStringContainsString('WeShop_Filters::frontend::partials::filters::container', $template);
            $this->assertStringContainsString('WeShop_Catalog::frontend::layouts::category::products-content', $template);
        }
    }

    public function testMotorThemeCategoryHostsCanonicalFilterContainerFallback(): void
    {
        $templates = [
            __DIR__ . '/../../../../../../design/WeShop/motor/frontend/pages/catalog/category.phtml',
            __DIR__ . '/../../../../../../design/WeShop/motor/frontend/layouts/category/default.phtml',
        ];

        foreach ($templates as $templatePath) {
            $template = file_get_contents($templatePath);
            $this->assertIsString($template);
            $this->assertStringContainsString("WeShop_Filters::frontend::partials::filters::container", $template);
            $this->assertStringContainsString("WeShop_Filters::templates/Frontend/filters.phtml", $template);
            $this->assertStringContainsString('$useCanonicalFilterContainer', $template);
        }
    }

    public function testCanonicalCategoryContentTemplateExposesAjaxRenderableProductsGrid(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../view/templates/Frontend/Category/content.phtml');
        $this->assertIsString($template);
        $this->assertStringContainsString('category-products-grid products-grid', $template);
        $this->assertStringContainsString('data-weshop-filter-products-grid', $template);
        $this->assertStringContainsString('data-browse-product-ids', $template);
    }
}
