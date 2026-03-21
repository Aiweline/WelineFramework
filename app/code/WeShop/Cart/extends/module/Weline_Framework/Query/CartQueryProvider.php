<?php
declare(strict_types=1);

namespace WeShop\Cart\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use WeShop\Cart\Service\CartService;

class CartQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly CartService $cartService
    ) {
    }

    public function getProviderName(): string
    {
        return 'cart';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'getCartItems' => $this->getCartItems($params),
            'calculateTotals' => $this->calculateTotals($params),
            'clearCart' => $this->clearCart($params),
            default => throw new \InvalidArgumentException(
                (string) __('Cart 查询器不支持的操作：%{1}', $operation)
            ),
        };
    }

    private function getCartItems(array $params): array
    {
        $customerId = (int)($params['customer_id'] ?? 0);
        if ($customerId <= 0) {
            return [];
        }

        return $this->cartService->getCartItems($customerId);
    }

    private function calculateTotals(array $params): array
    {
        $customerId = (int)($params['customer_id'] ?? 0);
        if ($customerId <= 0) {
            return [
                'subtotal' => 0,
                'tax' => 0,
                'shipping' => 0,
                'discount' => 0,
                'total' => 0,
            ];
        }

        return $this->cartService->calculateTotals($customerId);
    }

    private function clearCart(array $params): bool
    {
        $customerId = (int)($params['customer_id'] ?? 0);
        if ($customerId <= 0) {
            return false;
        }

        return $this->cartService->clearCart($customerId);
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'cart',
            'name' => __('购物车查询'),
            'description' => __('提供购物车读取与总额计算能力'),
            'module' => 'WeShop_Cart',
            'operations' => [
                [
                    'name' => 'getCartItems',
                    'description' => __('获取客户购物车商品列表'),
                    'params' => [['name' => 'customer_id', 'type' => 'int', 'required' => true]],
                ],
                [
                    'name' => 'calculateTotals',
                    'description' => __('计算客户购物车总额'),
                    'params' => [['name' => 'customer_id', 'type' => 'int', 'required' => true]],
                ],
                [
                    'name' => 'clearCart',
                    'description' => __('清空客户购物车'),
                    'params' => [['name' => 'customer_id', 'type' => 'int', 'required' => true]],
                ],
            ],
        ];
    }
}
