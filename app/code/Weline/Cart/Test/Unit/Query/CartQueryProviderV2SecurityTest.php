<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\Cart\Api\Data\OfferIdentity;
use Weline\Cart\Extends\Module\Weline_Framework\Query\CartQueryProvider;
use Weline\Cart\Service\CartCurrentCustomerResolver;
use Weline\Cart\Service\CartItemSnapshotProviderV2Registry;
use Weline\Cart\Service\CartScopeResolver;
use Weline\Cart\Service\CartService;
use Weline\Cart\Service\CartV2Service;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Product\Extends\Module\Weline_Cart\CartItemSnapshotProviderV2\ProductCartItemSnapshotProvider;

final class CartQueryProviderV2SecurityTest extends TestCase
{
    public function testFrontendDescriptorDoesNotExposeCustomerCartOwner(): void
    {
        $query = new CartQueryProvider(
            $this->createMock(CartService::class),
            new CartScopeResolver(),
            new CartCurrentCustomerResolver(static fn(): ?int => null),
        );
        $operations = [];
        foreach ($query->getDescriptor()['operations'] as $operation) {
            $operations[(string)$operation['name']] = $operation;
        }

        foreach (['add', 'addV2', 'mergeGuest', 'getV2Cart'] as $operationName) {
            self::assertArrayNotHasKey(
                'customer_id',
                $operations[$operationName]['params'],
                $operationName . ' must not expose customer_id to the browser',
            );
        }
        foreach (['addV2', 'mergeGuest', 'getV2Cart'] as $operationName) {
            self::assertArrayHasKey('store_code', $operations[$operationName]['params']);
            self::assertArrayHasKey('channel_code', $operations[$operationName]['params']);
            self::assertArrayHasKey('scope', $operations[$operationName]['params']);
        }
    }

    public function testAddV2ReplacesBrowserCustomerIdWithAuthenticatedIdentity(): void
    {
        $cartService = $this->createMock(CartService::class);
        $cartService->expects(self::once())
            ->method('add')
            ->with(self::callback(static function (array $params): bool {
                return ($params['customer_id'] ?? null) === 77
                    && ($params['provider_code'] ?? null) === 'product';
            }))
            ->willReturn([
                'success' => true,
                'message' => 'ok',
                'item_count' => 1,
            ]);
        $query = new CartQueryProvider(
            $cartService,
            new CartScopeResolver(),
            new CartCurrentCustomerResolver(static fn(): ?int => 77),
        );

        $result = $query->execute('addV2', [
            'global_offer_uuid' => '61616161-6161-4616-8616-616161616161',
            'customer_id' => 999,
        ]);

        self::assertTrue($result['success']);
        self::assertSame(1, $result['item_count']);
    }

    public function testGuestCannotReadOrMergeCustomerCartBySupplyingCustomerId(): void
    {
        [$v2, $offer] = $this->service();
        $scope = $this->channelScope();
        $guestToken = $v2->issueGuestToken();
        $v2->add($scope, $offer, [], 2, $guestToken);
        $v2->add($scope, $offer, [], 4, customerId: 99);

        $cartService = $this->createMock(CartService::class);
        $cartService->method('cartV2')->willReturn($v2);
        $query = new CartQueryProvider(
            $cartService,
            new CartScopeResolver(),
            new CartCurrentCustomerResolver(static fn(): ?int => null),
        );
        $params = $this->flatScopeParams() + [
            'guest_token' => $guestToken,
            'customer_id' => 99,
        ];

        $read = $query->execute('getV2Cart', $params);
        self::assertTrue($read['success']);
        self::assertSame(CartV2Service::OWNER_GUEST, $read['owner_kind']);
        self::assertSame(2, $read['item_count']);
        self::assertSame($scope->canonicalKey(), $read['scope_key']);

        $merge = $query->execute('mergeGuest', $params);
        self::assertFalse($merge['success']);
        self::assertSame(CartCurrentCustomerResolver::ERROR_AUTH_REQUIRED, $merge['error_code']);
        self::assertSame(2, $v2->getCart($scope, $guestToken)['item_count']);
        self::assertSame(4, $v2->getCart($scope, customerId: 99)['item_count']);
    }

    public function testAuthenticatedMergeUsesCurrentCustomerAndFlatChannelScope(): void
    {
        [$v2, $offer] = $this->service();
        $scope = $this->channelScope();
        $guestToken = $v2->issueGuestToken();
        $v2->add($scope, $offer, ['size' => 'M'], 2, $guestToken);
        $v2->add($scope, $offer, ['size' => 'M'], 1, customerId: 77);

        $cartService = $this->createMock(CartService::class);
        $cartService->method('cartV2')->willReturn($v2);
        $query = new CartQueryProvider(
            $cartService,
            new CartScopeResolver(),
            new CartCurrentCustomerResolver(static fn(): ?int => 77),
        );
        $params = $this->flatScopeParams() + [
            'guest_token' => $guestToken,
            'customer_id' => 999,
        ];

        $merged = $query->execute('mergeGuest', $params);
        self::assertTrue($merged['success']);
        self::assertSame(CartV2Service::OWNER_CUSTOMER, $merged['owner_kind']);
        self::assertSame('77', $merged['owner_id']);
        self::assertSame(3, $merged['item_count']);
        self::assertSame($scope->canonicalKey(), $merged['scope_key']);
        self::assertTrue($v2->getCart($scope, $guestToken)['is_empty']);
    }

    /**
     * @return array{CartV2Service, OfferIdentity}
     */
    private function service(): array
    {
        $offerUuid = '62626262-6262-4626-8626-626262626262';
        $provider = ProductCartItemSnapshotProvider::forTesting([
            $offerUuid => [
                'name' => 'Secure Offer',
                'unit_price_minor' => 300,
                'currency' => 'CNY',
                'stock' => 20,
                'sellable' => true,
            ],
        ]);
        $registry = CartItemSnapshotProviderV2Registry::forTesting([$provider]);
        return [
            CartV2Service::forTesting($registry),
            new OfferIdentity('product', $offerUuid, legacyProductId: 62),
        ];
    }

    private function channelScope(): ScopeIdentity
    {
        return ScopeIdentity::channel(
            0,
            'default',
            'store-a',
            'web',
            ScopeIdentity::MODE_NORMAL,
        );
    }

    /** @return array<string, mixed> */
    private function flatScopeParams(): array
    {
        return [
            'website_id' => 0,
            'website_code' => 'default',
            'store_code' => 'store-a',
            'channel_code' => 'web',
            'store_mode' => ScopeIdentity::MODE_NORMAL,
        ];
    }
}
