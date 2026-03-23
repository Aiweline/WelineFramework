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
}
