<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cart\Api\CommerceCartTypeInterface;
use Weline\Cart\Api\CommerceTypeMembershipCheckerInterface;
use Weline\Cart\Service\CartConflictException;
use Weline\Cart\Service\CommerceCartTypeRegistry;
use Weline\Cart\Service\CommerceTypeMembershipGate;
use Weline\Cart\Service\SellingTypeResolver;

final class CommerceTypeMembershipGateTest extends TestCase
{
    public function testGateAllowsTocWithoutProvider(): void
    {
        $gate = CommerceTypeMembershipGate::forTesting();
        self::assertTrue($gate->hasMembership('toc', 0, 0));
        self::assertFalse($gate->hasMembership('tob', 1, 0));
    }

    public function testGateDelegatesToChecker(): void
    {
        $checker = new class implements CommerceTypeMembershipCheckerInterface {
            public function hasMembership(string $typeCode, int $customerId, int $websiteId): bool
            {
                return $typeCode === 'tob' && $customerId === 9 && $websiteId === 0;
            }
        };
        $gate = CommerceTypeMembershipGate::forTesting($checker);
        self::assertTrue($gate->hasMembership('tob', 9, 0));
        self::assertFalse($gate->hasMembership('tob', 8, 0));
    }

    public function testResolverRequiresMembershipForTobViaGate(): void
    {
        $tob = new class implements CommerceCartTypeInterface {
            public function getCode(): string
            {
                return 'tob';
            }

            public function getLabel(): string
            {
                return '批发';
            }

            public function getBadgeTone(): string
            {
                return 'warning';
            }

            public function requiresCustomerLogin(): bool
            {
                return true;
            }

            public function disablesStorefrontDiscounts(): bool
            {
                return true;
            }
        };

        $checker = new class implements CommerceTypeMembershipCheckerInterface {
            public function hasMembership(string $typeCode, int $customerId, int $websiteId): bool
            {
                return $typeCode === 'tob' && $customerId === 3;
            }
        };

        $resolver = SellingTypeResolver::forTesting(
            CommerceCartTypeRegistry::forTesting([$tob]),
            null,
            CommerceTypeMembershipGate::forTesting($checker),
        );

        $ok = $resolver->resolve('tob', true, 3, 0);
        self::assertSame('tob', $ok['code']);

        try {
            $resolver->resolve('tob', true, 2, 0);
            self::fail('expected membership required');
        } catch (CartConflictException $e) {
            self::assertSame(SellingTypeResolver::ERROR_MEMBERSHIP_REQUIRED, $e->errorCode());
        }
    }
}
