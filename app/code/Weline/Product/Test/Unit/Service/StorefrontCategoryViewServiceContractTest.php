<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class StorefrontCategoryViewServiceContractTest extends TestCase
{
    public function testCategoryPageCollectsLinksFromCategoryDescendantsInOneBatch(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontCategoryViewService.php',
        );

        self::assertStringContainsString('collectCategoryProductIds', $source);
        self::assertStringContainsString('$this->tree->forWebsite($websiteId)', $source);
        self::assertStringContainsString('$this->categoryLinks->listByCategoryIds($websiteId, $categoryIds)', $source);
        self::assertStringContainsString('$queue = [$categoryId];', $source);
        self::assertStringContainsString("'product_ids' => \$productIds", $source);
    }
}
