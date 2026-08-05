<?php

declare(strict_types=1);

namespace Weline\Search\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Search\Service\SearchQueryException;
use Weline\Search\Service\SearchQueryService;

/**
 * Storefront Search facade（TASK-P3C-002）.
 *
 * Scope is server-owned RequestContext. Browser params cannot select another
 * Website/Store/Channel or localization context.
 */
final class SearchQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly SearchQueryService $search,
    ) {
    }

    public function getProviderName(): string
    {
        return 'search';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'search' => $this->search($params),
            default => throw new \InvalidArgumentException((string)__(
                '搜索接口不支持该操作：%{1}',
                [$operation],
            )),
        };
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function search(array $params): array
    {
        try {
            $unknown = \array_diff(\array_keys($params), ['q']);
            if ($unknown !== []) {
                throw new SearchQueryException(
                    SearchQueryException::ERROR_SCOPE,
                    (string)__('Search storefront 不接受客户端 Scope 参数'),
                    ['unknown_params' => \array_values($unknown)],
                );
            }
            $scope = RequestContext::scopeMetadata();
            if (!\is_array($scope)
                || ($scope['scope_kind'] ?? '') !== 'channel'
                || (int)($scope['store_id'] ?? 0) < 1
                || (int)($scope['channel_id'] ?? 0) < 1
                || \trim((string)($scope['locale'] ?? '')) === ''
                || \trim((string)($scope['currency'] ?? '')) === ''
            ) {
                throw new SearchQueryException(
                    SearchQueryException::ERROR_SCOPE,
                    (string)__('当前 storefront 请求没有冻结完整商城 Scope'),
                );
            }
            $result = $this->search->search([
                'website_id' => (int)$scope['website_id'],
                'store_id' => (int)$scope['store_id'],
                'channel_id' => (int)$scope['channel_id'],
                'locale' => (string)$scope['locale'],
                'currency' => (string)$scope['currency'],
                'q' => (string)($params['q'] ?? ''),
            ]);

            return ['success' => true]
                + $result
                + [
                    'degrade_active' => $this->search->degrade()->isActive(
                        (int)$result['website_id'],
                    ),
                ];
        } catch (SearchQueryException $exception) {
            return [
                'success' => false,
                'error_code' => $exception->errorCode,
                'message' => $exception->getMessage(),
                'context' => $exception->context,
            ];
        }
    }

    public function getDescriptor(): array
    {
        return [
            'name' => $this->getProviderName(),
            'module' => 'Weline_Search',
            'summary' => 'Trusted storefront Search with Product current degradation',
            'operations' => [
                [
                    'name' => 'search',
                    'frontend' => true,
                    'auth' => 'any',
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 2,
                    'params' => [
                        [
                            'name' => 'q',
                            'type' => 'string',
                            'required' => false,
                            'max_length' => 255,
                        ],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Search within the server-frozen storefront Scope',
                ],
            ],
        ];
    }
}
