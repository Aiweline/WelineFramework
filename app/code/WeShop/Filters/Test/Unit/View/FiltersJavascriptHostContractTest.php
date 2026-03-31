<?php

declare(strict_types=1);

namespace WeShop\Filters\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class FiltersJavascriptHostContractTest extends TestCase
{
    public function testFiltersJavascriptSupportsDefaultThemeProductHosts(): void
    {
        $script = file_get_contents(__DIR__ . '/../../../view/statics/js/filters.js');

        $this->assertIsString($script);
        $this->assertStringContainsString(".products-grid, #product-grid, .category-products-grid", $script);
        $this->assertStringContainsString(".products-count, .filter-result-count", $script);
        $this->assertStringContainsString(".category-products-grid .product-card a[href*=\"/product/\"]", $script);
    }
}
