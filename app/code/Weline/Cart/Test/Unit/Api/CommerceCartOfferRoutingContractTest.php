<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\Api;

use PHPUnit\Framework\TestCase;
use Weline\Cart\Api\CommerceCartOfferRoutingInterface;

final class CommerceCartOfferRoutingContractTest extends TestCase
{
    public function testInterfaceDeclaresResolveAddCartType(): void
    {
        self::assertTrue(interface_exists(CommerceCartOfferRoutingInterface::class));
        $ref = new \ReflectionClass(CommerceCartOfferRoutingInterface::class);
        self::assertTrue($ref->hasMethod('resolveAddCartType'));
    }

    public function testCartServiceRemapsViaOfferRoutingHook(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CartService.php',
        );
        self::assertStringContainsString('function remapCartTypeForOffer(', $src);
        self::assertStringContainsString('CommerceCartOfferRoutingInterface::class', $src);
        self::assertStringContainsString(
            '$cartType = $this->remapCartTypeForOffer($cartType, $snapshot, $scope, $customerId);',
            $src,
        );
    }
}
