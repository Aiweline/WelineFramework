<?php
declare(strict_types=1);

namespace Weline\Cart\Extends\Module\Weline_Framework\Query;

use Weline\Cart\Service\CartService;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

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
            'summary' => $this->success('Cart summary loaded.', $this->cartService->summary()),
            'count' => $this->success('Cart count loaded.', $this->cartCountPayload()),
            'items', 'miniItems' => $this->success('Cart items loaded.', $this->cartItemsPayload($params)),
            'add' => $this->successFromSummary($this->cartService->add($params)),
            'update' => $this->successFromSummary($this->cartService->update($params)),
            'remove' => $this->successFromSummary($this->cartService->remove($params)),
            'clear' => $this->successFromSummary($this->cartService->clear()),
            'options' => $this->success('Cart options loaded.', ['options' => []]),
            default => throw new \InvalidArgumentException((string)__('Cart 查询器不支持的 operation：%{1}', $operation)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function cartCountPayload(): array
    {
        $summary = $this->cartService->summary();

        return [
            'success' => true,
            'cart_count' => (int)($summary['cart_count'] ?? 0),
            'item_count' => (int)($summary['item_count'] ?? 0),
            'distinct_count' => (int)($summary['distinct_count'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function cartItemsPayload(array $params): array
    {
        $summary = $this->cartService->summary();
        $items = \is_array($summary['items'] ?? null) ? $summary['items'] : [];
        $limit = \max(1, \min(50, (int)($params['limit'] ?? $params['max_items'] ?? 5)));

        return [
            'success' => true,
            'items' => \array_slice($items, 0, $limit),
        ] + $summary;
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function successFromSummary(array $summary): array
    {
        return [
            'success' => (bool)($summary['success'] ?? false),
            'message' => (string)($summary['message'] ?? ''),
            'data' => $summary,
        ] + $summary;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function success(string $message, array $data): array
    {
        $data['success'] = $data['success'] ?? true;

        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ] + $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDescriptor(): array
    {
        $commonReturns = ['type' => 'array'];

        return [
            'provider' => 'cart',
            'name' => __('Frontend cart API'),
            'description' => __('Storefront cart session operations exposed through Weline.Api.'),
            'module' => 'Weline_Cart',
            'operations' => [
                [
                    'name' => 'summary',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'params' => [],
                    'returns' => $commonReturns,
                    'summary' => 'Read cart summary',
                ],
                [
                    'name' => 'count',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'params' => [],
                    'returns' => $commonReturns,
                    'summary' => 'Read cart item count',
                ],
                [
                    'name' => 'items',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'params' => [
                        'limit' => ['type' => 'int', 'min' => 1, 'max' => 50],
                        'max_items' => ['type' => 'int', 'min' => 1, 'max' => 50],
                    ],
                    'returns' => $commonReturns,
                    'summary' => 'Read cart items',
                ],
                [
                    'name' => 'miniItems',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'params' => [
                        'limit' => ['type' => 'int', 'min' => 1, 'max' => 50],
                        'max_items' => ['type' => 'int', 'min' => 1, 'max' => 50],
                    ],
                    'returns' => $commonReturns,
                    'summary' => 'Read mini cart items',
                ],
                [
                    'name' => 'add',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 4,
                    'params' => [
                        'product_id' => ['type' => 'int', 'min' => 1],
                        'id' => ['type' => 'int', 'min' => 1],
                        'qty' => ['type' => 'int', 'min' => 1, 'max' => 999],
                        'selected_options' => ['type' => 'array', 'max_items' => 50],
                        'options' => ['type' => 'array', 'max_items' => 50],
                        'name' => ['type' => 'string', 'max_length' => 160],
                        'sku' => ['type' => 'string', 'max_length' => 80],
                        'image' => ['type' => 'string', 'max_length' => 512],
                        'price' => ['type' => 'number', 'min' => 0],
                    ],
                    'returns' => $commonReturns,
                    'summary' => 'Add item to cart session',
                ],
                [
                    'name' => 'update',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'params' => [
                        'item_id' => ['type' => 'string', 'max_length' => 64],
                        'cart_item_id' => ['type' => 'string', 'max_length' => 64],
                        'product_id' => ['type' => 'int', 'min' => 1],
                        'id' => ['type' => 'int', 'min' => 1],
                        'qty' => ['type' => 'int', 'min' => 0, 'max' => 999],
                    ],
                    'returns' => $commonReturns,
                    'summary' => 'Update cart item quantity',
                ],
                [
                    'name' => 'remove',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'params' => [
                        'item_id' => ['type' => 'string', 'max_length' => 64],
                        'cart_item_id' => ['type' => 'string', 'max_length' => 64],
                        'product_id' => ['type' => 'int', 'min' => 1],
                        'id' => ['type' => 'int', 'min' => 1],
                    ],
                    'returns' => $commonReturns,
                    'summary' => 'Remove cart item',
                ],
                [
                    'name' => 'clear',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'params' => [],
                    'returns' => $commonReturns,
                    'summary' => 'Clear cart session',
                ],
                [
                    'name' => 'options',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 1,
                    'params' => [
                        'product_id' => ['type' => 'int', 'min' => 1],
                    ],
                    'returns' => $commonReturns,
                    'summary' => 'Read cart option metadata',
                ],
            ],
        ];
    }
}
