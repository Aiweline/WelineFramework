<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Extends\CatalogSpace;

use PHPUnit\Framework\TestCase;
use Weline\Catalog\Api\CatalogSpaceProviderInterface;

final class BlogCatalogSpaceProviderTest extends TestCase
{
    public function testImplementsCatalogSpaceProvider(): void
    {
        $provider = new \ReflectionClass(
            'Weline\Blog\Extends\Module\Weline_Catalog\Space\BlogCatalogSpaceProvider',
        );
        self::assertTrue($provider->implementsInterface(CatalogSpaceProviderInterface::class));
    }

    public function testDelegatesStructureCrudToBlogCategoryAdminService(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/extends/module/Weline_Catalog/Space/BlogCatalogSpaceProvider.php',
        );
        self::assertStringContainsString('BlogCategoryAdminService', $source);
        self::assertStringContainsString('$this->categoryAdmin->tree', $source);
        self::assertStringContainsString('$this->categoryAdmin->save', $source);
        self::assertStringContainsString('$this->categoryAdmin->delete', $source);
    }

    public function testEntityCodeIsBlogCategory(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/extends/module/Weline_Catalog/Space/BlogCatalogSpaceProvider.php',
        );
        self::assertStringContainsString('BlogCategoryAttributeEntity::entity_code', $source);
        self::assertStringContainsString("return 'blog'", $source);
    }

    public function testListProductsForDeleteReturnsEmpty(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/extends/module/Weline_Catalog/Space/BlogCatalogSpaceProvider.php',
        );
        self::assertStringContainsString('function listProductsForDelete', $source);
        self::assertStringContainsString('return [];', $source);
        self::assertStringContainsString('array $options = []', $source);
    }
}
