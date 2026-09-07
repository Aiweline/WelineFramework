<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cart\Api\CommerceCartTypeInterface;
use Weline\Cart\Service\CommerceCartTypeRegistry;
use Weline\Cart\Service\TocCommerceCartType;

final class CommerceCartTypeRegistryTest extends TestCase
{
    public function testTocAlwaysPresentInForTesting(): void
    {
        $reg = CommerceCartTypeRegistry::forTesting();

        self::assertTrue($reg->has(CommerceCartTypeRegistry::CODE_TOC));
        self::assertSame(['toc'], $reg->codes());
        self::assertInstanceOf(TocCommerceCartType::class, $reg->require('toc'));
        self::assertSame('toc', $reg->require('toc')->getCode());
    }

    public function testForTestingWithMockTobHasBoth(): void
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

        $reg = CommerceCartTypeRegistry::forTesting([$tob]);

        self::assertTrue($reg->has('toc'));
        self::assertTrue($reg->has('tob'));
        self::assertSame(['toc', 'tob'], $reg->codes());
        self::assertTrue($reg->require('tob')->requiresCustomerLogin());
        self::assertTrue($reg->require('tob')->disablesStorefrontDiscounts());
    }
}
