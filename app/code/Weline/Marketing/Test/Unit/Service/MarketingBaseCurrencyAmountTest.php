<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Currency\Service\CurrencyRateService;
use Weline\Marketing\Service\MarketingBaseCurrencyAmount;

final class MarketingBaseCurrencyAmountTest extends TestCase
{
    public function testSameCurrencyIsIdentity(): void
    {
        $fx = MarketingBaseCurrencyAmount::forTesting($this->rates([
            'CNY' => 1.0,
        ], 'CNY'));

        self::assertSame('CNY', $fx->baseCurrency());
        self::assertEqualsWithDelta(10.0, (float)$fx->convertBaseMajorToCheckout(10.0, 'CNY'), 0.0001);
    }

    public function testConvertsBaseCnyFixedAmountToUsd(): void
    {
        // rate column = units of base per 1 foreign → USD rate 7 means 1 USD = 7 CNY → 10 CNY ≈ 1.4286 USD
        $fx = MarketingBaseCurrencyAmount::forTesting($this->rates([
            'CNY' => 1.0,
            'USD' => 7.0,
        ], 'CNY'));

        $converted = $fx->convertBaseMajorToCheckout(10.0, 'USD');
        self::assertNotNull($converted);
        self::assertEqualsWithDelta(10.0 / 7.0, (float)$converted, 0.0001);
        self::assertLessThan(10.0, (float)$converted);
    }

    public function testMissingRateFailsClosed(): void
    {
        $fx = MarketingBaseCurrencyAmount::forTesting($this->rates([
            'CNY' => 1.0,
            'USD' => 0.0,
        ], 'CNY'));

        self::assertNull($fx->convertBaseMajorToCheckout(10.0, 'USD'));
    }

    public function testCheckoutCurrencyPrefersOrderContext(): void
    {
        $fx = MarketingBaseCurrencyAmount::forTesting($this->rates(['CNY' => 1.0], 'CNY'));
        self::assertSame('USD', $fx->checkoutCurrencyFromContext([
            'currency' => 'EUR',
            'order' => ['currency' => 'USD'],
        ]));
    }

    /**
     * @param array<string, float> $rates
     */
    private function rates(array $rates, string $base): CurrencyRateService
    {
        $mock = $this->getMockBuilder(CurrencyRateService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBaseCurrency', 'tryConvert'])
            ->getMock();
        $mock->method('getBaseCurrency')->willReturn($base);
        $mock->method('tryConvert')->willReturnCallback(
            static function (float $amount, ?string $from = null, ?string $to = null) use ($rates, $base): ?float {
                $from = strtoupper(trim((string)($from ?: $base)));
                $to = strtoupper(trim((string)($to ?: $base)));
                if ($from === $to) {
                    return round($amount, 4);
                }
                $fromRate = $rates[$from] ?? 0.0;
                $toRate = $rates[$to] ?? 0.0;
                if ($fromRate <= 0 || $toRate <= 0) {
                    return null;
                }
                // Mirror CurrencyRateService: rate = base units per 1 foreign
                $inBase = $from === $base ? $amount : $amount * $fromRate;
                $out = $to === $base ? $inBase : $inBase / $toRate;

                return round($out, 4);
            }
        );

        return $mock;
    }
}
