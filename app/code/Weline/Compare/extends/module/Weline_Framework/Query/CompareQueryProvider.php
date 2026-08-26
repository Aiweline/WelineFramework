<?php

declare(strict_types=1);

namespace Weline\Compare\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Compare\Service\CompareService;

final class CompareQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly CompareService $compare,
    ) {
    }

    public function getProviderName(): string
    {
        return 'compare';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'list' => $this->compare->list(),
            'add' => $this->compare->add($this->productId($params)),
            'remove' => $this->compare->remove($this->productId($params)),
            'clear' => $this->compare->clear(),
            'quickView' => $this->compare->quickView(
                $this->productId($params),
                is_array($params['fallback'] ?? null) ? $params['fallback'] : null,
            ),
            default => throw new \InvalidArgumentException((string)__('对比接口不支持操作：%{1}', [$operation])),
        };
    }

    public function getDescriptor(): array
    {
        $writeParams = [
            ['name' => 'product_id', 'type' => 'int', 'required' => true, 'min' => 1],
        ];

        return [
            'provider' => 'compare',
            'name' => (string)__('商品对比'),
            'description' => (string)__('对比栏、对比页与快速查看弹窗数据接口。'),
            'module' => 'Weline_Compare',
            'operations' => [
                ['name' => 'list', 'frontend' => true, 'mode' => 'read', 'params' => []],
                ['name' => 'add', 'frontend' => true, 'mode' => 'write', 'params' => $writeParams],
                ['name' => 'remove', 'frontend' => true, 'mode' => 'write', 'params' => $writeParams],
                ['name' => 'clear', 'frontend' => true, 'mode' => 'write', 'params' => []],
                [
                    'name' => 'quickView',
                    'frontend' => true,
                    'mode' => 'read',
                    'params' => array_merge($writeParams, [
                        ['name' => 'fallback', 'type' => 'array', 'required' => false],
                    ]),
                ],
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
