<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cart\Api\Data\CartItemSnapshot;
use Weline\Cart\Api\Data\OfferIdentity;
use Weline\Cart\Api\CartItemSnapshotProviderV2Interface;
use Weline\Cart\Service\CartItemSnapshotProviderV2Registry;
use Weline\Cart\Service\CartSelectionHash;
use Weline\Cart\Service\CheckoutCartSnapshotService;
use Weline\Cart\Service\CartV2ConflictException;
use Weline\Cart\Service\CartV2Service;
use Weline\Cart\Service\LegacyCartItemSnapshotProviderV2Adapter;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Product\Extends\Module\Weline_Cart\CartItemSnapshotProviderV2\ProductCartItemSnapshotProvider;

/**
 * TEST-P2E-01 / TEST-P2E-02 / TEST-P2E-03.
 */
final class CartV2ServiceTest extends TestCase
{
    private function scopeA(): ScopeIdentity
    {
        return ScopeIdentity::store(0, 'default', 'a', ScopeIdentity::MODE_NORMAL);
    }

    private function scopeB(): ScopeIdentity
    {
        return ScopeIdentity::store(0, 'default', 'b', ScopeIdentity::MODE_NORMAL);
    }

    private function service(array $catalog): CartV2Service
    {
        $provider = ProductCartItemSnapshotProvider::forTesting($catalog);
        $registry = CartItemSnapshotProviderV2Registry::forTesting([$provider]);
        return CartV2Service::forTesting($registry);
    }

    public function testScopeIsolationDoesNotLeakAcrossCarts(): void
    {
        $offerUuid = '11111111-1111-4111-8111-111111111111';
        $catalog = [
            $offerUuid => [
                'name' => 'Offer A',
                'unit_price_minor' => 1000,
                'currency' => 'CNY',
                'stock' => 10,
                'sellable' => true,
            ],
        ];
        $svc = $this->service($catalog);
        $offer = new OfferIdentity('product', $offerUuid, legacyProductId: 1);
        $guestA = $svc->issueGuestToken();
        $guestB = $svc->issueGuestToken();

        $svc->add($this->scopeA(), $offer, [], 1, $guestA);
        $svc->add($this->scopeB(), $offer, [], 2, $guestB);

        $cartA = $svc->getCart($this->scopeA(), $guestA);
        $cartB = $svc->getCart($this->scopeB(), $guestB);
        self::assertSame(1, $cartA['item_count']);
        self::assertSame(2, $cartB['item_count']);
        self::assertNotSame($cartA['scope_key'], $cartB['scope_key']);
        self::assertSame(1, $svc->cartCountForScope($this->scopeA()));
        self::assertSame(1, $svc->cartCountForScope($this->scopeB()));

        $cleared = $svc->clearCart($this->scopeA(), $guestA);
        self::assertTrue($cleared['is_empty']);
        self::assertSame(0, $svc->cartCountForScope($this->scopeA()));
        self::assertSame(1, $svc->cartCountForScope($this->scopeB()));
    }

    public function testSameGuestTokenRemainsIsolatedAcrossThreeSegmentScopes(): void
    {
        $offerUuid = '15151515-1515-4151-8151-151515151515';
        $svc = $this->service([
            $offerUuid => [
                'name' => 'Scoped Offer',
                'unit_price_minor' => 750,
                'currency' => 'CNY',
                'stock' => 10,
                'sellable' => true,
            ],
        ]);
        $offer = new OfferIdentity('product', $offerUuid, legacyProductId: 15);
        $guestToken = $svc->issueGuestToken();
        $scopeA = ScopeIdentity::channel(
            0,
            'default',
            'store-a',
            'web',
            ScopeIdentity::MODE_NORMAL,
        );
        $scopeB = ScopeIdentity::channel(
            0,
            'default',
            'store-b',
            'app',
            ScopeIdentity::MODE_NORMAL,
        );

        $svc->add($scopeA, $offer, [], 1, $guestToken);
        $svc->add($scopeB, $offer, [], 3, $guestToken);

        self::assertSame(1, $svc->getCart($scopeA, $guestToken)['item_count']);
        self::assertSame(3, $svc->getCart($scopeB, $guestToken)['item_count']);
        self::assertNotSame($scopeA->canonicalKey(), $scopeB->canonicalKey());
    }

    public function testGuestLoginMergeSameSelectionHashAndCapStock(): void
    {
        $offerUuid = '22222222-2222-4222-8222-222222222222';
        $catalog = [
            $offerUuid => [
                'name' => 'Limited',
                'unit_price_minor' => 500,
                'currency' => 'CNY',
                'stock' => 5,
                'sellable' => true,
            ],
        ];
        $svc = $this->service($catalog);
        $offer = new OfferIdentity('product', $offerUuid, 2);
        $guest = $svc->issueGuestToken();
        $scope = $this->scopeA();

        $svc->add($scope, $offer, ['color' => 'red'], 4, $guest);
        $svc->add($scope, $offer, ['color' => 'red'], 2, null, customerId: 9);
        $merged = $svc->mergeGuestIntoCustomer($scope, $guest, 9);

        self::assertTrue($merged['success']);
        self::assertTrue($merged['quantity_truncated']);
        self::assertSame(5, $merged['item_count']); // 4+2 capped at stock 5
        self::assertSame(1, $merged['distinct_count']);
        $guestAfter = $svc->getCart($scope, $guest);
        self::assertTrue($guestAfter['is_empty']);
    }

    public function testServerRejectsForgedSelectionHashAndInvalidSelection(): void
    {
        $offerUuid = '33333333-3333-4333-8333-333333333333';
        $catalog = [
            $offerUuid => [
                'name' => 'Hash',
                'unit_price_minor' => 100,
                'currency' => 'CNY',
                'stock' => 9,
                'sellable' => true,
            ],
        ];
        $svc = $this->service($catalog);
        $offer = new OfferIdentity('product', $offerUuid, 3);
        $guest = $svc->issueGuestToken();
        $selection = ['size' => 'M'];
        $good = CartSelectionHash::compute($offerUuid, OfferIdentity::SELECTION_SCHEMA_V1, $selection);

        $ok = $svc->add($this->scopeA(), $offer, $selection, 1, $guest, clientSelectionHash: $good);
        self::assertTrue($ok['success']);
        self::assertSame($good, $ok['selection_hash']);

        try {
            $svc->add($this->scopeA(), $offer, $selection, 1, $guest, clientSelectionHash: 'deadbeef');
            self::fail('forged hash must fail');
        } catch (CartV2ConflictException $e) {
            self::assertSame(CartSelectionHash::ERROR_HASH_MISMATCH, $e->errorCode());
        }

        try {
            $svc->add($this->scopeA(), $offer, ['bad' => ['nested']], 1, $guest);
            self::fail('nested selection must fail');
        } catch (CartV2ConflictException $e) {
            self::assertSame(CartSelectionHash::ERROR_INVALID_SELECTION, $e->errorCode());
        }
    }

    public function testCrossCurrencyMergeFailsBeforeEitherCartIsMutated(): void
    {
        $cnyOfferUuid = '56565656-5656-4565-8565-565656565656';
        $usdOfferUuid = '57575757-5757-4575-8575-575757575757';
        $svc = $this->service([
            $cnyOfferUuid => [
                'name' => 'CNY Offer',
                'unit_price_minor' => 100,
                'currency' => 'CNY',
                'stock' => 5,
                'sellable' => true,
            ],
            $usdOfferUuid => [
                'name' => 'USD Offer',
                'unit_price_minor' => 200,
                'currency' => 'USD',
                'stock' => 5,
                'sellable' => true,
            ],
        ]);
        $scope = $this->scopeA();
        $guestToken = $svc->issueGuestToken();
        $svc->add(
            $scope,
            new OfferIdentity('product', $cnyOfferUuid, legacyProductId: 56),
            [],
            2,
            $guestToken,
        );
        $svc->add(
            $scope,
            new OfferIdentity('product', $usdOfferUuid, legacyProductId: 57),
            [],
            1,
            customerId: 9,
        );

        try {
            $svc->mergeGuestIntoCustomer($scope, $guestToken, 9);
            self::fail('cross-currency merge must fail');
        } catch (CartV2ConflictException $exception) {
            self::assertSame(CartV2Service::ERROR_CROSS_CURRENCY, $exception->errorCode());
        }

        $guestAfter = $svc->getCart($scope, $guestToken);
        $customerAfter = $svc->getCart($scope, customerId: 9);
        self::assertSame(2, $guestAfter['item_count']);
        self::assertSame('CNY', $guestAfter['currency']);
        self::assertSame(1, $customerAfter['item_count']);
        self::assertSame('USD', $customerAfter['currency']);
    }

    public function testDuplicateProviderCodeFailsClosedAndLegacyAdapterFallback(): void
    {
        $a = ProductCartItemSnapshotProvider::forTesting();
        $b = ProductCartItemSnapshotProvider::forTesting(['x' => ['name' => 'x', 'unit_price_minor' => 1]]);
        try {
            CartItemSnapshotProviderV2Registry::forTesting([$a, $b]);
            self::fail('duplicate code');
        } catch (CartV2ConflictException $e) {
            self::assertSame(CartItemSnapshotProviderV2Registry::ERROR_CODE_DUPLICATE, $e->errorCode());
        }

        $legacy = LegacyCartItemSnapshotProviderV2Adapter::forTesting(
            static function (int $productId, array $params): array {
                return [
                    'name' => 'Legacy #' . $productId,
                    'price' => 12.34,
                    'sellable' => true,
                    'stock' => 3,
                ];
            }
        );
        $registry = CartItemSnapshotProviderV2Registry::forTesting([], $legacy);
        // unknown provider code but legacyProductId present → adapter
        $offer = new OfferIdentity('missing_provider', '44444444-4444-4444-8444-444444444444', 42);
        $snap = $registry->resolve($offer, $this->scopeA(), []);
        self::assertSame('Legacy #42', $snap->name);
        self::assertSame(1234, $snap->unitPriceMinor);
    }

    public function testCheckoutFreezeRepricesAndExportsOnlyCurrentProviderFacts(): void
    {
        $offerUuid = '77777777-7777-4777-8777-777777777777';
        $current = [
            'unit_price_minor' => 1000,
            'stock' => 5,
            'sellable' => true,
        ];
        $provider = new class($offerUuid, $current) implements CartItemSnapshotProviderV2Interface {
            /** @var array<string, mixed> */
            public array $current;

            /** @param array<string, mixed> $current */
            public function __construct(
                private readonly string $offerUuid,
                array $current,
            ) {
                $this->current = $current;
            }

            public function getProviderCode(): string
            {
                return 'product';
            }

            public function resolveCartItemSnapshot(
                OfferIdentity $offer,
                ScopeIdentity $scope,
                array $selection = [],
            ): ?CartItemSnapshot {
                if ($offer->globalOfferUuid !== $this->offerUuid) {
                    return null;
                }

                return new CartItemSnapshot(
                    offer: $offer,
                    name: 'Server Product',
                    sku: 'SERVER-SKU',
                    currency: 'CNY',
                    unitPriceMinor: (int)$this->current['unit_price_minor'],
                    found: true,
                    sellable: (bool)$this->current['sellable'],
                    stock: (int)$this->current['stock'],
                    selection: $selection,
                    offerId: 701,
                    productId: 70,
                    splitKey: 'vendor-7',
                    legalEntity: 'entity-7',
                    requiresShipping: true,
                    weightMinor: 250,
                    volumeMinor: 1250,
                    taxClassCode: 'reduced',
                );
            }
        };
        $registry = CartItemSnapshotProviderV2Registry::forTesting([$provider]);
        $cart = CartV2Service::forTesting($registry);
        $scope = $this->scopeA();
        $guest = $cart->issueGuestToken();
        $cart->add(
            $scope,
            new OfferIdentity('product', $offerUuid, legacyProductId: 7),
            ['size' => 'L'],
            2,
            $guest,
        );
        $provider->current['unit_price_minor'] = 1250;
        $provider->current['stock'] = 2;

        $snapshot = (new CheckoutCartSnapshotService($cart))->freeze($scope, $guest);
        self::assertSame('CNY', $snapshot['currency']);
        self::assertSame('store', $snapshot['scope']['scope_kind']);
        self::assertSame('a', $snapshot['scope']['store_code']);
        self::assertSame(1250, $snapshot['lines'][0]['unit_price_minor']);
        self::assertSame(2500, $snapshot['lines'][0]['row_total_minor']);
        self::assertSame(701, $snapshot['lines'][0]['offer_id']);
        self::assertSame(70, $snapshot['lines'][0]['product_id']);
        self::assertSame('vendor-7', $snapshot['lines'][0]['split_key']);
        self::assertSame(500, $snapshot['lines'][0]['weight_minor']);
        self::assertSame(2500, $snapshot['lines'][0]['volume_minor']);
        self::assertSame('reduced', $snapshot['lines'][0]['tax_class_code']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $snapshot['cart_hash']);

        $provider->current['sellable'] = false;
        try {
            (new CheckoutCartSnapshotService($cart))->freeze($scope, $guest);
            self::fail('current unsellable provider fact must block checkout');
        } catch (CartV2ConflictException $e) {
            self::assertSame(CheckoutCartSnapshotService::ERROR_SELLABILITY, $e->errorCode());
        }
    }
}
