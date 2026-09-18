<?php

declare(strict_types=1);

namespace Weline\Currency\Test\Unit\Model;

use PHPUnit\Framework\TestCase;

final class CurrencySaveInvalidatesCatalogContractTest extends TestCase
{
    public function testSaveAfterInvalidatesRateDefinitionsAndCatalogOffers(): void
    {
        $path = dirname(__DIR__, 3) . '/Model/Currency.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('invalidateStorefrontPriceCaches', $source);
        self::assertStringContainsString('invalidateCachedDefinitions', $source);
        self::assertStringContainsString('notifyCatalogChanged', $source);
        self::assertStringContainsString('currency-rate-changed', $source);
        self::assertStringContainsString('CurrencyCatalog::clearProcessCache', $source);
        self::assertStringContainsString('CurrencySelect::clearProcessCaches', $source);
    }

    public function testProcessCacheResetterClearsCatalogAndSelect(): void
    {
        $path = dirname(__DIR__, 3) . '/Api/Runtime/ProcessCacheResetter.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('CurrencyCatalog::clearProcessCache()', $source);
        self::assertStringContainsString('CurrencySelect::clearProcessCaches()', $source);
        self::assertStringContainsString('invalidateCachedDefinitions', $source);
    }

    public function testRateServiceExposesInvalidateCachedDefinitions(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/CurrencyRateService.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('function invalidateCachedDefinitions', $source);
        self::assertStringContainsString('runtimeCacheDelete', $source);
        self::assertStringContainsString('currency.definition.', $source);
    }
}
