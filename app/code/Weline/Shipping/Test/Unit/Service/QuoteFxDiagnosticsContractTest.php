<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class QuoteFxDiagnosticsContractTest extends TestCase
{
    public function testQuoteRatesExposeFxSkippedDiagnostics(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ShippingServiceManager.php',
        );
        self::assertStringContainsString('getLastQuoteDiagnostics', $src);
        self::assertStringContainsString('fx_skipped', $src);
        self::assertStringContainsString("\$fxSkipped[] = \$serviceCode", $src);
    }

    public function testListQuoteOptionsReturnsDiagnostics(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3)
            . '/extends/module/Weline_Framework/Query/ShippingInfoQueryProvider.php',
        );
        self::assertStringContainsString('quote_diagnostics', $src);
        self::assertStringContainsString('getLastQuoteDiagnostics', $src);
    }
}
