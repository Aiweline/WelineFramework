<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class PromotionStorefrontPageServiceAssemblerPricingContractTest extends TestCase
{
    public function testShelfPricingTrustsAssemblerAndBlocksFilterEmptyCatalogFallback(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/PromotionStorefrontPageService.php';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);

        self::assertStringContainsString('product_pick_mode', $src);
        self::assertStringContainsString('PICK_MODE_FILTER', $src);
        self::assertStringContainsString('Assembler is authoritative with PDP/cart', $src);
        self::assertStringContainsString("catalog_price_minor'] ?? \$item['unit_price_minor'", $src);
        self::assertStringContainsString("'has_deal' => false",
            $src,
        );
        self::assertStringContainsString('keepDealMarkedItemsOnly', $src);
        self::assertStringContainsString('listHubStorefrontProductIds', $src);
        self::assertStringNotContainsString(
            'Shelf themes may list price-band products while selection is empty; keep page deal + campaign name.',
            $src,
        );
    }
}
