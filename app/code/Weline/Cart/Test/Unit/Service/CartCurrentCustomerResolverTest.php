<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cart\Service\CartCurrentCustomerResolver;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;

final class CartCurrentCustomerResolverTest extends TestCase
{
    public function testUsesTheCurrentFrontendSessionAsServerOwnedIdentity(): void
    {
        $session = $this->createMock(AuthenticatedSessionInterface::class);
        $session->expects(self::once())->method('isLoggedIn')->willReturn(true);
        $session->expects(self::once())->method('getUserId')->willReturn(77);

        $factory = $this->createMock(SessionFactory::class);
        $factory->expects(self::once())->method('createFrontendSession')->willReturn($session);

        self::assertSame(
            77,
            (new CartCurrentCustomerResolver(null, $factory))->currentCustomerId(),
        );
    }
}
