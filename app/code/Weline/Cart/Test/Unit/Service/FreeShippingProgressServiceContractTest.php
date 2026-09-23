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
            self::assertArrayNotHasKey('message_qualified', $progress);
            self::assertArrayNotHasKey('threshold_minor', $progress);
        }
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
