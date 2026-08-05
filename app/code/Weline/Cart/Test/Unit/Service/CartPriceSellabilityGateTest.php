<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cart\Api\CartPriceSellabilityGate as PublicGate;
use Weline\Cart\Api\CartPriceSellabilityProviderInterface;
use Weline\Cart\Service\CartPriceSellabilityGate;

final class CartPriceSellabilityGateTest extends TestCase
{
    public function testPublicGateDelegatesProviderResultWithoutChangingContract(): void
    {
        $provider = new class implements CartPriceSellabilityProviderInterface {
            public function assertOrAllow(array $params): array
            {
                return [
                    'ok' => false,
                    'error_code' => 'price_cleared',
                    'message' => 'cleared',
                    'detail' => ['offer_id' => (int)($params['offer_id'] ?? 0)],
                ];
            }
        };

        $result = (new PublicGate(new CartPriceSellabilityGate($provider)))
            ->assertOrAllow(['offer_id' => 73]);

        self::assertSame([
            'ok' => false,
            'error_code' => 'price_cleared',
            'message' => 'cleared',
            'detail' => ['offer_id' => 73],
        ], $result);
    }

    public function testProviderFailureIsClosedWithStableErrorCode(): void
    {
        $provider = new class implements CartPriceSellabilityProviderInterface {
            public function assertOrAllow(array $params): array
            {
                throw new \RuntimeException('provider exploded');
            }
        };

        $result = (new CartPriceSellabilityGate($provider))->assertOrAllow([]);

        self::assertFalse($result['ok']);
        self::assertSame('cart_sellability_provider_failed', $result['error_code']);
        self::assertStringContainsString('provider exploded', $result['message']);
    }
}
