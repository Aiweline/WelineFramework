<?php

declare(strict_types=1);

namespace WeShop\Compare\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

class CompareServiceArchitectureTest extends TestCase
{
    public function testCompareServiceLoadsProductsThroughQueryProvider(): void
    {
        $service = file_get_contents(__DIR__ . '/../../../Service/CompareService.php');

        $this->assertIsString($service);
        $this->assertStringContainsString("w_query('product', 'getProductByIds'", $service);
        $this->assertStringNotContainsString('use WeShop\\Product\\Model\\Product;', $service);
        $this->assertStringNotContainsString('ObjectManager::getInstance(Product::class)', $service);
    }
}
