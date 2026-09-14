<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Api\Data;

use PHPUnit\Framework\TestCase;
use Weline\Dropship\Api\Data\DropshipCatalogSnapshot;

final class DropshipCatalogSnapshotTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $s = DropshipCatalogSnapshot::fromArray([
            'provider_code' => 'cj',
            'external_spu' => 'P1',
            'title' => 'T',
            'origin_currency' => 'USD',
            'origin_price_minor' => 100,
            'qty' => 2,
            'shelf_status' => 'active',
        ]);
        self::assertSame('cj', $s->providerCode);
        self::assertSame('P1', $s->toArray()['external_spu']);
        self::assertFalse($s->hasShippingDims());

        $withShip = DropshipCatalogSnapshot::fromArray([
            'provider_code' => 'cj',
            'external_spu' => 'P2',
            'title' => 'T2',
            'origin_currency' => 'USD',
            'origin_price_minor' => 100,
            'qty' => 1,
            'shelf_status' => 'active',
            'shipping' => ['weight_kg' => 0.5, 'length_cm' => 10, 'width_cm' => 8, 'height_cm' => 4],
        ]);
        self::assertTrue($withShip->hasShippingDims());
        self::assertSame(0.5, $withShip->toArray()['shipping']['weight_kg']);
    }
}
