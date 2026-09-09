<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cart\Api\CartStoreInterface;
use Weline\Cart\Service\CartDbStore;
use Weline\Cart\Service\CartService;

/**
 * Contract: production default store is DB, not cache/file.
 */
final class CartDbStoreDefaultWiringContractTest extends TestCase
{
    public function testModuleProvidesCartStoreInterfaceToDbStore(): void
    {
        $module = require dirname(__DIR__, 3) . '/etc/module.php';
        self::assertIsArray($module);
        $provides = $module['provides'] ?? [];
        self::assertIsArray($provides);
        self::assertSame(
            CartDbStore::class,
            $provides[CartStoreInterface::class] ?? null,
        );
    }

    public function testCartServiceConstructorDefaultsToCartDbStore(): void
    {
        $src = (string)\file_get_contents(
            (new \ReflectionClass(CartService::class))->getFileName(),
        );
        self::assertStringContainsString('CartDbStore::class', $src);
        self::assertStringNotContainsString(
            'ObjectManager::getInstance(CartCacheStore::class)',
            $src,
        );
        self::assertTrue(\class_exists(CartDbStore::class));
        self::assertTrue(
            \is_a(CartDbStore::class, CartStoreInterface::class, true),
        );
    }

    public function testCartDbStoreDocumentsDbAuthority(): void
    {
        $src = (string)\file_get_contents(
            (new \ReflectionClass(CartDbStore::class))->getFileName(),
        );
        self::assertStringContainsString('weline_cart', $src);
        self::assertStringContainsString('expires_at', $src);
        self::assertStringContainsString('CartPersistencePolicy::expiresAtForCart', $src);
        self::assertSame('cart_persist_failed', CartDbStore::ERROR_PERSIST);
    }
}
