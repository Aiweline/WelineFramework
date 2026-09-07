<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class StorefrontCategoryTreeIndexLocalizedNameContractTest extends TestCase
{
    public function testForWebsiteAppliesEavLocalizedNamesAfterCacheLoad(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontCategoryTreeIndex.php',
        );

        self::assertStringContainsString('ProductCategoryAttributeService', $source);
        self::assertStringContainsString('applyLocalizedNames', $source);
        self::assertStringContainsString('State::getLangLocal()', $source);
        self::assertStringContainsString('rememberForRequest', $source);
        self::assertStringContainsString(
            'fn(): array => $this->applyLocalizedNames($websiteId, $index, $locale)',
            $source,
        );
        self::assertStringContainsString("'storefront.category_tree.urls'", $source);
        self::assertStringContainsString("['categories' => \\count(\$index['by_id'])]", $source);
    }
}
