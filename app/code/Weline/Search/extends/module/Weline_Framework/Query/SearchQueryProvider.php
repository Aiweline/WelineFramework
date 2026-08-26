<?php

declare(strict_types=1);

namespace Weline\Search\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Service\Query\Attribute\BinQueryCache;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Search\Service\HotWordsService;
use Weline\Search\Service\SearchHubService;
use Weline\Search\Service\SearchParamException;
use Weline\Search\Service\SearchParamGuard;

/**
 * Storefront Search facade — shared allowlist for GET /search and QueryBin POST.
 */
final class SearchQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly SearchHubService $hub,
        private readonly HotWordsService $hotWords,
        private readonly SearchParamGuard $paramGuard,
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
            'hotWords' => $this->hotWords($params),
            'types' => $this->types($params),
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
    #[BinQueryCache(ttl: '0s', description: 'Search results are never CDN cached', cdn: false)]
    private function search(array $params): array
    {
        try {
            $result = $this->hub->search($params, autocomplete: true);
            $payload = $result->toArray() + [
                'website_id' => (int)(RequestContext::scopeMetadata()['website_id'] ?? 0),
                'store_id' => (int)(RequestContext::scopeMetadata()['store_id'] ?? 0),
                'channel_id' => (int)(RequestContext::scopeMetadata()['channel_id'] ?? 0),
                'locale' => (string)(RequestContext::scopeMetadata()['locale'] ?? ''),
                'currency' => (string)(RequestContext::scopeMetadata()['currency'] ?? ''),
            ];

            return ['success' => $result->ok] + $payload;
        } catch (SearchParamException $exception) {
            return [
                'success' => false,
                'error_code' => $exception->errorCode,
                'message' => $exception->getMessage(),
                'context' => $exception->context,
            ];
        }
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    #[BinQueryCache(ttl: '5m', description: 'Storefront hot words', keyParams: ['limit'])]
    private function hotWords(array $params): array
    {
        try {
            $limit = $this->paramGuard->guardHotWords($params);
            $payload = $this->hotWords->resolve($limit);

            return [
                'success' => true,
                'message' => (string)__('热搜词已加载'),
                'data' => $payload,
            ] + $payload;
        } catch (SearchParamException $exception) {
            return [
                'success' => false,
                'error_code' => $exception->errorCode,
                'message' => $exception->getMessage(),
                'context' => $exception->context,
            ];
        }
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    #[BinQueryCache(ttl: '10m', description: 'Registered search types')]
    private function types(array $params): array
    {
        try {
            $this->paramGuard->guardTypes($params);

            return [
                'success' => true,
                'types' => $this->hub->listTypes(),
            ];
        } catch (SearchParamException $exception) {
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
            'summary' => 'Universal storefront Search hub with shared param guard',
            'operations' => [
                [
                    'name' => 'search',
                    'frontend' => true,
                    'auth' => 'any',
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 2,
                    'params' => [
                        ['name' => 'q', 'type' => 'string', 'required' => false, 'max_length' => 255],
                        ['name' => 'type', 'type' => 'string', 'required' => false],
                        ['name' => 'page', 'type' => 'int', 'required' => false, 'min' => 1, 'max' => 100],
                        ['name' => 'page_size', 'type' => 'int', 'required' => false, 'min' => 1, 'max' => 48],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Autocomplete/search within server-frozen Scope',
                ],
                [
                    'name' => 'hotWords',
                    'frontend' => true,
                    'auth' => 'any',
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 1,
                    'params' => [
                        ['name' => 'limit', 'type' => 'int', 'required' => false, 'min' => 1, 'max' => 20],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Channel-scoped hot words',
                ],
                [
                    'name' => 'types',
                    'frontend' => true,
                    'auth' => 'any',
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 1,
                    'params' => [],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Registered search provider types',
                ],
            ],
        ];
    }
}
