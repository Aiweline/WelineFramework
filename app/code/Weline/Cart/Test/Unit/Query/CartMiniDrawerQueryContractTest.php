<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\Cart\Extends\Module\Weline_Framework\Query\CartQueryProvider;
use Weline\Cart\Service\CartCurrentCustomerResolver;
use Weline\Cart\Service\CartScopeResolver;
use Weline\Cart\Service\CartService;

final class CartMiniDrawerQueryContractTest extends TestCase
{
    public function testMiniItemsDeclaresHumanAttackRuleWithoutCache(): void
    {
        $provider = new CartQueryProvider(
            $this->createMock(CartService::class),
            new CartScopeResolver(),
            new CartCurrentCustomerResolver(static fn(): ?int => null),
        );

        $operations = [];
        foreach ($provider->getDescriptor()['operations'] as $operation) {
            $operations[(string)$operation['name']] = $operation;
        }

        self::assertArrayHasKey('miniItems', $operations);
        self::assertSame('read', $operations['miniItems']['mode']);
        self::assertArrayNotHasKey('cache', $operations['miniItems']);
        self::assertArrayHasKey('guest_token', $operations['miniItems']['params']);
        self::assertArrayHasKey('guest_token', $operations['count']['params']);
        self::assertSame(
            [
                'enabled' => true,
                'rate_limit' => '5/10s',
                'challenge' => 'human',
                'description' => '迷你购物车抽屉异步加载限流',
            ],
            $operations['miniItems']['attack'],
        );
    }
}
