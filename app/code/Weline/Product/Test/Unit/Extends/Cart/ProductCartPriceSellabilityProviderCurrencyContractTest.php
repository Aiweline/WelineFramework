<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Extends\Cart;

use PHPUnit\Framework\TestCase;
use Weline\Product\Integration\Cart\ProductCartPriceSellabilityProvider;
use Weline\Product\Service\CatalogConflictException;

/**
 * Sellability must follow the snapshot currency contract: display-currency rows
 * win, a missing display row falls back to base price + FX (rate-mode doc), and
 * an explicit cleared row stays blocked.
 */
final class ProductCartPriceSellabilityProviderCurrencyContractTest extends TestCase
{
    public function testMissingDisplayRowWithConvertibleBasePriceIsSellable(): void
    {
        $prices = new PriceDouble(missing: ['USD']);
        $provider = $this->provider($prices);

        $result = $provider->assertOrAllow([
            'website_id' => 0,
            'store_id' => 0,
            'offer_id' => 42,
            'currency' => 'USD',
        ]);

        self::assertTrue($result['ok'], json_encode($result));
        self::assertSame(['USD', 'CNY'], $prices->asserted);
    }

    public function testClearedDisplayRowStaysBlockedWithoutBaseFallback(): void
    {
        $prices = new PriceDouble(cleared: ['USD']);
        $provider = $this->provider($prices);

        $result = $provider->assertOrAllow([
            'website_id' => 0,
            'store_id' => 0,
            'offer_id' => 42,
            'currency' => 'USD',
        ]);

        self::assertFalse($result['ok']);
        self::assertSame('price_cleared_at_scope', $result['error_code']);
        self::assertSame(['USD'], $prices->asserted);
    }

    public function testMissingBaseRowAlsoBlocksDisplayCurrency(): void
    {
        // Display row missing too (no USD entry in `missing` list means USD throws first;
        // use both so the base fallback is attempted and also fails).
        $prices = new PriceDouble(missing: ['USD', 'CNY']);
        $provider = $this->provider($prices);

        $result = $provider->assertOrAllow([
            'website_id' => 0,
            'store_id' => 0,
            'offer_id' => 42,
            'currency' => 'USD',
        ]);

        self::assertFalse($result['ok']);
        self::assertSame('price_missing', $result['error_code']);
        self::assertSame(['USD', 'CNY'], $prices->asserted);
    }

    private function provider(PriceDouble $prices): ProductCartPriceSellabilityProvider
    {
        \Weline\Framework\Manager\ObjectManager::setInstance(
            \Weline\Currency\Service\CurrencyRateService::class,
            new RateDouble(),
        );

        return new ProductCartPriceSellabilityProvider($prices);
    }
}

/**
 * Duck-typed stand-in for the final PriceRepository (only assertSellable is used).
 */
final class PriceDouble
{
    /** @var list<string> */
    public array $asserted = [];

    /**
     * @param list<string> $cleared currencies that throw price_cleared_at_scope
     * @param list<string> $missing currencies that throw price_missing
     */
    public function __construct(
        private readonly array $cleared = [],
        private readonly array $missing = [],
    ) {
    }

    public function assertSellable(int $websiteId, int $storeId, int $offerId, string $currency): int
    {
        $this->asserted[] = $currency;
        if (in_array($currency, $this->missing, true)) {
            throw new CatalogConflictException('price_missing', 'missing', []);
        }
        if (in_array($currency, $this->cleared, true)) {
            throw new CatalogConflictException('price_cleared_at_scope', 'cleared', []);
        }

        return 10000;
    }
}

/**
 * CNY base with a live USD rate; no DB reads.
 */
final class RateDouble
{
    public function getBaseCurrency(): string
    {
        return 'CNY';
    }

    public function tryConvert(float $amount, string $from, string $to): ?float
    {
        return ['CNY', 'USD'] === [$from, $to] ? $amount / 7.0 : null;
    }
}
