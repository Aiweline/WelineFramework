<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Doc;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllProductDocContractsPass().
 */
final class ProductDocContractsTest extends TestCase
{
    public function testAllProductDocContractsPass(): void
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
            'Product541StorefrontEnrichmentLog::testDevelopmentLogRecordsApiDemoEnrichment' => function () {
            $this->assert_Product541StorefrontEnrichmentLog_testDevelopmentLogRecordsApiDemoEnrichment();
        },
        ];
    }

private function assert_Product541StorefrontEnrichmentLog_testDevelopmentLogRecordsApiDemoEnrichment(): void
{
    $log = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/doc/开发日志.md');
    self::assertStringContainsString('API 演示商品 541 店面 enrichment', $log);
    self::assertStringContainsString('/product/541', $log);
}
}
