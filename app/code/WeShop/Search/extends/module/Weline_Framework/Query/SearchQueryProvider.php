<?php

declare(strict_types=1);

namespace WeShop\Search\Extends\Module\Weline_Framework\Query;

use WeShop\Search\Service\SearchIndexer;
use WeShop\Search\Service\SearchService;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class SearchQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly SearchService $searchService,
        private readonly SearchIndexer $searchIndexer
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
            'browseProducts' => $this->browseProducts($params),
            'suggest' => $this->suggest($params),
            'rebuildIndex' => $this->rebuildIndex($params),
            'indexEntity' => $this->indexEntity($params),
            'deleteEntity' => $this->deleteEntity($params),
            'providers' => $this->searchIndexer->getProviderDescriptors(),
            default => throw new \InvalidArgumentException(
                (string) __('Unsupported search query operation: %{1}', $operation)
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'search',
            'name' => __('Search Query'),
            'description' => __('Provides unified search, suggestions, index rebuild, and entity indexing operations.'),
            'module' => 'WeShop_Search',
            'operations' => [
                [
                    'name' => 'search',
                    'description' => __('Run the unified product search.'),
                    'params' => [
                        ['name' => 'keyword', 'type' => 'string', 'required' => true],
                        ['name' => 'filters', 'type' => 'array', 'required' => false],
                        ['name' => 'page', 'type' => 'int', 'required' => false],
                        ['name' => 'page_size', 'type' => 'int', 'required' => false],
                        ['name' => 'scope', 'type' => 'string', 'required' => false],
                    ],
                ],
                [
                    'name' => 'browseProducts',
                    'description' => __('Browse products with keyword, category, and dynamic facet filters.'),
                    'params' => [
                        ['name' => 'keyword', 'type' => 'string', 'required' => false],
                        ['name' => 'filters', 'type' => 'array', 'required' => false],
                        ['name' => 'page', 'type' => 'int', 'required' => false],
                        ['name' => 'page_size', 'type' => 'int', 'required' => false],
                        ['name' => 'scope', 'type' => 'string', 'required' => false],
                        ['name' => 'category_ids', 'type' => 'array', 'required' => false],
                        ['name' => 'include_facets', 'type' => 'bool', 'required' => false],
                    ],
                ],
                [
                    'name' => 'suggest',
                    'description' => __('Get search suggestions.'),
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 2,
                    'cache_ttl' => 10,
                    'params' => [
                        ['name' => 'keyword', 'type' => 'string', 'required' => true, 'max_length' => 120],
                        ['name' => 'limit', 'type' => 'int', 'required' => false, 'min' => 1, 'max' => 20],
                        ['name' => 'scope', 'type' => 'string', 'required' => false, 'max_length' => 64],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Search suggestions',
                ],
                [
                    'name' => 'rebuildIndex',
                    'description' => __('Rebuild all search indexes or a specific provider index.'),
                    'params' => [
                        ['name' => 'provider', 'type' => 'string', 'required' => false],
                        ['name' => 'force', 'type' => 'bool', 'required' => false],
                        ['name' => 'scope', 'type' => 'string', 'required' => false],
                    ],
                ],
                [
                    'name' => 'indexEntity',
                    'description' => __('Index a single entity into the search engine.'),
                    'params' => [
                        ['name' => 'provider', 'type' => 'string', 'required' => true],
                        ['name' => 'entity_id', 'type' => 'int|string', 'required' => true],
                        ['name' => 'scope', 'type' => 'string', 'required' => false],
                    ],
                ],
                [
                    'name' => 'deleteEntity',
                    'description' => __('Delete a single entity from the search index.'),
                    'params' => [
                        ['name' => 'provider', 'type' => 'string', 'required' => true],
                        ['name' => 'entity_id', 'type' => 'int|string', 'required' => true],
                        ['name' => 'scope', 'type' => 'string', 'required' => false],
                    ],
                ],
                [
                    'name' => 'providers',
                    'description' => __('List the registered search document providers.'),
                    'params' => [],
                ],
            ],
        ];
    }

    private function search(array $params): array
    {
        return $this->searchService->searchProducts(
            trim((string) ($params['keyword'] ?? '')),
            is_array($params['filters'] ?? null) ? $params['filters'] : [],
            max(1, (int) ($params['page'] ?? 1)),
            max(1, (int) ($params['page_size'] ?? 20)),
            trim((string) ($params['scope'] ?? 'default')) ?: 'default'
        );
    }

    private function browseProducts(array $params): array
    {
        return $this->searchService->browseProducts(
            trim((string) ($params['keyword'] ?? '')),
            is_array($params['filters'] ?? null) ? $params['filters'] : [],
            max(1, (int) ($params['page'] ?? 1)),
            max(1, (int) ($params['page_size'] ?? 20)),
            trim((string) ($params['scope'] ?? 'default')) ?: 'default',
            is_array($params['category_ids'] ?? null) ? $params['category_ids'] : [],
            (bool) ($params['include_facets'] ?? true)
        );
    }

    private function suggest(array $params): array
    {
        return $this->searchService->getSearchSuggestions(
            trim((string) ($params['keyword'] ?? '')),
            max(1, (int) ($params['limit'] ?? 10)),
            trim((string) ($params['scope'] ?? 'default')) ?: 'default'
        );
    }

    private function rebuildIndex(array $params): bool
    {
        $provider = trim((string) ($params['provider'] ?? ''));
        $provider = $provider !== '' ? $provider : null;

        return $this->searchIndexer->rebuild(
            $provider,
            (bool) ($params['force'] ?? false),
            100,
            trim((string) ($params['scope'] ?? 'default')) ?: 'default'
        );
    }

    private function indexEntity(array $params): bool
    {
        return $this->searchIndexer->indexEntity(
            trim((string) ($params['provider'] ?? '')),
            (string) ($params['entity_id'] ?? ''),
            trim((string) ($params['scope'] ?? 'default')) ?: 'default'
        );
    }

    private function deleteEntity(array $params): bool
    {
        return $this->searchIndexer->deleteEntity(
            trim((string) ($params['provider'] ?? '')),
            (string) ($params['entity_id'] ?? ''),
            trim((string) ($params['scope'] ?? 'default')) ?: 'default'
        );
    }
}
