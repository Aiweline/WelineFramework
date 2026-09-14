<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Api\CommerceOrderTypeInterface;
use Weline\Order\Service\CommerceOrderTypeRegistry;
use Weline\Order\Service\TocCommerceOrderType;

final class CommerceOrderTypeRegistryTest extends TestCase
{
    public function testTocAlwaysPresentInForTesting(): void
    {
        $reg = CommerceOrderTypeRegistry::forTesting();

        self::assertTrue($reg->has(CommerceOrderTypeRegistry::CODE_TOC));
        self::assertSame(['toc'], $reg->codes());
        self::assertInstanceOf(TocCommerceOrderType::class, $reg->require('toc'));
        self::assertSame('toc', $reg->require('toc')->getCode());
        self::assertSame('info', $reg->require('toc')->getBadgeTone());
        self::assertSame('info', $reg->resolveBadgeTone('toc'));
        $tocSource = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/TocCommerceOrderType.php');
        self::assertStringContainsString("'零售订单'", $tocSource);
        self::assertStringContainsString("return 'info'", $tocSource);
    }

    public function testForTestingWithMockTobHasBoth(): void
    {
        $tob = new class implements CommerceOrderTypeInterface {
            public function getCode(): string
            {
                return 'tob';
            }

            public function getLabel(): string
            {
                return '批发订单';
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

        $reg = CommerceOrderTypeRegistry::forTesting([$tob]);

        self::assertTrue($reg->has('toc'));
        self::assertTrue($reg->has('tob'));
        self::assertSame(['toc', 'tob'], $reg->codes());
        self::assertTrue($reg->require('tob')->requiresCustomerLogin());
        self::assertTrue($reg->require('tob')->disablesStorefrontDiscounts());
    }
}
