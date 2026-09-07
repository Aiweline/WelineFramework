<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cart\Api\CommerceCartTypeInterface;
use Weline\Cart\Api\Data\OfferIdentity;
use Weline\Cart\Service\CartConflictException;
use Weline\Cart\Service\CartItemSnapshotProviderRegistry;
use Weline\Cart\Service\CartService;
use Weline\Cart\Service\CommerceCartTypeRegistry;
use Weline\Cart\Service\SellingTypeResolver;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Product\Extends\Module\Weline_Cart\CartItemSnapshotProvider\ProductCartItemSnapshotProvider;

final class CartServiceCommerceTypeTest extends TestCase
{
    private function scope(): ScopeIdentity
    {
        return ScopeIdentity::store(0, 'default', 'a', ScopeIdentity::MODE_NORMAL);
    }

    private function tobProvider(): CommerceCartTypeInterface
    {
        return new class implements CommerceCartTypeInterface {
            public function getCode(): string
            {
                return 'tob';
            }

            public function getLabel(): string
            {
                return '批发';
            }

            public function getBadgeTone(): string
            {
                return 'warning';
            }

            public function requiresCustomerLogin(): bool
            {
                return true;
            }

            public function disablesStorefrontDiscounts(): bool
            {
                return true;
            }
        };
    }

    private function serviceWithTob(array $catalog): CartService
    {
        $provider = ProductCartItemSnapshotProvider::forTesting($catalog);
        $registry = CartItemSnapshotProviderRegistry::forTesting([$provider]);
        $types = CommerceCartTypeRegistry::forTesting([$this->tobProvider()]);

        return CartService::forTesting($registry, $types);
    }

    public function testDefaultCartKeyUsesTocTypeSuffix(): void
    {
        $svc = $this->serviceWithTob([]);
        $guest = $svc->issueGuestToken();
        $key = $svc->cartKeyForTesting($this->scope(), $guest, null);

        self::assertStringEndsWith('|type:toc', $key);
        self::assertStringContainsString('|guest:' . $guest, $key);
    }

    public function testTypedCartKeyIncludesRegisteredCode(): void
    {
        $svc = $this->serviceWithTob([]);
        $key = $svc->cartKeyForTesting($this->scope(), null, 42, 'tob');

        self::assertStringEndsWith('|type:tob', $key);
        self::assertStringContainsString('|customer:42', $key);
    }

    public function testGuestCannotGetTobCart(): void
    {
        $offerUuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $svc = $this->serviceWithTob([
            $offerUuid => [
                'name' => 'Wholesale Offer',
                'unit_price_minor' => 900,
                'currency' => 'CNY',
                'stock' => 10,
                'sellable' => true,
            ],
        ]);
        $guest = $svc->issueGuestToken();
        $offer = new OfferIdentity('product', $offerUuid, legacyProductId: 1);

        try {
            $svc->getCart($this->scope(), $guest, null, 'tob');
            self::fail('guest tob getCart must fail closed');
        } catch (CartConflictException $e) {
            self::assertSame(SellingTypeResolver::ERROR_LOGIN_REQUIRED, $e->errorCode());
        }

        try {
            $svc->add(
                $this->scope(),
                $offer,
                [],
                1,
                $guest,
                cartTypePreference: 'tob',
            );
            self::fail('guest tob add must fail closed');
        } catch (CartConflictException $e) {
            self::assertSame(SellingTypeResolver::ERROR_LOGIN_REQUIRED, $e->errorCode());
        }
    }

    public function testMergeGuestOnlySameTocTypeAndSkipsTobGuest(): void
    {
        $offerUuid = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $svc = $this->serviceWithTob([
            $offerUuid => [
                'name' => 'Merge Offer',
                'unit_price_minor' => 500,
                'currency' => 'CNY',
                'stock' => 20,
                'sellable' => true,
            ],
        ]);
        $scope = $this->scope();
        $offer = new OfferIdentity('product', $offerUuid, legacyProductId: 2);
        $guest = $svc->issueGuestToken();

        $svc->add($scope, $offer, [], 2, $guest);
        $svc->add($scope, $offer, [], 1, null, customerId: 9);
        $merged = $svc->mergeGuestIntoCustomer($scope, $guest, 9);

        self::assertTrue($merged['success']);
        self::assertSame('toc', $merged['cart_type']);
        self::assertSame(3, $merged['item_count']);
        self::assertTrue($svc->getCart($scope, $guest)['is_empty']);

        $guest2 = $svc->issueGuestToken();
        $svc->add($scope, $offer, [], 4, $guest2);
        // tob MOQ/step default 5 — guest tob carts are forbidden; customer tob add uses moq qty
        $svc->add($scope, $offer, [], 5, null, customerId: 11, cartTypePreference: 'tob');

        $tobMerge = $svc->mergeGuestIntoCustomer($scope, $guest2, 11, 'tob');
        self::assertSame('tob', $tobMerge['cart_type']);
        self::assertSame(5, $tobMerge['item_count']);
        self::assertFalse($tobMerge['quantity_truncated']);
        // toc guest cart must remain — tob merge must not swallow it
        self::assertSame(4, $svc->getCart($scope, $guest2)['item_count']);
        self::assertSame(5, $svc->getCart($scope, null, 11, 'tob')['item_count']);
    }

    public function testSummaryIncludesCartTypeAndTypePayload(): void
    {
        $offerUuid = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $svc = $this->serviceWithTob([
            $offerUuid => [
                'name' => 'Summary Offer',
                'unit_price_minor' => 100,
                'currency' => 'CNY',
                'stock' => 5,
                'sellable' => true,
            ],
        ]);
        $guest = $svc->issueGuestToken();
        $summary = $svc->add(
            $this->scope(),
            new OfferIdentity('product', $offerUuid, legacyProductId: 3),
            [],
            1,
            $guest,
        );

        self::assertSame('toc', $summary['cart_type']);
        self::assertTrue($summary['type_payload']['discounts_applied']);
        self::assertSame([], $summary['type_payload']['extras']);
        self::assertSame('toc', $summary['items'][0]['cart_type']);

        $tob = $svc->getCart($this->scope(), null, 7, 'tob');
        self::assertSame('tob', $tob['cart_type']);
        self::assertFalse($tob['type_payload']['discounts_applied']);
    }
}
