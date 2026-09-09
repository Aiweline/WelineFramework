<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cart\Service\CartCacheStore;
use Weline\Framework\Cache\CacheManager;

/**
 * Source + predefined-pool contract: cart must stay durable under WLS hijack policy.
 */
final class CartCacheDurableRoutingContractTest extends TestCase
{
    public function testCartPoolPredefinedAsHijackExempt(): void
    {
        $ref = new \ReflectionClass(CacheManager::class);
        $const = $ref->getConstant('PREDEFINED_POOLS');
        self::assertIsArray($const);
        self::assertArrayHasKey('cart', $const);
        self::assertTrue((bool)($const['cart']['hijack_exempt'] ?? false));
        self::assertTrue((bool)($const['cart']['durable'] ?? false));

        $src = (string)\file_get_contents($ref->getFileName());
        self::assertStringContainsString('hijack_exempt', $src);
        self::assertStringContainsString("!empty(\$poolConfig['hijack_exempt'])", $src);
    }

    public function testCartCacheStoreFailsClosedOnPersistError(): void
    {
        $storeRef = new \ReflectionClass(CartCacheStore::class);
        $store = (string)\file_get_contents((string)$storeRef->getFileName());
        self::assertStringContainsString('ERROR_PERSIST', $store);
        self::assertStringContainsString('cart_persist_failed', $store);
        self::assertStringContainsString('if (!$cache->setCustom(', $store);
        self::assertSame('cart_persist_failed', CartCacheStore::ERROR_PERSIST);
    }
}