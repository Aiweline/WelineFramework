<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\ProductSearchProjectionMutationCoordinator;

final class ProductPublicUrlChangeTest extends TestCase
{
    public function testCoordinatorEmitsProjectionImpactContract(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/ProductSearchProjectionMutationCoordinator.php');
        self::assertStringContainsString("'namespaces' => [\$catalogNamespace]", $src);
        self::assertStringContainsString("'urls' => array_column(\$currentUrls, 'loc')", $src);
        self::assertStringContainsString("'previous_urls' => array_column(\$previousUrls, 'loc')", $src);
        self::assertStringContainsString('\\w_changed($change)', $src);
        self::assertSame('product_search_projection', ProductSearchProjectionMutationCoordinator::RESOURCE_TYPE);
    }

    public function testCoordinatorDoesNotBypassFpcViaBusinessInvalidator(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/ProductSearchProjectionMutationCoordinator.php');
        self::assertStringContainsString('\\w_changed($change)', $src);
        self::assertStringNotContainsString('ProductStorefrontCacheInvalidator', $src);
        self::assertStringNotContainsString('product_storefront_fpc_', $src);
    }
}
