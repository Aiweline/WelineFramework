<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cart\Service\FreeShippingProgressService;

final class FreeShippingProgressServiceContractTest extends TestCase
{
    public function testSubtotalDoesNotEstablishApplicableFreeShipping(): void
    {
        $service = new FreeShippingProgressService();
        foreach ([0, 2000, 4900, 100000] as $subtotalMinor) {
            $progress = $service->build([
                'currency' => 'USD',
                'subtotal_minor' => $subtotalMinor,
            ]);
            self::assertFalse($progress['enabled']);
            self::assertArrayNotHasKey('threshold_minor', $progress);
        }
    }

    public function testMixedCartShowsLineDistinctionWithoutWholeCartQualified(): void
    {
        $service = new FreeShippingProgressService();
        $progress = $service->build([
            'currency_precision' => 2,
            'items' => [
                [
                    'requires_shipping' => true,
                    'is_free_shipping' => 1,
                    'free_shipping_min_amount' => 0,
                    'row_total_minor' => 1000,
                ],
                [
                    'requires_shipping' => true,
                    'row_total_minor' => 5000,
                ],
            ],
        ]);
        self::assertTrue($progress['enabled']);
        self::assertFalse($progress['qualified']);
        self::assertSame('product_line', $progress['source']);
        self::assertSame(1, $progress['free_item_count']);
        self::assertSame(1, $progress['paid_item_count']);
        self::assertLessThan(100, $progress['progress_percent']);
    }

    public function testAllProductFreeLinesDoNotAdvertiseWholeCartFreeShipping(): void
    {
        $service = new FreeShippingProgressService();
        $progress = $service->build([
            'items' => [
                [
                    'requires_shipping' => true,
                    'is_free_shipping' => 1,
                    'row_total_minor' => 1000,
                ],
            ],
        ]);
        self::assertFalse($progress['enabled']);
    }

    public function testCartQueryProviderEnrichesFreeShippingProgress(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/CartQueryProvider.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('FreeShippingProgressService', $source);
        self::assertStringContainsString('enrichSummaryWithFreeShippingProgress', $source);
        self::assertStringContainsString('free_shipping_progress', $source);
    }
}
