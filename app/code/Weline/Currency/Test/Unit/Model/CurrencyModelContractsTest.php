<?php

declare(strict_types=1);

namespace Weline\Currency\Test\Unit\Model;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCurrencyModelContractsPass().
 */
final class CurrencyModelContractsTest extends TestCase
{
    public function testAllCurrencyModelContractsPass(): void
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
            'CurrencySaveInvalidatesCatalog::testSaveAfterInvalidatesRateDefinitionsAndCatalogOffers' => function () {
            $this->assert_CurrencySaveInvalidatesCatalog_testSaveAfterInvalidatesRateDefinitionsAndCatalogOffers();
        },
            'CurrencySaveInvalidatesCatalog::testProcessCacheResetterClearsCatalogAndSelect' => function () {
            $this->assert_CurrencySaveInvalidatesCatalog_testProcessCacheResetterClearsCatalogAndSelect();
        },
            'CurrencySaveInvalidatesCatalog::testRateServiceExposesInvalidateCachedDefinitions' => function () {
            $this->assert_CurrencySaveInvalidatesCatalog_testRateServiceExposesInvalidateCachedDefinitions();
        },
        ];
    }

private function assert_CurrencySaveInvalidatesCatalog_testSaveAfterInvalidatesRateDefinitionsAndCatalogOffers(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Currency' . '/Model/Currency.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('invalidateStorefrontPriceCaches', $source);
    self::assertStringContainsString('invalidateCachedDefinitions', $source);
    self::assertStringContainsString('notifyCatalogChanged', $source);
    self::assertStringContainsString('currency-rate-changed', $source);
    self::assertStringContainsString('CurrencyCatalog::clearProcessCache', $source);
    self::assertStringContainsString('CurrencySelect::clearProcessCaches', $source);
    self::assertStringContainsString('CurrencyData::clearCache', $source);
    self::assertStringContainsString('CurrencySymbol::clearProcessCache', $source);
    self::assertStringContainsString('CurrencySwitcherIslandBuilder::invalidate', $source);
}

private function assert_CurrencySaveInvalidatesCatalog_testProcessCacheResetterClearsCatalogAndSelect(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Currency' . '/Api/Runtime/ProcessCacheResetter.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('CurrencyCatalog::clearProcessCache()', $source);
    self::assertStringContainsString('CurrencySelect::clearProcessCaches()', $source);
    self::assertStringContainsString('CurrencyData::clearProcessCache()', $source);
    self::assertStringContainsString('CurrencySymbol::clearProcessCache()', $source);
    self::assertStringContainsString('CurrencyDisplayName::clearProcessCache()', $source);
    self::assertStringContainsString('CurrencyLocalDescriptionService::clearProcessCache()', $source);
    self::assertStringContainsString('CurrencySwitcherIslandBuilder::invalidate', $source);
    self::assertStringContainsString('invalidateCachedDefinitions', $source);
}

private function assert_CurrencySaveInvalidatesCatalog_testRateServiceExposesInvalidateCachedDefinitions(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Currency' . '/Service/CurrencyRateService.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('function invalidateCachedDefinitions', $source);
    self::assertStringContainsString('runtimeCacheDelete', $source);
    self::assertStringContainsString('currency.definition.', $source);
}
}
