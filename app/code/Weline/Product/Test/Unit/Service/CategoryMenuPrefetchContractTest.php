<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\StorefrontAllMenuCategoryTreeService;

/** R2: category menu tree primes prefetchPolicy before rememberPolicy. */
final class CategoryMenuPrefetchContractTest extends TestCase
{
    public function testNavTreePrefetchesCategoryMenuPolicyKey(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontAllMenuCategoryTreeService.php',
        );
        self::assertStringContainsString('prefetchPolicy(', $src);
        self::assertStringContainsString('categoryMenuPolicy()', $src);
        self::assertStringContainsString('rememberPolicy(', $src);
        $prefetchPos = \strpos($src, 'prefetchPolicy(');
        $rememberPos = \strpos($src, 'rememberPolicy(');
        self::assertNotFalse($prefetchPos);
        self::assertNotFalse($rememberPos);
        self::assertLessThan($rememberPos, $prefetchPos);
        self::assertTrue(class_exists(StorefrontAllMenuCategoryTreeService::class));
    }
}
