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
        self::assertStringContainsString('readNameMap', $source);
        self::assertStringContainsString('State::getLangLocal()', $source);
        self::assertStringContainsString(
            'return $this->applyLocalizedNames($websiteId, $index, $locale);',
            $source,
        );
    }
}
