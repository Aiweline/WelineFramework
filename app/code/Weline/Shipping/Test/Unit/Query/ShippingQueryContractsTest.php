<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Query;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllShippingQueryContractsPass().
 */
final class ShippingQueryContractsTest extends TestCase
{
    public function testAllShippingQueryContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'ShippingQuoteFailureDiagnostics::testQuoteOpsFailureIncludesLastDiagnostics' => function () {
            $this->assert_ShippingQuoteFailureDiagnostics_testQuoteOpsFailureIncludesLastDiagnostics();
        },
        ];
    }

private function assert_ShippingQuoteFailureDiagnostics_testQuoteOpsFailureIncludesLastDiagnostics(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Shipping' . '/extends/module/Weline_Framework/Query/ShippingInfoQueryProvider.php');
    self::assertStringContainsString('getLastQuoteDiagnostics()', $src);
    self::assertStringContainsString("'quote_diagnostics' => \$diagnostics", $src);
    self::assertStringContainsString('exception_message', $src);
}
}
