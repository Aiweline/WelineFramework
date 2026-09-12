<?php

declare(strict_types=1);

namespace Weline\Catalog\Test\Unit\Query;

use PHPUnit\Framework\TestCase;

final class CatalogCategoryAdminQueryProviderTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\function_exists('__')) {
            require_once dirname(__DIR__) . '/bootstrap.php';
        }
    }

    public function testDescriptorUsesCatalogCategoryAdminAcl(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3)
            . '/extends/module/Weline_Framework/Query/CatalogCategoryAdminQueryProvider.php',
        );

        self::assertStringContainsString("return 'catalog_category_admin';", $source);
        self::assertStringContainsString(
            "public const ACL_SOURCE = 'Weline_Catalog::commerce:universal-catalog:categories';",
            $source,
        );
        self::assertStringContainsString('categoryAdminReorder', $source);
        self::assertStringContainsString('categoryAdminListProductsForDelete', $source);
        self::assertStringContainsString("'name' => 'product_ids'", $source);
        self::assertStringContainsString("'name' => 'expected_grant_version'", $source);
        self::assertStringContainsString('getCategoryProductsForDelete', $source);
        self::assertStringContainsString("'product_ids' => \$productIds", $source);
    }
}
