<?php

declare(strict_types=1);

namespace Weline\Currency\Test\Unit\Data;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCurrencyDataContractsPass().
 */
final class CurrencyDataContractsTest extends TestCase
{
    public function testAllCurrencyDataContractsPass(): void
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
            'CurrencyDataProcessCache::testCurrencyDataPinsProcessMemoAboveSharedCache' => function () {
            $this->assert_CurrencyDataProcessCache_testCurrencyDataPinsProcessMemoAboveSharedCache();
        },
        ];
    }

private function assert_CurrencyDataProcessCache_testCurrencyDataPinsProcessMemoAboveSharedCache(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Currency' . '/Data/CurrencyData.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('function clearProcessCache', $source);
    self::assertStringContainsString('$processCurrenciesByLocale', $source);
    self::assertStringContainsString('$processCurrencyByKey', $source);
    self::assertStringContainsString('self::clearProcessCache()', $source);
    self::assertStringContainsString("w_cache('currency')->clear()", $source);
}
}
