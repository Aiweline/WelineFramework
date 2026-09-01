<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\StorefrontProductCatalogEnrichment;

final class StorefrontProductCatalogLocaleCopyTest extends TestCase
{
    public function testLibySkuHasEnglishLocaleCopy(): void
    {
        $copy = StorefrontProductCatalogEnrichment::localeCopy('WEB-LIBY-LAUNDRY-3KG', 'en_US');
        self::assertIsArray($copy);
        self::assertStringContainsString('Liby', (string)($copy['name'] ?? ''));
        self::assertSame('Liby', $copy['attributes']['brand'] ?? null);
    }

    public function testUnknownLocaleReturnsNull(): void
    {
        self::assertNull(StorefrontProductCatalogEnrichment::localeCopy('WEB-LIBY-LAUNDRY-3KG', ''));
    }
}
