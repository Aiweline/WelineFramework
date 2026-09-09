<?php

declare(strict_types=1);

namespace {
    if (!function_exists('w_env_set')) {
        function w_env_set(string $key, mixed $value, string $reason = ''): void
        {
            \Weline\Framework\Env\WelineEnv::set($key, $value, $reason);
        }
    }

    if (!function_exists('w_env_cookie')) {
        function w_env_cookie(?string $key = null, mixed $default = null): mixed
        {
            return \Weline\Framework\Env\WelineEnv::getCookie($key, $default);
        }
    }
}

namespace Weline\Cart\Test\Unit\Query {

use PHPUnit\Framework\TestCase;
use Weline\Cart\Api\Data\OfferIdentity;
use Weline\Cart\Extends\Module\Weline_Framework\Query\CartQueryProvider;
use Weline\Cart\Service\CartCurrentCustomerResolver;
use Weline\Cart\Service\CartItemSnapshotProviderRegistry;
use Weline\Cart\Service\CartScopeResolver;
use Weline\Cart\Service\CartService;
use Weline\Framework\Context;
use Weline\Framework\Http\CookieScope;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Product\Extends\Module\Weline_Cart\CartItemSnapshotProvider\ProductCartItemSnapshotProvider;

final class CartQueryProviderSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        CookieScope::setPolicyResolverOverride(static fn(): array => [
            'active' => true,
            'name_suffix' => '_w7',
            'name_suffix_pattern' => '/_w\d+$/',
            'mount_path' => '/',
            'expire_unscoped_aliases' => true,
            'revision' => 'cart-query-provider-security-test',
        ]);
        Context::current()->set('input.cookie', []);
        $GLOBALS['weline_cart_test_env'] = [];
    }

    protected function tearDown(): void
    {
        Context::current()->set('input.cookie', []);
        $GLOBALS['weline_cart_test_env'] = [];
        CookieScope::setPolicyResolverOverride(null);
    }

    public function testIssueGuestTokenReusesTrustedScopedCookie(): void
    {
        $existingToken = str_repeat('a', 64);
        Context::current()->set('input.cookie', [
            CartService::GUEST_TOKEN_COOKIE . '_w7' => $existingToken,
        ]);
        $GLOBALS['weline_cart_test_env']['cookie.' . CartService::GUEST_TOKEN_COOKIE . '_w7'] = $existingToken;
        $query = new CartQueryProvider(
            CartService::forTesting(CartItemSnapshotProviderRegistry::forTesting()),
            new CartScopeResolver(),
            new CartCurrentCustomerResolver(static fn(): ?int => null),
        );

        $result = $query->execute('issueGuestToken');
        $payload = is_array($result['data'] ?? null) ? $result['data'] : $result;

        self::assertTrue($result['success']);
        self::assertSame($existingToken, $payload['guest_token']);
    }

    public function testFrontendDescriptorDoesNotExposeCustomerCartOwner(): void
    {
        $query = new CartQueryProvider(
            CartService::forTesting(CartItemSnapshotProviderRegistry::forTesting()),
            new CartScopeResolver(),
            new CartCurrentCustomerResolver(static fn(): ?int => null),
        );
        $operations = [];
        foreach ($query->getDescriptor()['operations'] as $operation) {
            $operations[(string)$operation['name']] = $operation;
        }

        foreach (['add', 'mergeGuest', 'getCart', 'update', 'remove', 'clear'] as $operationName) {
            self::assertArrayHasKey($operationName, $operations, $operationName . ' missing');
            self::assertArrayNotHasKey(
                'customer_id',
                $operations[$operationName]['params'],
                $operationName . ' must not expose customer_id to the browser',
            );
        }
        foreach (['add', 'mergeGuest', 'getCart', 'update', 'remove', 'clear'] as $operationName) {
            self::assertArrayHasKey('store_code', $operations[$operationName]['params']);
            self::assertArrayHasKey('channel_code', $operations[$operationName]['params']);
            self::assertArrayHasKey('scope', $operations[$operationName]['params']);
            self::assertArrayHasKey(
                'cart_type',
                $operations[$operationName]['params'],
                $operationName . ' must accept cart_type for toc/tob cart routing',
            );
            self::assertArrayHasKey(
                'selling_mode',
                $operations[$operationName]['params'],
                $operationName . ' must accept selling_mode alias for toc/tob cart routing',
            );
        }
        self::assertSame('write', $operations['update']['mode']);
        self::assertSame('write', $operations['remove']['mode']);
        self::assertArrayHasKey('cart_type', $operations['previewDiscount']['params']);
        self::assertArrayHasKey('selling_mode', $operations['previewDiscount']['params']);
        foreach ($operations as $operationName => $operation) {
            self::assertTrue(
                ($operation['external'] ?? false) === true,
                $operationName . ' must set external=true for frontend worker exposure',
            );
        }
    }

    public function testMutationUsesTrustedGuestIdentityAndScope(): void
    {
        [$cart, $offer] = $this->service();
        $scope = $this->channelScope();
        $guestToken = $cart->issueGuestToken();
        $added = $cart->add($scope, $offer, [], 3, $guestToken);
        $itemId = (string)$added['items'][0]['item_id'];

        $query = new CartQueryProvider(
            $cart,
            new CartScopeResolver(),
            new CartCurrentCustomerResolver(static fn(): ?int => null),
        );
        $params = $this->flatScopeParams() + [
            'guest_token' => $guestToken,
            'item_id' => $itemId,
            'qty' => 1,
            'customer_id' => 999,
        ];

        $updated = $query->execute('update', $params);
        self::assertTrue($updated['success']);
        self::assertSame(1, $updated['item_count']);
        self::assertSame(CartService::OWNER_GUEST, $updated['owner_kind']);

        $removed = $query->execute('remove', $params);
        self::assertTrue($removed['success']);
        self::assertTrue($removed['is_empty']);
    }

    public function testAddReplacesBrowserCustomerIdWithAuthenticatedIdentity(): void
    {
        [$cart, $offer] = $this->service();
        $guestToken = $cart->issueGuestToken();
        $query = new CartQueryProvider(
            $cart,
            new CartScopeResolver(),
            new CartCurrentCustomerResolver(static fn(): ?int => 77),
        );
        $result = $query->execute('add', $this->flatScopeParams() + [
            'provider_code' => 'product',
            'global_offer_uuid' => $offer->globalOfferUuid,
            'legacy_product_id' => $offer->legacyProductId,
            'guest_token' => $guestToken,
            'qty' => 1,
            'customer_id' => 999,
        ]);
        self::assertTrue($result['success'], (string)($result['message'] ?? ''));
        self::assertSame(CartService::OWNER_CUSTOMER, $result['owner_kind']);
        self::assertSame('77', $result['owner_id']);
        self::assertSame(1, $result['item_count']);
    }

    public function testGuestCannotReadOrMergeCustomerCartBySupplyingCustomerId(): void
    {
        [$cart, $offer] = $this->service();
        $scope = $this->channelScope();
        $guestToken = $cart->issueGuestToken();
        $cart->add($scope, $offer, [], 2, $guestToken);
        $cart->add($scope, $offer, [], 4, customerId: 99);

        $query = new CartQueryProvider(
            $cart,
            new CartScopeResolver(),
            new CartCurrentCustomerResolver(static fn(): ?int => null),
        );
        $params = $this->flatScopeParams() + [
            'guest_token' => $guestToken,
            'customer_id' => 99,
        ];

        $read = $query->execute('getCart', $params);
        self::assertTrue($read['success']);
        self::assertSame(CartService::OWNER_GUEST, $read['owner_kind']);
        self::assertSame(2, $read['item_count']);
        self::assertSame($scope->canonicalKey(), $read['scope_key']);

        $merge = $query->execute('mergeGuest', $params);
        self::assertFalse($merge['success']);
        self::assertSame(CartCurrentCustomerResolver::ERROR_AUTH_REQUIRED, $merge['error_code']);
        self::assertSame(2, $cart->getCart($scope, $guestToken)['item_count']);
        self::assertSame(4, $cart->getCart($scope, customerId: 99)['item_count']);
    }

    public function testAuthenticatedMergeUsesCurrentCustomerAndFlatChannelScope(): void
    {
        [$cart, $offer] = $this->service();
        $scope = $this->channelScope();
        $guestToken = $cart->issueGuestToken();
        $cart->add($scope, $offer, ['size' => 'M'], 2, $guestToken);
        $cart->add($scope, $offer, ['size' => 'M'], 1, customerId: 77);

        $query = new CartQueryProvider(
            $cart,
            new CartScopeResolver(),
            new CartCurrentCustomerResolver(static fn(): ?int => 77),
        );
        $params = $this->flatScopeParams() + [
            'guest_token' => $guestToken,
            'customer_id' => 999,
        ];

        $merged = $query->execute('mergeGuest', $params);
        self::assertTrue($merged['success']);
        self::assertSame(CartService::OWNER_CUSTOMER, $merged['owner_kind']);
        self::assertSame('77', $merged['owner_id']);
        self::assertSame(3, $merged['item_count']);
        self::assertSame($scope->canonicalKey(), $merged['scope_key']);
        self::assertTrue($cart->getCart($scope, $guestToken)['is_empty']);
    }

    /**
     * @return array{CartService, OfferIdentity}
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
        $registry = CartItemSnapshotProviderRegistry::forTesting([$provider]);
        return [
            CartService::forTesting($registry),
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
}
