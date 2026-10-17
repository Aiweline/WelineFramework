<?php

declare(strict_types=1);

namespace Weline\Currency\Test\Unit\Service\Repository;

use PHPUnit\Framework\TestCase;
use Weline\Currency\Service\Repository\CurrencyCatalog;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCurrencyRepositoryContractsPass().
 */
final class CurrencyRepositoryContractsTest extends TestCase
{
    public function testAllCurrencyRepositoryContractsPass(): void
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
            'CurrencyCatalogProcessCache::testClearProcessCacheIsPublicAndResetsActiveBucket' => function () {
            $this->assert_CurrencyCatalogProcessCache_testClearProcessCacheIsPublicAndResetsActiveBucket();
        },
        ];
    }

private function assert_CurrencyCatalogProcessCache_testClearProcessCacheIsPublicAndResetsActiveBucket(): void
{
    $property = new \ReflectionProperty(CurrencyCatalog::class, 'activeCache');
    $property->setValue(null, []);
    self::assertIsArray($property->getValue(null));
    CurrencyCatalog::clearProcessCache();
    self::assertNull($property->getValue(null));
}
}
