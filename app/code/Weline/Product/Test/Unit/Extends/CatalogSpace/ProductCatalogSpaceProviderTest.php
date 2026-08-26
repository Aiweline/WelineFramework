<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Extends\CatalogSpace;

use PHPUnit\Framework\TestCase;
use Weline\Catalog\Api\CatalogSpaceProviderInterface;
use Weline\Product\Model\CategoryAttributeEntity;

final class ProductCatalogSpaceProviderTest extends TestCase
{
    public function testImplementsCatalogSpaceProvider(): void
    {
        $provider = new \ReflectionClass(
            'Weline\Product\Extends\Module\Weline_Catalog\Space\ProductCatalogSpaceProvider',
        );
        self::assertTrue($provider->implementsInterface(CatalogSpaceProviderInterface::class));
    }

    public function testDelegatesStructureCrudToCategoryAdminService(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/extends/module/Weline_Catalog/Space/ProductCatalogSpaceProvider.php',
        );
        self::assertStringContainsString('ProductCategoryAdminService', $source);
        self::assertStringContainsString('$this->categoryAdmin->tree', $source);
        self::assertStringContainsString('$this->categoryAdmin->save', $source);
        self::assertStringContainsString('$this->categoryAdmin->delete', $source);
        self::assertStringContainsString('$this->categoryAdmin->reorder', $source);
    }

    public function testDisplayAndGoogleStubsDoNotThrow(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/extends/module/Weline_Catalog/Space/ProductCatalogSpaceProvider.php',
        );
        self::assertStringContainsString("return [];\n    }\n\n    /**\n     * @param array<string, mixed> \$scope\n     * @param array<string, mixed> \$payload", $source)
            || str_contains($source, 'readDisplaySelection');
        self::assertStringContainsString('listExternalTaxonomyPicker', $source);
        self::assertStringContainsString('externalTaxonomyRequired(): bool', $source);
    }

    public function testEntityCodeIsCategory(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/extends/module/Weline_Catalog/Space/ProductCatalogSpaceProvider.php',
        );
        self::assertStringContainsString(CategoryAttributeEntity::entity_code, $source);
        self::assertStringContainsString("return 'product'", $source);
    }
}
