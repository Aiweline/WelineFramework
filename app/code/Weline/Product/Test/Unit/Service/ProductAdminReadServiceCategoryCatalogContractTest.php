<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class ProductAdminReadServiceCategoryCatalogContractTest extends TestCase
{
    public function testTaxonomyCatalogUsesCatalogQueryConsumer(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ProductAdminReadService.php',
        );
        self::assertStringContainsString('ProductCatalogQueryConsumer', $source);
        self::assertStringContainsString('catalogConsumer->flatRows', $source);
        self::assertStringNotContainsString('$this->categories->listAll', $source);
        self::assertStringContainsString("'categories' => \$this->categoryCatalog", $source);
    }
}
