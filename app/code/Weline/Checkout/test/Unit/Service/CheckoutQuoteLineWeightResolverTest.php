<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Checkout\Service\CheckoutQuoteLineWeightResolver;

final class CheckoutQuoteLineWeightResolverTest extends TestCase
{
    public function testPrefersLineSnapshotOverCatalog(): void
    {
        $resolver = CheckoutQuoteLineWeightResolver::forTesting(static fn (): int => 999);
        self::assertSame(250, $resolver->resolveLineWeightMinor([
            'weight_minor' => 250,
            'product_id' => 12,
        ]));
    }

    public function testCatalogBackfillWhenLineWeightMissing(): void
    {
        $resolver = CheckoutQuoteLineWeightResolver::forTesting(
            static function (int $_w, int $_s, int $productId): int {
                return $productId === 12 ? 400 : 0;
            }
        );
        self::assertSame(400, $resolver->resolveLineWeightMinor([
            'weight_minor' => 0,
            'product_id' => 12,
        ]));
    }

    public function testMissingCatalogWeightIsZeroNotHalfKgInvent(): void
    {
        $resolver = CheckoutQuoteLineWeightResolver::forTesting(static fn (): int => 0);
        self::assertSame(0, $resolver->resolveLineWeightMinor([
            'weight_minor' => 0,
            'product_id' => 192,
        ]));
        self::assertSame(0, $resolver->resolveLineWeightMinor([
            'weight_minor' => 0,
            'product_id' => 0,
        ]));
    }
}
