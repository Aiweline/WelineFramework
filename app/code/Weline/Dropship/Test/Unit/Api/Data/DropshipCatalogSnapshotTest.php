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
    }
}
