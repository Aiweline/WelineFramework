<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class ProductIdentityCutoverRequestMemoContractTest extends TestCase
{
    public function testCurrentUsesRequestContextRemember(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ProductIdentityCutoverService.php'
        );
        self::assertStringContainsString('REQUEST_SNAPSHOT_KEY', $src);
        self::assertStringContainsString('product.identity_cutover.snapshot.v1', $src);
        self::assertStringContainsString('RequestContext::remember', $src);
        self::assertStringContainsString('publishRequestSnapshot', $src);
    }
}
