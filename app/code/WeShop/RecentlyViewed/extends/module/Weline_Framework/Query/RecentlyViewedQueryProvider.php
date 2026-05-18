<?php
declare(strict_types=1);

namespace WeShop\RecentlyViewed\Extends\Module\Weline_Framework\Query;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\RecentlyViewed\Service\RecentlyViewedService;
use Weline\Framework\Http\Url;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class RecentlyViewedQueryProvider implements QueryProviderInterface
{
    private const LOGIN_ROUTE = 'customer/account/login';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly RecentlyViewedService $recentlyViewedService,
        private readonly Url $url
    ) {
    }

    public function getProviderName(): string
    {
        return 'recentlyViewed';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'remove' => $this->remove($params),
            default => throw new \InvalidArgumentException(
                (string)__('Unsupported recently viewed provider operation: %{1}', $operation)
            ),
        };
    }

    private function remove(array $params): array
    {
        $customerId = (int)($this->customerContext->getUserId() ?? 0);
        if ($customerId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('Please log in to continue.'),
                'data' => ['redirect_url' => $this->url->getUrl(self::LOGIN_ROUTE)],
            ];
        }

        $viewId = (int)($params['view_id'] ?? $params['item_id'] ?? 0);
        if ($viewId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('Recently viewed item ID is required.'),
            ];
        }

        $this->recentlyViewedService->removeView($viewId, $customerId);

        return [
            'success' => true,
            'message' => (string)__('Removed from recently viewed.'),
            'data' => [
                'view_id' => $viewId,
                'recently_viewed_count' => $this->recentlyViewedService->getRecentlyViewedCount($customerId),
            ],
        ];
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'recentlyViewed',
            'name' => __('Recently Viewed Query'),
            'description' => __('Provides frontend recently viewed operations through the worker API.'),
            'module' => 'WeShop_RecentlyViewed',
            'operations' => [
                [
                    'name' => 'remove',
                    'description' => __('Remove a recently viewed item owned by the current customer.'),
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'params' => [
                        'view_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Remove recently viewed item',
                ],
            ],
        ];
    }
}
