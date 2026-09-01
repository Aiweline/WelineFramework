<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class ProductCategoryAdminServiceDedupeContractTest extends TestCase
{
    public function testFindSiblingAndDedupeUseEavLocalizedNames(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ProductCategoryAdminService.php',
        );

        self::assertStringContainsString('findSiblingIdByLocalizedName', $source);
        self::assertStringContainsString('dedupeSiblingsByLocalizedName', $source);
        self::assertStringContainsString('readNameMap', $source);
    }
}
