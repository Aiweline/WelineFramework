<?php
declare(strict_types=1);

namespace WeShop\Compare\Extends\Module\Weline_Framework\Query;

use WeShop\Compare\Service\CompareService;
use WeShop\Customer\Api\CustomerContextInterface;
use Weline\Framework\Http\Url;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class CompareQueryProvider implements QueryProviderInterface
{
    private const LOGIN_ROUTE = 'customer/account/login';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly CompareService $compareService,
        private readonly Url $url
    ) {
    }

    public function getProviderName(): string
    {
        return 'compare';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'add' => $this->add($params),
            'remove' => $this->remove($params),
            'count' => $this->count(),
            default => throw new \InvalidArgumentException(
                (string)__('Unsupported compare provider operation: %{1}', $operation)
            ),
        };
    }

    private function add(array $params): array
    {
        $customerId = $this->getCustomerId();
        if ($customerId <= 0) {
            return $this->loginRequired();
        }

        $productId = (int)($params['product_id'] ?? 0);
        if ($productId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('Product ID is required.'),
            ];
        }

        $item = $this->compareService->addToCompare($customerId, $productId);

        return [
            'success' => true,
            'message' => (string)__('Added to compare.'),
            'data' => [
                'item_id' => (int)($item->getId() ?? 0),
                'product_id' => $productId,
                'compare_count' => $this->compareService->getCompareCount($customerId),
            ],
        ];
    }

    private function remove(array $params): array
    {
        $customerId = $this->getCustomerId();
        if ($customerId <= 0) {
            return $this->loginRequired();
        }

        $compareId = (int)($params['compare_id'] ?? $params['item_id'] ?? 0);
        if ($compareId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('Compare item ID is required.'),
            ];
        }

        $removed = $this->compareService->removeFromCompare($compareId, $customerId);
        if (!$removed) {
            return [
                'success' => false,
                'message' => (string)__('Compare item could not be removed.'),
            ];
        }

        return [
            'success' => true,
            'message' => (string)__('Removed from compare.'),
            'data' => [
                'compare_count' => $this->compareService->getCompareCount($customerId),
            ],
        ];
    }

    private function count(): array
    {
        $customerId = $this->getCustomerId();

        return [
            'success' => true,
            'message' => (string)__('Compare count loaded.'),
            'data' => [
                'compare_count' => $customerId > 0
                    ? $this->compareService->getCompareCount($customerId)
                    : 0,
            ],
        ];
    }

    private function getCustomerId(): int
    {
        return (int)($this->customerContext->getUserId() ?? 0);
    }

    private function loginRequired(): array
    {
        return [
            'success' => false,
            'message' => (string)__('Please log in to continue.'),
            'data' => [
                'redirect_url' => $this->url->getUrl(self::LOGIN_ROUTE),
            ],
        ];
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'compare',
            'name' => __('Compare Query'),
            'description' => __('Provides frontend compare-list operations through the worker API.'),
            'module' => 'WeShop_Compare',
            'operations' => [
                [
                    'name' => 'add',
                    'description' => __('Frontend compare add operation.'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 4,
                    'params' => [
                        'product_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Add product to compare list',
                ],
                [
                    'name' => 'remove',
                    'description' => __('Frontend compare remove operation.'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 4,
                    'params' => [
                        'compare_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Remove compare item',
                ],
                [
                    'name' => 'count',
                    'description' => __('Frontend compare count operation.'),
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'cache_ttl' => 5,
                    'params' => [],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Compare item count',
                ],
            ],
        ];
    }
}
