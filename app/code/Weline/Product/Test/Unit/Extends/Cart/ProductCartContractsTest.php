<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Extends\Cart;

use PHPUnit\Framework\TestCase;
use Weline\Product\Integration\Cart\ProductCartPriceSellabilityProvider;
use Weline\Product\Service\CatalogConflictException;

/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllProductCartContractsPass().
 */
final class ProductCartContractsTest extends TestCase
{
    public function testAllProductCartContractsPass(): void
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
            'ProductCartPriceSellabilityProviderCurrency::testMissingDisplayRowWithConvertibleBasePriceIsSellable' => function () {
            $this->assert_ProductCartPriceSellabilityProviderCurrency_testMissingDisplayRowWithConvertibleBasePriceIsSellable();
        },
            'ProductCartPriceSellabilityProviderCurrency::testClearedDisplayRowStaysBlockedWithoutBaseFallback' => function () {
            $this->assert_ProductCartPriceSellabilityProviderCurrency_testClearedDisplayRowStaysBlockedWithoutBaseFallback();
        },
            'ProductCartPriceSellabilityProviderCurrency::testMissingBaseRowAlsoBlocksDisplayCurrency' => function () {
            $this->assert_ProductCartPriceSellabilityProviderCurrency_testMissingBaseRowAlsoBlocksDisplayCurrency();
        },
            'ProductCatalogCartQuoteOnlyDeal::testSnapshotResolverRejectsQuoteOnlyAndAppliesActiveDeal' => function () {
            $this->assert_ProductCatalogCartQuoteOnlyDeal_testSnapshotResolverRejectsQuoteOnlyAndAppliesActiveDeal();
        },
            'ProductCatalogCartWeightMinor::testSnapshotResolverMapsCatalogWeightKgToWeightMinor' => function () {
            $this->assert_ProductCatalogCartWeightMinor_testSnapshotResolverMapsCatalogWeightKgToWeightMinor();
        },
        ];
    }

private function assert_ProductCartPriceSellabilityProviderCurrency_testMissingDisplayRowWithConvertibleBasePriceIsSellable(): void
{
    $prices = new PriceDouble(missing: ['USD']);
    $provider = $this->provider_ProductCartPriceSellabilityProviderCurrency($prices);
    $result = $provider->assertOrAllow(['website_id' => 0, 'store_id' => 0, 'offer_id' => 42, 'currency' => 'USD']);
    self::assertTrue($result['ok'], json_encode($result));
    self::assertSame(['USD', 'CNY'], $prices->asserted);
}

private function assert_ProductCartPriceSellabilityProviderCurrency_testClearedDisplayRowStaysBlockedWithoutBaseFallback(): void
{
    $prices = new PriceDouble(cleared: ['USD']);
    $provider = $this->provider_ProductCartPriceSellabilityProviderCurrency($prices);
    $result = $provider->assertOrAllow(['website_id' => 0, 'store_id' => 0, 'offer_id' => 42, 'currency' => 'USD']);
    self::assertFalse($result['ok']);
    self::assertSame('price_cleared_at_scope', $result['error_code']);
    self::assertSame(['USD'], $prices->asserted);
}

private function assert_ProductCartPriceSellabilityProviderCurrency_testMissingBaseRowAlsoBlocksDisplayCurrency(): void
{
    // Display row missing too (no USD entry in `missing` list means USD throws first;
    // use both so the base fallback is attempted and also fails).
    $prices = new PriceDouble(missing: ['USD', 'CNY']);
    $provider = $this->provider_ProductCartPriceSellabilityProviderCurrency($prices);
    $result = $provider->assertOrAllow(['website_id' => 0, 'store_id' => 0, 'offer_id' => 42, 'currency' => 'USD']);
    self::assertFalse($result['ok']);
    self::assertSame('price_missing', $result['error_code']);
    self::assertSame(['USD', 'CNY'], $prices->asserted);
}

private function provider_ProductCartPriceSellabilityProviderCurrency(PriceDouble $prices): ProductCartPriceSellabilityProvider
{
    \Weline\Framework\Manager\ObjectManager::setInstance(\Weline\Currency\Service\CurrencyRateService::class, new RateDouble());
    return new ProductCartPriceSellabilityProvider($prices);
}

private function assert_ProductCatalogCartQuoteOnlyDeal_testSnapshotResolverRejectsQuoteOnlyAndAppliesActiveDeal(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/extends/module/Weline_Cart/CartItemSnapshotProvider/ProductCatalogCartItemSnapshotResolver.php';
    self::assertFileExists($path);
    $content = (string) file_get_contents($path);
    self::assertStringContainsString('function isQuoteOnly(', $content);
    self::assertStringContainsString('仅询价，不可加入购物车', $content);
    self::assertStringContainsString('function applyActiveDealMinor(', $content);
    self::assertStringContainsString('StorefrontOfferPriceAssemblerInterface', $content);
    self::assertStringNotContainsString('PromotionStorefrontActiveDealResolver', $content);
}

private function assert_ProductCatalogCartWeightMinor_testSnapshotResolverMapsCatalogWeightKgToWeightMinor(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/extends/module/Weline_Cart/CartItemSnapshotProvider/ProductCatalogCartItemSnapshotResolver.php';
    self::assertFileExists($path);
    $content = (string) file_get_contents($path);
    self::assertStringContainsString('function weightMinorFromCatalog(', $content);
    self::assertStringContainsString("'weight_kg'", $content);
    self::assertStringContainsString('weightMinor: $weightMinor', $content);
    self::assertStringContainsString('round($kg * 1000)', $content);
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
    public function __construct(private readonly array $cleared = [], private readonly array $missing = [])
    {
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
