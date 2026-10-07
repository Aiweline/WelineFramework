<?php

declare(strict_types=1);

namespace Weline\Currency\Test\Unit\Data;

use PHPUnit\Framework\TestCase;

final class CurrencyDataProcessCacheContractTest extends TestCase
{
    public function testCurrencyDataPinsProcessMemoAboveSharedCache(): void
    {
        $path = dirname(__DIR__, 3) . '/Data/CurrencyData.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('function clearProcessCache', $source);
        self::assertStringContainsString('$processCurrenciesByLocale', $source);
        self::assertStringContainsString('$processCurrencyByKey', $source);
        self::assertStringContainsString('self::clearProcessCache()', $source);
        self::assertStringContainsString("w_cache('currency')->clear()", $source);
    }
}
