<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Grocery website-concept seeds live under app/design/Weline/grocery (not Product module).
 */
final class StorefrontGroceryCatalogSeedContractTest extends TestCase
{
    public function testProductModuleNoLongerHostsGrocerySeedOpeners(): void
    {
        $productRoot = dirname(__DIR__, 3);
        self::assertFileDoesNotExist($productRoot . '/data/seed-grocery-categories.php');
        self::assertFileDoesNotExist($productRoot . '/scripts/seed-grocery-catalog.php');
        self::assertFileDoesNotExist($productRoot . '/Service/StorefrontGroceryCatalogSeeder.php');
    }

    public function testDesignThemeCategorySeedRefusesDefaultAndRequiresGroceryCode(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 6) . '/design/Weline/grocery/data/seed-categories.php'
        );
        self::assertStringContainsString("Refuse: Grocery seed must target website code grocery", $source);
        self::assertStringContainsString("Refuse: Grocery seed only accepts code=grocery", $source);
        self::assertStringContainsString('grocery-produce', $source);
        self::assertStringContainsString('deactivateForeignCategories', $source);
        self::assertStringContainsString('Website::ID_DEFAULT', $source);
    }

    public function testDesignThemeCatalogSeederRefusesDefaultWebsiteAndUsesGrocerySkus(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 6) . '/design/Weline/grocery/scripts/GroceryCatalogSeeder.php'
        );
        self::assertStringContainsString('namespace Weline\\Design\\Grocery', $source);
        self::assertStringContainsString('class GroceryCatalogSeeder', $source);
        self::assertStringContainsString('Grocery catalog seed refuses website_id=0', $source);
        self::assertStringContainsString('GROCERY-TOMATO-500G', $source);
        self::assertStringContainsString('grocery-produce', $source);
        self::assertStringContainsString('notifyCatalogChanged', $source);
        self::assertStringContainsString('disableForeignPublishedProducts', $source);
        self::assertStringContainsString("str_starts_with(\$sku, 'GROCERY-')", $source);
        self::assertStringNotContainsString('is_demo', $source);
    }

    public function testDesignThemeCliSeedScriptOnlyAcceptsGrocery(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 6) . '/design/Weline/grocery/scripts/seed-catalog.php'
        );
        self::assertStringContainsString("only website code grocery is accepted", $source);
        self::assertStringContainsString('GroceryCatalogSeeder', $source);
        self::assertStringContainsString('seed-categories.php', $source);
        self::assertStringContainsString('app/design/Weline/grocery/scripts/seed-catalog.php', $source);
    }
}
