<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class ProductSearchCategoryScopeServiceTest extends TestCase
{
    public function testServiceReadsCatalogQueryTreeBeforeDemoFallback(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/ProductSearchCategoryScopeService.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('ProductCatalogQueryConsumer', $source);
        self::assertStringContainsString('$this->catalog->tree', $source);
        self::assertStringContainsString('listFromCatalog', $source);
        self::assertStringContainsString('private function demoScopes()', $source);
        self::assertStringNotContainsString('CategoryRepository', $source);
    }
}
