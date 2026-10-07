<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class StorefrontGroceryCatalogSeedContractTest extends TestCase
{
    public function testCategorySeedRefusesDefaultAndRequiresGroceryCode(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/data/seed-grocery-categories.php'
        );
        self::assertStringContainsString("Refuse: Grocery seed must target website code grocery", $source);
        self::assertStringContainsString("Refuse: Grocery seed only accepts code=grocery", $source);
        self::assertStringContainsString('grocery-produce', $source);
        self::assertStringContainsString('deactivateForeignCategories', $source);
        self::assertStringContainsString('Website::ID_DEFAULT', $source);
    }

    public function testCatalogSeederRefusesDefaultWebsiteAndUsesGrocerySkus(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontGroceryCatalogSeeder.php'
        );
        self::assertStringContainsString('Grocery catalog seed refuses website_id=0', $source);
        self::assertStringContainsString('GROCERY-TOMATO-500G', $source);
        self::assertStringContainsString('grocery-produce', $source);
        self::assertStringContainsString('notifyCatalogChanged', $source);
        self::assertStringNotContainsString('is_demo', $source);
    }

    public function testCliSeedScriptOnlyAcceptsGrocery(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/scripts/seed-grocery-catalog.php'
        );
        self::assertStringContainsString("only website code grocery is accepted", $source);
        self::assertStringContainsString('StorefrontGroceryCatalogSeeder', $source);
        self::assertStringContainsString('seed-grocery-categories.php', $source);
    }
}
