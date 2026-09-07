<?php

declare(strict_types=1);

namespace {
    if (!function_exists('w_env_cookie')) {
        function w_env_cookie(?string $key = null, mixed $default = null): mixed
        {
            return \Weline\Framework\Env\WelineEnv::getCookie($key, $default);
        }
    }
}

namespace Weline\Checkout\Service {
    function w_query(string $provider, string $operation, array $params = []): mixed
    {
        return \Weline\Checkout\Test\Unit\Service\CheckoutPageViewModelQuerySpy::dispatch(
            $provider,
            $operation,
            $params,
        );
    }
}

namespace Weline\Checkout\Test\Unit\Service {
    use PHPUnit\Framework\TestCase;
    use Weline\Cart\Service\CartService;
    use Weline\Checkout\Service\CheckoutPageViewModel;
    use Weline\Framework\Context;
    use Weline\Framework\Http\CookieScope;

    final class CheckoutPageViewModelQuerySpy
    {
        /** @var list<array{provider:string,operation:string,params:array<string,mixed>}> */
        public static array $calls = [];

        public static function dispatch(string $provider, string $operation, array $params): array
        {
            self::$calls[] = compact('provider', 'operation', 'params');

            return [
                'success' => true,
                'data' => [
                    'currency' => 'USD',
                    'subtotal_minor' => 289500,
                    'grand_total_minor' => 289500,
                    'items' => [[
                        'name' => 'Trusted guest cart item',
                        'qty' => 1,
                        'unit_price_minor' => 289500,
                        'row_total_minor' => 289500,
                    ]],
                ],
            ];
        }
    }

    final class CheckoutPageViewModelGuestTokenTest extends TestCase
    {
        protected function setUp(): void
        {
            CheckoutPageViewModelQuerySpy::$calls = [];
            CookieScope::setPolicyResolverOverride(static fn(): array => [
                'active' => false,
                'name_suffix' => '',
                'name_suffix_pattern' => '',
                'mount_path' => '/',
                'expire_unscoped_aliases' => false,
                'revision' => 'checkout-guest-cart-test',
            ]);
            Context::current()->set('input.cookie', []);
        }

        protected function tearDown(): void
        {
            Context::current()->set('input.cookie', []);
            CookieScope::setPolicyResolverOverride(null);
        }

        public function testCurrentCartForwardsGuestTokenToCartBoundary(): void
        {
            $cart = (new CheckoutPageViewModel())->currentCart('guest-token-123');

            self::assertFalse($cart['is_empty']);
            self::assertSame('Trusted guest cart item', $cart['items'][0]['name']);
            self::assertSame([[
                'provider' => 'cart',
                'operation' => 'getCart',
                'params' => ['guest_token' => 'guest-token-123'],
            ]], CheckoutPageViewModelQuerySpy::$calls);
        }

        public function testCurrentCartRecoversGuestTokenFromTrustedRequestCookie(): void
        {
            Context::current()->set('input.cookie', [
                CartService::GUEST_TOKEN_COOKIE => 'guest-cookie-token-456',
            ]);

            $cart = (new CheckoutPageViewModel())->currentCart();

            self::assertFalse($cart['is_empty']);
            self::assertSame([[
                'provider' => 'cart',
                'operation' => 'getCart',
                'params' => ['guest_token' => 'guest-cookie-token-456'],
            ]], CheckoutPageViewModelQuerySpy::$calls);
        }
    }
}
