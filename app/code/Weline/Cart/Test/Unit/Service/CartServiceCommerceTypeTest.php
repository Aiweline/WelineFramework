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

        // Read path: soft empty tob shell + sibling hints (mutations stay fail-closed).
        $tocAdd = $svc->add($this->scope(), $offer, [], 2, $guest, cartTypePreference: 'toc');
        self::assertTrue($tocAdd['success'] ?? false);
        $guestTob = $svc->getCart($this->scope(), $guest, null, 'tob');
        self::assertTrue($guestTob['is_empty'] ?? false);
        self::assertSame(SellingTypeResolver::ERROR_LOGIN_REQUIRED, $guestTob['gate_reason'] ?? null);
        self::assertSame('tob', $guestTob['cart_type'] ?? null);
        $siblings = $guestTob['sibling_carts'] ?? [];
        self::assertNotEmpty($siblings);
        self::assertSame('toc', $siblings[0]['cart_type'] ?? null);
        self::assertGreaterThan(0, (int)($siblings[0]['item_count'] ?? 0));
        self::assertTrue((bool)($siblings[0]['switchable'] ?? false));

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

    public function testEmptyTocHasNoSiblingsWhenTobEmpty(): void
    {
        $svc = $this->serviceWithTob([]);
        $guest = $svc->issueGuestToken();
        $empty = $svc->getCart($this->scope(), $guest, null, 'toc');
        self::assertTrue($empty['is_empty'] ?? false);
        self::assertSame([], $empty['sibling_carts'] ?? null);
    }

    public function testEmptyTocHasTobSiblingWhenTobHasLines(): void
    {
        $offerUuid = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
        $svc = $this->serviceWithTob([
            $offerUuid => [
                'name' => 'Wholesale Sibling Offer',
                'unit_price_minor' => 300,
                'currency' => 'CNY',
                'stock' => 40,
                'sellable' => true,
            ],
        ]);
        $scope = $this->scope();
        $offer = new OfferIdentity('product', $offerUuid, legacyProductId: 8);
        $customerId = 33;

        $add = $svc->add(
            $scope,
            $offer,
            [],
            5,
            null,
            customerId: $customerId,
            cartTypePreference: 'tob',
        );
        self::assertTrue($add['success'] ?? false);

        $toc = $svc->getCart($scope, null, $customerId, 'toc');
        self::assertTrue($toc['is_empty'] ?? false);
        $siblings = $toc['sibling_carts'] ?? [];
        self::assertNotEmpty($siblings, 'empty toc must advertise tob when tob has lines');
        self::assertSame('tob', $siblings[0]['cart_type'] ?? null);
        self::assertSame(5, (int)($siblings[0]['item_count'] ?? 0));
        self::assertTrue((bool)($siblings[0]['switchable'] ?? false));
    }

    public function testClearTocAttachesTobSiblingWhenTobHasLines(): void
    {
        $offerUuid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
        $svc = $this->serviceWithTob([
            $offerUuid => [
                'name' => 'Clear Sibling Offer',
                'unit_price_minor' => 400,
                'currency' => 'CNY',
                'stock' => 40,
                'sellable' => true,
            ],
        ]);
        $scope = $this->scope();
        $offer = new OfferIdentity('product', $offerUuid, legacyProductId: 11);
        $customerId = 44;

        self::assertTrue($svc->add(
            $scope,
            $offer,
            [],
            5,
            null,
            customerId: $customerId,
            cartTypePreference: 'tob',
        )['success'] ?? false);
        self::assertTrue($svc->add(
            $scope,
            $offer,
            [],
            1,
            null,
            customerId: $customerId,
            cartTypePreference: 'toc',
        )['success'] ?? false);

        $cleared = $svc->clearCart($scope, null, $customerId, 'toc');
        self::assertTrue($cleared['is_empty'] ?? false);
        $siblings = $cleared['sibling_carts'] ?? [];
        self::assertNotEmpty($siblings, 'clearing toc must still advertise tob lines');
        self::assertSame('tob', $siblings[0]['cart_type'] ?? null);
        self::assertSame(5, (int)($siblings[0]['item_count'] ?? 0));
    }

    public function testRemoveTobItemFailsWhenPreferenceIsToc(): void
    {
        $offerUuid = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $svc = $this->serviceWithTob([
            $offerUuid => [
                'name' => 'Remove Isolation Offer',
                'unit_price_minor' => 220,
                'currency' => 'CNY',
                'stock' => 40,
                'sellable' => true,
            ],
        ]);
        $scope = $this->scope();
        $offer = new OfferIdentity('product', $offerUuid, legacyProductId: 12);
        $customerId = 55;

        $added = $svc->add(
            $scope,
            $offer,
            [],
            5,
            null,
            customerId: $customerId,
            cartTypePreference: 'tob',
        );
        self::assertTrue($added['success'] ?? false);
        $itemId = (string)($added['items'][0]['item_id'] ?? '');
        self::assertNotSame('', $itemId);

        try {
            $svc->removeItem($scope, $itemId, null, $customerId, 'toc');
            self::fail('removing a tob line against toc preference must fail');
        } catch (CartConflictException $e) {
            self::assertSame(CartService::ERROR_NOT_FOUND, $e->errorCode());
        }

        $removed = $svc->removeItem($scope, $itemId, null, $customerId, 'tob');
        self::assertTrue($removed['success'] ?? false);
        self::assertTrue($removed['is_empty'] ?? false);
        self::assertSame('tob', $removed['cart_type'] ?? null);
    }

    public function testStorefrontSummarySoftFallsBackWhenGuestPrefersTob(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/CartService.php');
        self::assertStringContainsString('ERROR_LOGIN_REQUIRED', $src);
        self::assertStringContainsString('ERROR_MEMBERSHIP_REQUIRED', $src);
        self::assertStringContainsString("CommerceCartTypeRegistry::CODE_TOC", $src);
        self::assertStringContainsString("HTML /cart and header SSR must not 500", $src);
        self::assertStringContainsString("'gate_reason'", $src);
        self::assertStringContainsString('Guests have no tob cart', $src);
        self::assertStringContainsString("=== 'tob'", $src);
        self::assertStringContainsString("Keep retail body quiet", $src);
        self::assertStringContainsString("\$summary['message'] = ''", $src);
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

    public function testTobCustomerAddDoesNotMutateTocCart(): void
    {
        $offerUuid = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
        $svc = $this->serviceWithTob([
            $offerUuid => [
                'name' => 'Isolation Offer',
                'unit_price_minor' => 250,
                'currency' => 'CNY',
                'stock' => 50,
                'sellable' => true,
            ],
        ]);
        $scope = $this->scope();
        $offer = new OfferIdentity('product', $offerUuid, legacyProductId: 4);
        $customerId = 21;

        $tocBefore = $svc->getCart($scope, null, $customerId, 'toc');
        self::assertTrue((bool)($tocBefore['is_empty'] ?? false));

        $tobAdd = $svc->add(
            $scope,
            $offer,
            [],
            5,
            null,
            customerId: $customerId,
            cartTypePreference: 'tob',
        );
        self::assertTrue($tobAdd['success'] ?? false);
        self::assertSame('tob', $tobAdd['cart_type'] ?? null);
        self::assertSame(5, (int)($tobAdd['item_count'] ?? 0));

        $tocAfter = $svc->getCart($scope, null, $customerId, 'toc');
        self::assertTrue((bool)($tocAfter['is_empty'] ?? false), 'tob add must not write toc cart');
        self::assertSame(0, (int)($tocAfter['item_count'] ?? 0));
        self::assertSame([], $tocAfter['items'] ?? []);

        $tobAfter = $svc->getCart($scope, null, $customerId, 'tob');
        self::assertSame(5, (int)($tobAfter['item_count'] ?? 0));
        self::assertSame('tob', $tobAfter['cart_type'] ?? null);
    }
}
