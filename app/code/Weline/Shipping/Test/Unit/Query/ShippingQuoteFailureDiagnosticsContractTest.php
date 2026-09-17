<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Query;

use PHPUnit\Framework\TestCase;

/** listQuoteOptions 失败必须带回 quote_diagnostics，禁止空 data 导致结账含糊空态。 */
final class ShippingQuoteFailureDiagnosticsContractTest extends TestCase
{
    public function testQuoteOpsFailureIncludesLastDiagnostics(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/ShippingInfoQueryProvider.php',
        );
        self::assertStringContainsString('getLastQuoteDiagnostics()', $src);
        self::assertStringContainsString("'quote_diagnostics' => \$diagnostics", $src);
        self::assertStringContainsString('exception_message', $src);
    }
}
