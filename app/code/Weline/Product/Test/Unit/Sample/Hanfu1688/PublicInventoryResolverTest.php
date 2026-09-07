<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Sample\Hanfu1688;

use PHPUnit\Framework\TestCase;
use Weline\Product\Sample\Hanfu1688\PublicInventoryResolver;

final class PublicInventoryResolverTest extends TestCase
{
    public function testAggregatesPublicVariantAvailabilityForSimpleCatalogOffer(): void
    {
        $quantity = (new PublicInventoryResolver())->resolve([
            'variants' => [
                ['public_available_quantity' => 12],
                ['public_available_quantity' => '8'],
                ['public_available_quantity' => 0],
            ],
        ]);

        self::assertSame(20, $quantity);
    }

    public function testReturnsNullWhenSourceDoesNotPublishAvailability(): void
    {
        $quantity = (new PublicInventoryResolver())->resolve([
            'variants' => [
                ['source_sku_id' => 'sku-1'],
                ['public_available_quantity' => null],
            ],
        ]);

        self::assertNull($quantity);
    }

    public function testIgnoresMalformedAndNegativeAvailability(): void
    {
        $quantity = (new PublicInventoryResolver())->resolve([
            'variants' => [
                ['public_available_quantity' => -1],
                ['public_available_quantity' => 'oops'],
                'invalid-row',
                ['public_available_quantity' => 3],
            ],
        ]);

        self::assertSame(3, $quantity);
    }

    public function testBuildsCreateAndExistingProductInventoryPayloads(): void
    {
        $resolver = new PublicInventoryResolver();
        $offer = ['variants' => [['public_available_quantity' => 7]]];

        self::assertSame(['stock' => 7], $resolver->payload($offer));
        self::assertSame([
            'inventory' => [[
                'store_id' => 0,
                'global_offer_uuid' => '68b3ab26-75f6-5cec-a84b-51ea2766d482',
                'on_hand_minor' => 7,
            ]],
        ], $resolver->payload($offer, '68b3ab26-75f6-5cec-a84b-51ea2766d482', 0));
    }
}
