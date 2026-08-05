<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Cart\Api\Data\OfferIdentity;
use Weline\Cart\Observer\LoginMergeGuestCart;
use Weline\Cart\Service\CartItemSnapshotProviderV2Registry;
use Weline\Cart\Service\CartScopeResolver;
use Weline\Cart\Service\CartService;
use Weline\Cart\Service\CartV2Service;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Request;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Product\Extends\Module\Weline_Cart\CartItemSnapshotProviderV2\ProductCartItemSnapshotProvider;

final class LoginMergeGuestCartTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['weline_cart_test_env'] = [];
        RequestContext::resetWelineVars();
    }

    public function testLoginMergeUsesSharedThreeSegmentScopeResolver(): void
    {
        [$service, $offer] = $this->service();
        $scope = ScopeIdentity::channel(
            0,
            'default',
            'store-a',
            'web',
            ScopeIdentity::MODE_NORMAL,
        );
        $guestToken = $service->issueGuestToken();
        $service->add($scope, $offer, ['size' => 'M'], 2, $guestToken);
        $service->add($scope, $offer, ['size' => 'M'], 1, customerId: 77);

        $observer = new LoginMergeGuestCart(
            $this->cartService($service),
            new CartScopeResolver(),
        );
        $event = $this->event(77, [
            'guest_token' => $guestToken,
            'website_id' => 0,
            'website_code' => 'default',
            'store_code' => 'store-a',
            'channel_code' => 'web',
            'store_mode' => ScopeIdentity::MODE_NORMAL,
        ]);
        $observer->execute($event);

        self::assertSame(3, $service->getCart($scope, customerId: 77)['item_count']);
        self::assertTrue($service->getCart($scope, $guestToken)['is_empty']);
    }

    public function testLoginMergeInheritsTrustedChannelWhenRequestOmitsScopeParams(): void
    {
        RequestContext::installScopeIdentity(ScopeIdentity::channel(
            0,
            'default',
            'default',
            'default',
            ScopeIdentity::MODE_NORMAL,
        ));

        [$service, $offer] = $this->service();
        $scope = ScopeIdentity::channel(
            0,
            'default',
            'default',
            'default',
            ScopeIdentity::MODE_NORMAL,
        );
        $guestToken = $service->issueGuestToken();
        $service->add($scope, $offer, ['size' => 'M'], 2, $guestToken);
        $service->add($scope, $offer, ['size' => 'M'], 1, customerId: 77);

        $observer = new LoginMergeGuestCart(
            $this->cartService($service),
            new CartScopeResolver(),
        );
        $event = $this->event(77, [
            'guest_token' => $guestToken,
        ]);
        $observer->execute($event);

        self::assertSame(3, $service->getCart($scope, customerId: 77)['item_count']);
        self::assertTrue($service->getCart($scope, $guestToken)['is_empty']);
    }

    public function testInvalidChannelScopeFailsClosedWithoutMergingWebsiteCart(): void
    {
        [$service, $offer] = $this->service();
        $websiteScope = ScopeIdentity::website(0, 'default');
        $guestToken = $service->issueGuestToken();
        $service->add($websiteScope, $offer, [], 2, $guestToken);

        $observer = new LoginMergeGuestCart(
            $this->cartService($service),
            new CartScopeResolver(),
        );
        $event = $this->event(77, [
            'guest_token' => $guestToken,
            'website_id' => 0,
            'website_code' => 'default',
            'channel_code' => 'web',
        ]);
        $observer->execute($event);

        self::assertSame(2, $service->getCart($websiteScope, $guestToken)['item_count']);
        self::assertTrue($service->getCart($websiteScope, customerId: 77)['is_empty']);
    }

    /** @return array{CartV2Service, OfferIdentity} */
    private function service(): array
    {
        $offerUuid = '71717171-7171-4717-8717-717171717171';
        $provider = ProductCartItemSnapshotProvider::forTesting([
            $offerUuid => [
                'name' => 'Observer Offer',
                'unit_price_minor' => 500,
                'currency' => 'CNY',
                'stock' => 20,
                'sellable' => true,
            ],
        ]);
        $registry = CartItemSnapshotProviderV2Registry::forTesting([$provider]);

        return [
            CartV2Service::forTesting($registry),
            new OfferIdentity('product', $offerUuid, legacyProductId: 71),
        ];
    }

    private function cartService(CartV2Service $service): CartService
    {
        $cartService = $this->createMock(CartService::class);
        $cartService->method('cartV2')->willReturn($service);
        return $cartService;
    }

    /** @param array<string, mixed> $params */
    private function event(int $customerId, array $params): Event
    {
        $request = new Request();
        $request->setData($params);
        $user = new class($customerId) {
            public function __construct(private readonly int $id)
            {
            }

            public function getId(): int
            {
                return $this->id;
            }
        };

        return new Event([
            'data' => new DataObject([
                'user' => $user,
                'request' => $request,
            ]),
        ]);
    }
}
