<?php

declare(strict_types=1);

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
    use Weline\Checkout\Service\CheckoutPageViewModel;

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
        }

        public function testCurrentCartForwardsGuestTokenToCartV2Boundary(): void
        {
            $cart = (new CheckoutPageViewModel())->currentCart('guest-token-123');

            self::assertFalse($cart['is_empty']);
            self::assertSame('Trusted guest cart item', $cart['items'][0]['name']);
            self::assertSame([[
                'provider' => 'cart',
                'operation' => 'getV2Cart',
                'params' => ['guest_token' => 'guest-token-123'],
            ]], CheckoutPageViewModelQuerySpy::$calls);
        }
    }
}
