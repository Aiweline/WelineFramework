<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Extends\Module\Weline_Checkout\CheckoutType\TobCheckoutTypeProvider;
use Weline\Checkout\CheckoutType\StandardCheckoutTypeProvider;
use Weline\Checkout\Service\CheckoutTypeProviderRegistry;

final class CheckoutTypeProviderRegistryContractTest extends TestCase
{
    public function testBuiltInStandardAndCartTypeMapping(): void
    {
        $reg = CheckoutTypeProviderRegistry::forTesting();
        self::assertTrue($reg->has('standard'));
        self::assertSame('toc', $reg->cartTypeFromTypeCode('standard'));
        self::assertSame('standard', $reg->typeCodeFromCartType('toc'));
        self::assertSame('standard', $reg->typeCodeFromCartType(''));
    }

    public function testRejectsContinuePayAsType(): void
    {
        $reg = CheckoutTypeProviderRegistry::forTesting();
        $this->expectException(\InvalidArgumentException::class);
        $reg->register(new class implements \Weline\Checkout\Api\CheckoutTypeProviderInterface {
            public function getCode(): string { return 'continue_pay'; }
            public function getLabel(): string { return 'x'; }
            public function getSortOrder(): int { return 1; }
            public function isAvailable(array $context = []): bool { return true; }
            public function getCartTypeCode(): string { return 'toc'; }
            public function getCapabilities(): array { return []; }
        });
    }

    public function testTobProviderMapsCartType(): void
    {
        $reg = CheckoutTypeProviderRegistry::forTesting([new TobCheckoutTypeProvider()]);
        self::assertTrue($reg->has('tob'));
        self::assertSame('tob', $reg->cartTypeFromTypeCode('tob'));
        self::assertSame('tob', $reg->typeCodeFromCartType('tob'));
        $labels = array_map(static fn ($p) => $p->getCode(), $reg->all());
        self::assertSame(['standard', 'tob'], $labels);
    }

    public function testStandardProviderCapabilities(): void
    {
        $p = new StandardCheckoutTypeProvider();
        self::assertTrue($p->getCapabilities()['needs_shipping_address']);
    }
}
