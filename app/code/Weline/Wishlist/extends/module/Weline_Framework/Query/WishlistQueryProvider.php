<?php

declare(strict_types=1);

namespace Weline\Wishlist\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Wishlist\Service\WishlistService;

final class WishlistQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly WishlistService $wishlist,
    ) {
    }

    public function getProviderName(): string
    {
        return 'wishlist';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'list' => $this->wishlist->list(),
            'count' => $this->wishlist->count(),
            'add' => $this->wishlist->add($this->productId($params)),
            'remove' => $this->wishlist->remove($this->productId($params)),
            'toggle' => $this->wishlist->toggle($this->productId($params)),
            default => throw new \InvalidArgumentException((string)__('心愿单接口不支持操作：%{1}', [$operation])),
        };
    }

    public function getDescriptor(): array
    {
        $writeParams = [
            ['name' => 'product_id', 'type' => 'int', 'required' => true, 'min' => 1],
        ];

        return [
            'provider' => 'wishlist',
            'name' => (string)__('心愿单'),
            'description' => (string)__('产品卡片收藏按钮：添加、移除、列表与计数。'),
            'module' => 'Weline_Wishlist',
            'operations' => [
                ['name' => 'list', 'frontend' => true, 'mode' => 'read', 'params' => []],
                ['name' => 'count', 'frontend' => true, 'mode' => 'read', 'params' => []],
                ['name' => 'add', 'frontend' => true, 'mode' => 'write', 'params' => $writeParams],
                ['name' => 'remove', 'frontend' => true, 'mode' => 'write', 'params' => $writeParams],
                ['name' => 'toggle', 'frontend' => true, 'mode' => 'write', 'params' => $writeParams],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function productId(array $params): int
    {
        return max(0, (int)($params['product_id'] ?? 0));
    }
}
