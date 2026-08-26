<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class ProductCatalogQueryConsumerTest extends TestCase
{
    public function testConsumerCallsCatalogTreeQuery(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ProductCatalogQueryConsumer.php',
        );
        self::assertStringContainsString("w_query('catalog', 'tree'", $source);
        self::assertStringContainsString("'space' => 'product'", $source);
        self::assertStringContainsString("'scope_level' => 'website'", $source);
    }
}
