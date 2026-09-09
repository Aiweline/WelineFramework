<?php

declare(strict_types=1);

namespace Weline\Currency\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Currency\Service\CurrencyRateService;
use Weline\Framework\Manager\ObjectManager;

final class CurrencyRateServiceTryConvertTest extends TestCase
{
    public function testTryConvertReturnsNullWhenTargetRateMissing(): void
    {
        /** @var CurrencyRateService $rates */
        $rates = ObjectManager::getInstance(CurrencyRateService::class);

        // Prefer a known zero-rate code (GBP fixture) so the assertion stays honest
        // after ops configure EUR for storefront acceptance.
        $target = 'GBP';
        $rate = (float)($rates->getCurrencyDefinition($target)['rate'] ?? 0);
        if ($rate > 0.0) {
            $target = 'JPY';
            $rate = (float)($rates->getCurrencyDefinition($target)['rate'] ?? 0);
        }
        if ($rate > 0.0) {
            self::markTestSkipped('No zero-rate currency available to assert tryConvert null');
        }

        self::assertNull($rates->tryConvert(108.0, 'CNY', $target));
    }

    public function testTryConvertChangesAmountWhenUsdRateConfigured(): void
    {
        /** @var CurrencyRateService $rates */
        $rates = ObjectManager::getInstance(CurrencyRateService::class);
        $usdRate = (float)($rates->getCurrencyDefinition('USD')['rate'] ?? 0);
        if ($usdRate <= 0.0) {
            self::markTestSkipped('USD rate not configured in this environment');
        }

        $result = $rates->tryConvert(108.0, 'CNY', 'USD');
        self::assertNotNull($result);
        self::assertEqualsWithDelta(108.0 / $usdRate, (float)$result, 0.01);
    }

    public function testConvertThrowsWhenTargetRateMissing(): void
    {
        /** @var CurrencyRateService $rates */
        $rates = ObjectManager::getInstance(CurrencyRateService::class);
        $target = 'GBP';
        $rate = (float)($rates->getCurrencyDefinition($target)['rate'] ?? 0);
        if ($rate > 0.0) {
            $target = 'JPY';
            $rate = (float)($rates->getCurrencyDefinition($target)['rate'] ?? 0);
        }
        if ($rate > 0.0) {
            self::markTestSkipped('No zero-rate currency available to assert convert throw');
        }

        $this->expectException(\RuntimeException::class);
        $rates->convert(108.0, 'CNY', $target);
    }

    public function testTryConvertChangesAmountWhenEurRateConfigured(): void
    {
        /** @var CurrencyRateService $rates */
        $rates = ObjectManager::getInstance(CurrencyRateService::class);
        $eurRate = (float)($rates->getCurrencyDefinition('EUR')['rate'] ?? 0);
        if ($eurRate <= 0.0) {
            self::markTestSkipped('EUR rate not configured in this environment');
        }

        $result = $rates->tryConvert(108.0, 'CNY', 'EUR');
        self::assertNotNull($result);
        self::assertEqualsWithDelta(108.0 / $eurRate, (float)$result, 0.01);
        self::assertNotEqualsWithDelta(108.0, (float)$result, 0.0001);
    }
}
