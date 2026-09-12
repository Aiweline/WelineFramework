<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Dropship\Service\DropshipListedLocalDetailService;

final class DropshipListedLocalDetailServiceTest extends TestCase
{
    public function testMissingListingIdFails(): void
    {
        $svc = new DropshipListedLocalDetailService();
        $r = $svc->build([]);
        self::assertFalse($r['ok']);
        self::assertSame('listing_required', $r['message']);
    }

    public function testPendingWithoutLocalUuidReturnsHasLocalFalse(): void
    {
        $svc = new DropshipListedLocalDetailService();
        $r = $svc->build([
            'listing_id' => 99,
            'website_id' => 0,
            'store_id' => 1,
            'local_product_uuid' => '',
            'origin_price_minor' => 211,
            'origin_price_prev_minor' => 250,
            'origin_currency' => 'CNY',
            'sale_price_minor' => 274,
            'uplift_percent' => 30,
            'price_direction' => 'down',
        ]);
        self::assertTrue($r['ok']);
        self::assertFalse($r['has_local']);
        self::assertSame(274, $r['sale']['amount_minor']);
        self::assertSame([], $r['variants']);
        self::assertSame(211, $r['economics']['cost_minor']);
        self::assertSame(63, $r['economics']['margin_minor']);
        self::assertSame('down', $r['economics']['origin_direction']);
        self::assertArrayHasKey('ops', $r);
        // Same-currency origin/sale → no compare line
        self::assertArrayNotHasKey('compare_amount_minor', $r['sale']);

        $usdOrigin = $svc->build([
            'listing_id' => 100,
            'website_id' => 0,
            'store_id' => 0,
            'local_product_uuid' => '',
            'origin_price_minor' => 211,
            'origin_currency' => 'USD',
            'sale_price_minor' => 2194,
            'uplift_percent' => 30,
        ]);
        self::assertTrue($usdOrigin['ok']);
        if (isset($usdOrigin['sale']['compare_amount_minor'])) {
            self::assertSame('USD', $usdOrigin['sale']['compare_currency']);
            self::assertGreaterThan(0, (int)$usdOrigin['sale']['compare_amount_minor']);
        }
    }

    public function testProjectVariantRowLabelsFromCombination(): void
    {
        $svc = new DropshipListedLocalDetailService();
        $m = new \ReflectionMethod($svc, 'projectVariantRow');
        $m->setAccessible(true);
        $row = $m->invoke($svc, [
            'offer_id' => 1,
            'sku' => 'SKU-A',
            'combination' => ['style_type' => 'Groove B', 'size' => 'L'],
            'amount_minor' => 10868,
            'currency' => 'CNY',
            'status' => 'published',
            'image_url' => '',
        ], 'CNY');
        self::assertSame('Groove B · L', $row['label']);
        self::assertSame(10868, $row['amount_minor']);
    }
}
