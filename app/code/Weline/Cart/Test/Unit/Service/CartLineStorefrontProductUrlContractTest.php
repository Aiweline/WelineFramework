<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Cart lines must expose slug + PDP url for mini-cart / cart chrome.
 * Never default product identity links to /cart.
 */
final class CartLineStorefrontProductUrlContractTest extends TestCase
{
    public function testLineFromSnapshotCarriesSlugAndStorefrontProductUrl(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/CartService.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('function storefrontProductUrl', $source);
        self::assertStringContainsString("'slug' => \$slug", $source);
        self::assertStringContainsString("'url' => \$this->storefrontProductUrl(\$slug, \$productId)", $source);
        self::assertStringContainsString("'/product/'", $source);
        self::assertStringContainsString('never /cart', $source);
    }
}
