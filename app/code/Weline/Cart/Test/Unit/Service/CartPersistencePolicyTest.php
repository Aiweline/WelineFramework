<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cart\Service\CartPersistencePolicy;
use Weline\Cart\Service\CartService;

final class CartPersistencePolicyTest extends TestCase
{
    public function testGuestTtlIsFifteenDays(): void
    {
        self::assertSame(15 * 24 * 3600, CartPersistencePolicy::GUEST_TTL_SECONDS);
        self::assertSame(15 * 24 * 3600, CartPersistencePolicy::guestTtlSeconds());
        self::assertSame(
            CartPersistencePolicy::GUEST_TTL_SECONDS,
            CartPersistencePolicy::ttlForOwnerKind(CartService::OWNER_GUEST),
        );
        self::assertSame(
            CartPersistencePolicy::GUEST_TTL_SECONDS,
            CartPersistencePolicy::ttlForCartKey('site|guest:abc|type:toc'),
        );
    }

    public function testCustomerTtlIsDurableNotSevenDays(): void
    {
        self::assertGreaterThan(
            10 * 365 * 24 * 3600,
            CartPersistencePolicy::CUSTOMER_TTL_SECONDS,
        );
        self::assertNotSame(604800, CartPersistencePolicy::CUSTOMER_TTL_SECONDS);
        self::assertSame(
            CartPersistencePolicy::CUSTOMER_TTL_SECONDS,
            CartPersistencePolicy::ttlForOwnerKind(CartService::OWNER_CUSTOMER),
        );
        self::assertSame(
            CartPersistencePolicy::CUSTOMER_TTL_SECONDS,
            CartPersistencePolicy::ttlForCart([
                'owner_kind' => CartService::OWNER_CUSTOMER,
                'owner_id' => '42',
            ]),
        );
        self::assertSame(
            CartPersistencePolicy::CUSTOMER_TTL_SECONDS,
            CartPersistencePolicy::ttlForCartKey('site|customer:42|type:toc'),
        );
    }

    public function testGuestExpiresAtMsMatchesTtl(): void
    {
        $now = 1_700_000_000_000.0;
        self::assertSame(
            (int)($now + CartPersistencePolicy::GUEST_TTL_SECONDS * 1000),
            CartPersistencePolicy::guestExpiresAtMs($now),
        );
    }

    public function testDbExpiresAtGuestDatetimeCustomerNull(): void
    {
        $guest = CartPersistencePolicy::expiresAtForCart([
            'owner_kind' => CartService::OWNER_GUEST,
            'owner_id' => 'tok',
        ]);
        self::assertNotNull($guest);
        $guestTs = strtotime($guest . ' UTC');
        self::assertNotFalse($guestTs);
        self::assertEqualsWithDelta(
            time() + CartPersistencePolicy::GUEST_TTL_SECONDS,
            $guestTs,
            3,
        );

        self::assertNull(CartPersistencePolicy::expiresAtForCart([
            'owner_kind' => CartService::OWNER_CUSTOMER,
            'owner_id' => '42',
        ]));
        self::assertNull(CartPersistencePolicy::expiresAtForCart(
            [],
            'site|customer:42|type:toc',
        ));
    }
}
