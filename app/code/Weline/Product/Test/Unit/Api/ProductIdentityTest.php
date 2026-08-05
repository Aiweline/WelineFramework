<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Api;

use PHPUnit\Framework\TestCase;
use Weline\Product\Api\ProductIdentity;

final class ProductIdentityTest extends TestCase
{
    public function testToArray(): void
    {
        $identity = new ProductIdentity(
            registryId: 7,
            sku: 'SKU-A',
            globalProductUuid: '11111111-1111-4111-8111-111111111111',
            globalOfferUuid: '22222222-2222-4222-8222-222222222222',
            requestHash: str_repeat('a', 64),
            refCount: 3,
        );

        self::assertSame([
            'registry_id' => 7,
            'sku' => 'SKU-A',
            'global_product_uuid' => '11111111-1111-4111-8111-111111111111',
            'global_offer_uuid' => '22222222-2222-4222-8222-222222222222',
            'request_hash' => str_repeat('a', 64),
            'ref_count' => 3,
        ], $identity->toArray());
    }
}
