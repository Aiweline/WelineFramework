<?php

declare(strict_types=1);

namespace WeShop\Search\Engine;

use WeShop\Search\Api\SearchBrowseEngineInterface;
use WeShop\Search\Api\SearchEngineInterface;
use WeShop\Search\Api\SearchWritableEngineInterface;

class ElasticsearchEngine implements SearchEngineInterface, SearchWritableEngineInterface, SearchBrowseEngineInterface
{
    /**
     * @var array<string, mixed>
     */
    private array $config = [];

    public function search(string $keyword, array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        $result = $this->browseProducts([
            'keyword' => trim($keyword),
            'filters' => $filters,
            'page' => $page,
            'page_size' => $pageSize,
            'include_facets' => false,
        ]);

        return [
            'items' => is_array($result['items'] ?? null) ? $result['items'] : [],
            'total' => (int) ($result['total'] ?? 0),
            'pagination' => $result['pagination'] ?? [],
            'engine' => (string) ($result['engine'] ?? $this->getEngineType()),
            'fallback' => !empty($result['fallback']),
        ];
    }

    public function browseProducts(array $request): array
    {
        $normalizedRequest = $this->normalizeBrowseRequest($request);

        try {
            $response = $this->requestJson(
                'POST',
                $this->buildIndexUrl('/_search'),
                $this->buildBrowsePayload($normalizedRequest),
                $this->buildHeaders()
            );

            return $this->normalizeBrowseResult($response['body'] ?? [], $normalizedRequest);
        } catch (\Throwable $throwable) {
            w_log_error('Elasticsearch browse failed: ' . $throwable->getMessage());

            return $this->fallbackBrowse($normalizedRequest);
        }
    }

    public function getSuggestions(string $keyword, int $limit = 10): array
    {
        $keyword = trim($keyword);
        $limit = max(1, $limit);

        if ($keyword === '') {
            return [];
        }

        $payload = [
            'size' => $limit,
            '_source' => ['name', 'sku', 'document_type', 'entity_id', 'url'],
            'query' => [
                'bool' => [
                    'must' => [$this->buildKeywordQuery($keyword)],
                    'filter' => [['term' => ['status' => 1]]],
                ],
            ],
        ];

        try {
            $response = $this->requestJson(
                'POST',
                $this->buildIndexUrl('/_search'),
                $payload,
                $this->buildHeaders()
            );

            return $this->extractSuggestionItems($response['body']['hits']['hits'] ?? [], $limit, '_source');
        } catch (\Throwable $throwable) {
            w_log_error('Elasticsearch suggestions failed: ' . $throwable->getMessage());

            return $this->fallbackSuggestions($keyword, $limit);
        }
    }

    public function initConfig(array $config): bool
    {
        $host = trim((string) ($config['host'] ?? 'http://127.0.0.1'));
        if ($host === '') {
            $host = 'http://127.0.0.1';
        }

        $this->config = [
            'host' => rtrim($host, '/'),
            'port' => (int) ($config['port'] ?? 9200),
            'index' => trim((string) ($config['index'] ?? 'products')) ?: 'products',
            'username' => trim((string) ($config['username'] ?? '')),
            'password' => (string) ($config['password'] ?? ''),
            'timeout' => max(1, (int) ($config['timeout'] ?? 5)),
        ];

        return true;
    }

    public function testConnection(): bool
    {
        try {
            $response = $this->requestJson('GET', $this->buildBaseUrl(), [], $this->buildHeaders());

            return ($response['status'] ?? 0) >= 200 && ($response['status'] ?? 0) < 300;
        } catch (\Throwable $throwable) {
            w_log_error('Elasticsearch connection test failed: ' . $throwable->getMessage());

            return false;
        }
    }

    public function getEngineType(): string
    {
        return 'elasticsearch';
    }

    public function getEngineName(): string
    {
        return 'Elasticsearch';
    }

    public function upsertDocuments(array $documents, string $primaryKey = 'document_id'): bool
    {
        if ($documents === []) {
            return true;
        }

        if (!$this->configureIndex()) {
            return false;
        }

        $lines = [];
        foreach ($documents as $document) {
            if (!is_array($document)) {
                continue;
            }

            $documentId = trim((string) ($document[$primaryKey] ?? ''));
            if ($documentId === '') {
                continue;
            }

            $lines[] = json_encode([
                'index' => [
                    '_index' => trim((string) ($this->config['index'] ?? 'products')),
                    '_id' => $documentId,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $lines[] = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($lines === []) {
            return true;
        }

        $response = $this->requestRaw(
            'POST',
            $this->buildIndexUrl('/_bulk?refresh=true'),
            implode("\n", $lines) . "\n",
            $this->buildHeaders('application/x-ndjson')
        );

        if (($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300) {
            return false;
        }

        $decoded = json_decode((string) ($response['body'] ?? ''), true);

        return !is_array($decoded) || empty($decoded['errors']);
    }

    public function deleteDocuments(array $documentIds): bool
    {
        if ($documentIds === []) {
            return true;
        }

        $lines = [];
        foreach ($documentIds as $documentId) {
            $documentId = trim((string) $documentId);
            if ($documentId === '') {
                continue;
            }

            $lines[] = json_encode([
                'delete' => [
                    '_index' => trim((string) ($this->config['index'] ?? 'products')),
                    '_id' => $documentId,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($lines === []) {
            return true;
        }

        $response = $this->requestRaw(
            'POST',
            $this->buildIndexUrl('/_bulk?refresh=true'),
            implode("\n", $lines) . "\n",
            $this->buildHeaders('application/x-ndjson')
        );

        return ($response['status'] ?? 0) >= 200 && ($response['status'] ?? 0) < 300;
    }

    public function deleteByDocumentType(string $documentType): bool
    {
        $documentType = trim($documentType);
        if ($documentType === '') {
            return true;
        }

        try {
            $response = $this->requestJson(
                'POST',
                $this->buildIndexUrl('/_delete_by_query?refresh=true&conflicts=proceed'),
                ['query' => ['term' => ['document_type' => $documentType]]],
                $this->buildHeaders()
            );

            return ($response['status'] ?? 0) >= 200 && ($response['status'] ?? 0) < 300;
        } catch (\Throwable $throwable) {
            w_log_error('Elasticsearch delete_by_query failed: ' . $throwable->getMessage());

            return false;
        }
    }

    public function configureIndex(array $definition = []): bool
    {
        $payload = $this->buildIndexDefinition($definition);

        $response = $this->requestRaw(
            'PUT',
            $this->buildIndexUrl(),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $this->buildHeaders()
        );

        if (($response['status'] ?? 0) >= 200 && ($response['status'] ?? 0) < 300) {
            return true;
        }

        if (($response['status'] ?? 0) !== 400 || !str_contains((string) ($response['body'] ?? ''), 'resource_already_exists_exception')) {
            return false;
        }

        $mappingResponse = $this->requestRaw(
            'PUT',
            $this->buildIndexUrl('/_mapping'),
            json_encode(['properties' => $payload['mappings']['properties']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $this->buildHeaders()
        );

        return ($mappingResponse['status'] ?? 0) >= 200 && ($mappingResponse['status'] ?? 0) < 300;
    }

    protected function requestJson(string $method, string $url, array $payload = [], array $headers = []): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new \RuntimeException('Unable to initialize curl.');
        }

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_TIMEOUT, (int) ($this->config['timeout'] ?? 5));
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, min((int) ($this->config['timeout'] ?? 5), 5));

        if ($payload !== [] && strtoupper($method) !== 'GET') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $body = curl_exec($curl);
        if ($body === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new \RuntimeException($error !== '' ? $error : 'Request failed.');
        }

        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('HTTP status ' . $status);
        }

        if ($body === '' || $body === null) {
            return ['status' => $status, 'body' => []];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON response.');
        }

        return ['status' => $status, 'body' => $decoded];
    }

    protected function requestRaw(string $method, string $url, string $payload = '', array $headers = []): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new \RuntimeException('Unable to initialize curl.');
        }

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_TIMEOUT, (int) ($this->config['timeout'] ?? 5));
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, min((int) ($this->config['timeout'] ?? 5), 5));

        if ($payload !== '' && strtoupper($method) !== 'GET') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
        }

        $body = curl_exec($curl);
        if ($body === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new \RuntimeException($error !== '' ? $error : 'Request failed.');
        }

        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return ['status' => $status, 'body' => (string) $body];
    }

    protected function buildBrowsePayload(array $request): array
    {
        $page = (int) ($request['page'] ?? 1);
        $pageSize = (int) ($request['page_size'] ?? 20);
        $keyword = trim((string) ($request['keyword'] ?? ''));
        $facetDefinitions = is_array($request['facet_definitions'] ?? null) ? $request['facet_definitions'] : [];

        $payload = [
            'from' => ($page - 1) * $pageSize,
            'size' => $pageSize,
            'query' => [
                'bool' => [
                    'must' => [$this->buildKeywordQuery($keyword)],
                    'filter' => $this->buildBrowseRootFilters($request),
                ],
            ],
        ];

        $sort = $this->buildSort($request);
        if ($sort !== []) {
            $payload['sort'] = $sort;
        }

        if (!empty($request['include_facets']) && $facetDefinitions !== []) {
            $payload['aggs'] = $this->buildFacetAggregations($request, $facetDefinitions, $keyword);
        }

        return $payload;
    }

    protected function normalizeBrowseResult(array $body, array $request): array
    {
        $items = [];
        foreach (($body['hits']['hits'] ?? []) as $hit) {
            if (!is_array($hit)) {
                continue;
            }

            $source = is_array($hit['_source'] ?? null) ? $hit['_source'] : [];
            $items[] = array_merge($source, [
                '_id' => $hit['_id'] ?? null,
                '_score' => $hit['_score'] ?? null,
            ]);
        }

        $total = (int) ($body['hits']['total']['value'] ?? $body['hits']['total'] ?? 0);
        $page = (int) ($request['page'] ?? 1);
        $pageSize = (int) ($request['page_size'] ?? 20);

        return [
            'items' => $items,
            'total' => $total,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'pages' => $pageSize > 0 ? (int) ceil($total / $pageSize) : 0,
                'total' => $total,
                'from' => $total > 0 ? (($page - 1) * $pageSize) + 1 : 0,
                'to' => $total > 0 ? min($page * $pageSize, $total) : 0,
            ],
            'facets' => $this->normalizeFacetBuckets($body['aggregations'] ?? [], $request),
            'engine' => $this->getEngineType(),
            'fallback' => false,
        ];
    }

    private function normalizeBrowseRequest(array $request): array
    {
        $filters = is_array($request['filters'] ?? null) ? $request['filters'] : [];
        $categoryIds = $request['category_ids'] ?? [];
        if (!is_array($categoryIds)) {
            $categoryIds = [$categoryIds];
        }
        if ($categoryIds === [] && array_key_exists('category_id', $filters)) {
            $categoryIds = $this->normalizeSelectedValues($filters['category_id']);
        }

        $normalizedDefinitions = [];
        foreach ((array) ($request['facet_definitions'] ?? []) as $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $code = trim((string) ($definition['code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $normalizedDefinitions[$code] = $definition;
        }

        return [
            'keyword' => trim((string) ($request['keyword'] ?? '')),
            'filters' => $filters,
            'page' => max(1, (int) ($request['page'] ?? 1)),
            'page_size' => max(1, (int) ($request['page_size'] ?? 20)),
            'scope' => trim((string) ($request['scope'] ?? 'default')) ?: 'default',
            'category_ids' => array_values(array_unique(array_filter(array_map('intval', $categoryIds)))),
            'include_facets' => (bool) ($request['include_facets'] ?? false),
            'facet_definitions' => $normalizedDefinitions,
        ];
    }

    private function buildBrowseRootFilters(array $request): array
    {
        $filters = [
            ['term' => ['document_type' => 'product']],
            ['term' => ['status' => 1]],
        ];

        $categoryIds = $request['category_ids'] ?? [];
        if (is_array($categoryIds) && $categoryIds !== []) {
            $filters[] = ['terms' => ['category_ids' => array_values(array_map('intval', $categoryIds))]];
        }

        return array_merge(
            $filters,
            $this->compileSelectedFilterClauses(
                is_array($request['filters'] ?? null) ? $request['filters'] : [],
                is_array($request['facet_definitions'] ?? null) ? $request['facet_definitions'] : []
            )
        );
    }

    private function buildSort(array $request): array
    {
        $filters = is_array($request['filters'] ?? null) ? $request['filters'] : [];
        $sortField = trim((string) ($filters['order_by'] ?? ''));
        if ($sortField === '') {
            return [
                ['_score' => ['order' => 'desc']],
                ['entity_id' => ['order' => 'desc']],
            ];
        }

        if ($sortField === 'name') {
            $sortField = 'name.keyword';
        }

        return [[
            $sortField => [
                'order' => strtolower((string) ($filters['order_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc',
            ],
        ]];
    }

    private function buildFacetAggregations(array $request, array $facetDefinitions, string $keyword): array
    {
        $aggs = [];
        $filters = is_array($request['filters'] ?? null) ? $request['filters'] : [];
        $categoryIds = is_array($request['category_ids'] ?? null) ? $request['category_ids'] : [];

        foreach ($facetDefinitions as $code => $definition) {
            $rootFilters = [
                ['term' => ['document_type' => 'product']],
                ['term' => ['status' => 1]],
            ];

            if ($categoryIds !== []) {
                $rootFilters[] = ['terms' => ['category_ids' => array_values(array_map('intval', $categoryIds))]];
            }

            $rootFilters = array_merge($rootFilters, $this->compileSelectedFilterClauses($filters, $facetDefinitions, $code));

            $aggs[$this->buildFacetAggName($code)] = [
                'filter' => [
                    'bool' => [
                        'must' => [$this->buildKeywordQuery($keyword)],
                        'filter' => $rootFilters,
                    ],
                ],
                'aggs' => $this->buildSingleFacetAggregation($definition),
            ];
        }

        return $aggs;
    }

    private function buildSingleFacetAggregation(array $definition): array
    {
        return match ((string) ($definition['type'] ?? '')) {
            'eav' => $this->buildEavFacetAggregation($definition),
            'range', 'price' => $this->buildRangeFacetAggregation($definition),
            'stock' => $this->buildStockFacetAggregation($definition),
            default => [],
        };
    }

    private function buildEavFacetAggregation(array $definition): array
    {
        $bucketSize = max(1, (int) ($definition['bucket_size'] ?? 20));
        $mode = strtolower((string) ($definition['facet_mode'] ?? 'terms'));

        $bucketAggregation = [
            'terms' => [
                'field' => 'eav_facets.value_keyword',
                'size' => $bucketSize,
            ],
            'aggs' => [
                'label' => ['terms' => ['field' => 'eav_facets.value_text', 'size' => 1]],
                'swatch_color' => ['terms' => ['field' => 'eav_facets.swatch_color', 'size' => 1]],
                'swatch_image' => ['terms' => ['field' => 'eav_facets.swatch_image', 'size' => 1]],
                'swatch_text' => ['terms' => ['field' => 'eav_facets.swatch_text', 'size' => 1]],
                'display_type' => ['terms' => ['field' => 'eav_facets.display_type', 'size' => 1]],
                'attribute_label' => ['terms' => ['field' => 'eav_facets.attribute_label', 'size' => 1]],
            ],
        ];

        if ($mode === 'range') {
            $ranges = $this->normalizeRangeDefinitions((array) ($definition['range_buckets'] ?? []));
            if ($ranges !== []) {
                $bucketAggregation = [
                    'range' => [
                        'field' => 'eav_facets.value_number',
                        'ranges' => $ranges,
                    ],
                ];
            }
        }

        return [
            'facet_nested' => [
                'nested' => ['path' => 'eav_facets'],
                'aggs' => [
                    'facet_attribute' => [
                        'filter' => ['term' => ['eav_facets.attribute_code' => (string) ($definition['attribute_code'] ?? '')]],
                        'aggs' => ['facet_buckets' => $bucketAggregation],
                    ],
                ],
            ],
        ];
    }

    private function buildRangeFacetAggregation(array $definition): array
    {
        return [
            'facet_buckets' => [
                'range' => [
                    'field' => (string) ($definition['field'] ?? 'price'),
                    'ranges' => $this->normalizeRangeDefinitions((array) ($definition['range_buckets'] ?? [])),
                ],
            ],
        ];
    }

    private function buildStockFacetAggregation(array $definition): array
    {
        $buckets = [];
        foreach ((array) ($definition['buckets'] ?? []) as $bucket) {
            if (!is_array($bucket)) {
                continue;
            }

            $code = trim((string) ($bucket['key'] ?? $bucket['value'] ?? ''));
            if ($code === '') {
                continue;
            }

            $buckets[$code] = $this->buildStockBucketQuery($code, (int) ($definition['low_stock_threshold'] ?? 10));
        }

        return [
            'facet_buckets' => [
                'filters' => [
                    'filters' => $buckets,
                ],
            ],
        ];
    }

    private function compileSelectedFilterClauses(array $filters, array $facetDefinitions, ?string $excludingFacetCode = null): array
    {
        $clauses = [];
        $hasPriceFacetDefinition = false;

        foreach ($facetDefinitions as $code => $definition) {
            if ($excludingFacetCode !== null && $excludingFacetCode === $code) {
                continue;
            }

            $selectedValues = $this->normalizeSelectedValues($filters[$code] ?? null);
            if ($selectedValues === []) {
                continue;
            }

            $type = (string) ($definition['type'] ?? '');
            $field = (string) ($definition['field'] ?? 'price');
            if ($type === 'range' || $type === 'price') {
                $hasPriceFacetDefinition = $hasPriceFacetDefinition || $field === 'price' || $code === 'price';
            }

            if ($type === 'eav') {
                $clauses[] = [
                    'nested' => [
                        'path' => 'eav_facets',
                        'query' => [
                            'bool' => [
                                'filter' => [
                                    ['term' => ['eav_facets.attribute_code' => (string) ($definition['attribute_code'] ?? '')]],
                                    ['terms' => ['eav_facets.value_keyword' => array_values($selectedValues)]],
                                ],
                            ],
                        ],
                    ],
                ];
                continue;
            }

            if ($type === 'range' || $type === 'price') {
                $rangeFilters = [];
                foreach ($selectedValues as $value) {
                    $parsed = $this->parseRangeValue($value);
                    if ($parsed === null) {
                        continue;
                    }

                    $range = [];
                    if ($parsed['from'] !== null) {
                        $range['gte'] = $parsed['from'];
                    }
                    if ($parsed['to'] !== null) {
                        $range['lte'] = $parsed['to'];
                    }
                    if ($range !== []) {
                        $rangeFilters[] = ['range' => [$field => $range]];
                    }
                }

                if ($rangeFilters !== []) {
                    $clauses[] = [
                        'bool' => [
                            'should' => $rangeFilters,
                            'minimum_should_match' => 1,
                        ],
                    ];
                }
                continue;
            }

            if ($type === 'stock') {
                $stockFilters = [];
                foreach ($selectedValues as $value) {
                    $stockFilters[] = $this->buildStockBucketQuery($value, (int) ($definition['low_stock_threshold'] ?? 10));
                }

                if ($stockFilters !== []) {
                    $clauses[] = [
                        'bool' => [
                            'should' => $stockFilters,
                            'minimum_should_match' => 1,
                        ],
                    ];
                }
            }
        }

        if (($excludingFacetCode === null || $excludingFacetCode !== 'price') && !$hasPriceFacetDefinition) {
            $legacyPriceClause = $this->buildLegacyPriceClause($filters);
            if ($legacyPriceClause !== null) {
                $clauses[] = $legacyPriceClause;
            }
        }

        return $clauses;
    }

    private function buildLegacyPriceClause(array $filters): ?array
    {
        $hasMin = array_key_exists('price_min', $filters) && $filters['price_min'] !== '' && $filters['price_min'] !== null;
        $hasMax = array_key_exists('price_max', $filters) && $filters['price_max'] !== '' && $filters['price_max'] !== null;

        if (!$hasMin && !$hasMax) {
            return null;
        }

        $range = [];
        if ($hasMin) {
            $range['gte'] = (float) $filters['price_min'];
        }
        if ($hasMax) {
            $range['lte'] = (float) $filters['price_max'];
        }

        return $range === [] ? null : ['range' => ['price' => $range]];
    }

    private function normalizeFacetBuckets(array $aggregations, array $request): array
    {
        $normalized = [];
        $definitions = is_array($request['facet_definitions'] ?? null) ? $request['facet_definitions'] : [];

        foreach ($definitions as $code => $definition) {
            $facetAggregation = is_array($aggregations[$this->buildFacetAggName($code)] ?? null)
                ? $aggregations[$this->buildFacetAggName($code)]
                : [];

            $type = (string) ($definition['type'] ?? '');
            if ($type === 'eav') {
                $normalized[$code] = $this->normalizeEavBuckets($facetAggregation['facet_nested']['facet_attribute']['facet_buckets']['buckets'] ?? [], $definition);
                continue;
            }

            if ($type === 'range' || $type === 'price') {
                $normalized[$code] = $this->normalizeRangeBuckets($facetAggregation['facet_buckets']['buckets'] ?? [], $definition);
                continue;
            }

            if ($type === 'stock') {
                $normalized[$code] = $this->normalizeStockBuckets($facetAggregation['facet_buckets']['buckets'] ?? [], $definition);
            }
        }

        return $normalized;
    }

    private function normalizeEavBuckets(array $buckets, array $definition): array
    {
        $normalized = [];

        foreach ($buckets as $bucket) {
            if (!is_array($bucket) || (int) ($bucket['doc_count'] ?? 0) <= 0) {
                continue;
            }

            $value = (string) ($bucket['key'] ?? '');
            if ($value === '') {
                continue;
            }

            $normalized[] = [
                'key' => $value,
                'value' => $value,
                'count' => (int) ($bucket['doc_count'] ?? 0),
                'label' => $this->extractNestedAggValue($bucket, 'label', $value),
                'display_type' => $this->extractNestedAggValue($bucket, 'display_type', (string) ($definition['display_type'] ?? 'list')),
                'swatch_color' => $this->extractNestedAggValue($bucket, 'swatch_color', ''),
                'swatch_image' => $this->extractNestedAggValue($bucket, 'swatch_image', ''),
                'swatch_text' => $this->extractNestedAggValue($bucket, 'swatch_text', ''),
                'attribute_label' => $this->extractNestedAggValue($bucket, 'attribute_label', (string) ($definition['name'] ?? $value)),
            ];
        }

        return $normalized;
    }

    private function normalizeRangeBuckets(array $buckets, array $definition): array
    {
        $normalized = [];
        $rangeMap = [];

        foreach ((array) ($definition['range_buckets'] ?? []) as $rangeDefinition) {
            if (!is_array($rangeDefinition)) {
                continue;
            }

            $rangeMap[(string) ($rangeDefinition['key'] ?? $this->buildRangeKey($rangeDefinition['from'] ?? null, $rangeDefinition['to'] ?? null))] = $rangeDefinition;
        }

        foreach ($buckets as $bucket) {
            if (!is_array($bucket) || (int) ($bucket['doc_count'] ?? 0) <= 0) {
                continue;
            }

            $key = (string) ($bucket['key'] ?? $this->buildRangeKey($bucket['from'] ?? null, $bucket['to'] ?? null));
            $rangeDefinition = $rangeMap[$key] ?? [];
            $normalized[] = [
                'key' => $key,
                'value' => $key,
                'count' => (int) ($bucket['doc_count'] ?? 0),
                'label' => (string) ($rangeDefinition['label'] ?? $key),
                'from' => $bucket['from'] ?? null,
                'to' => $bucket['to'] ?? null,
            ];
        }

        return $normalized;
    }

    private function normalizeStockBuckets(array $buckets, array $definition): array
    {
        $normalized = [];
        $bucketDefinitions = [];

        foreach ((array) ($definition['buckets'] ?? []) as $bucketDefinition) {
            if (!is_array($bucketDefinition)) {
                continue;
            }

            $key = trim((string) ($bucketDefinition['key'] ?? $bucketDefinition['value'] ?? ''));
            if ($key === '') {
                continue;
            }

            $bucketDefinitions[$key] = $bucketDefinition;
        }

        foreach ($buckets as $key => $bucket) {
            $count = (int) ($bucket['doc_count'] ?? 0);
            if ($count <= 0) {
                continue;
            }

            $bucketDefinition = $bucketDefinitions[(string) $key] ?? [];
            $normalized[] = [
                'key' => (string) $key,
                'value' => (string) $key,
                'count' => $count,
                'label' => (string) ($bucketDefinition['label'] ?? $key),
            ];
        }

        return $normalized;
    }

    private function buildKeywordQuery(string $keyword): array
    {
        if ($keyword === '') {
            return ['match_all' => (object) []];
        }

        return [
            'multi_match' => [
                'query' => $keyword,
                'fields' => [
                    'name^4',
                    'sku^3',
                    'spu^3',
                    'category_names^2',
                    'short_description^2',
                    'searchable_text^2',
                    'eav_search_text^2',
                    'description',
                ],
                'type' => 'best_fields',
            ],
        ];
    }

    private function extractSuggestionItems(array $hits, int $limit, string $sourceKey = ''): array
    {
        $suggestions = [];
        $seen = [];

        foreach ($hits as $hit) {
            $row = $hit;
            if ($sourceKey !== '' && is_array($hit) && is_array($hit[$sourceKey] ?? null)) {
                $row = $hit[$sourceKey];
            }

            if (!is_array($row)) {
                continue;
            }

            $documentType = trim((string) ($row['document_type'] ?? 'product'));
            $entityId = trim((string) ($row['entity_id'] ?? ''));
            $url = trim((string) ($row['url'] ?? ''));

            if ($url === '' && $entityId !== '') {
                $url = $documentType === 'category'
                    ? '/catalog/category/view?id=' . $entityId
                    : '/product/view?id=' . $entityId;
            }

            $candidates = array_filter([
                trim((string) ($row['name'] ?? '')),
                $documentType === 'category' ? '' : trim((string) ($row['sku'] ?? '')),
            ]);

            foreach ($candidates as $text) {
                if ($text === '' || isset($seen[$text])) {
                    continue;
                }

                $suggestions[] = [
                    'text' => $text,
                    'type' => $documentType === 'category' ? 'category' : 'product',
                    'icon' => $documentType === 'category' ? 'fa-folder' : 'fa-shopping-bag',
                    'url' => $url !== '' ? $url : '/search?q=' . urlencode($text),
                ];
                $seen[$text] = true;

                if (count($suggestions) >= $limit) {
                    break 2;
                }
            }
        }

        return $suggestions;
    }

    private function buildIndexDefinition(array $definition): array
    {
        return [
            'settings' => [
                'index' => [
                    'number_of_shards' => 1,
                    'number_of_replicas' => 0,
                ],
            ],
            'mappings' => [
                'properties' => [
                    'document_id' => ['type' => 'keyword'],
                    'document_type' => ['type' => 'keyword'],
                    'entity_id' => ['type' => 'integer'],
                    'name' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
                    'sku' => ['type' => 'keyword'],
                    'spu' => ['type' => 'keyword'],
                    'handle' => ['type' => 'keyword'],
                    'url' => ['type' => 'keyword'],
                    'image' => ['type' => 'keyword'],
                    'searchable_text' => ['type' => 'text'],
                    'eav_search_text' => ['type' => 'text'],
                    'short_description' => ['type' => 'text'],
                    'description' => ['type' => 'text'],
                    'category_ids' => ['type' => 'integer'],
                    'category_names' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
                    'price' => ['type' => 'float'],
                    'stock' => ['type' => 'integer'],
                    'status' => ['type' => 'integer'],
                    'eav_facets' => [
                        'type' => 'nested',
                        'properties' => [
                            'attribute_id' => ['type' => 'integer'],
                            'attribute_code' => ['type' => 'keyword'],
                            'attribute_label' => ['type' => 'keyword'],
                            'value_keyword' => ['type' => 'keyword'],
                            'value_text' => ['type' => 'keyword'],
                            'value_number' => ['type' => 'float'],
                            'display_type' => ['type' => 'keyword'],
                            'has_option' => ['type' => 'boolean'],
                            'is_multiple' => ['type' => 'boolean'],
                            'swatch_color' => ['type' => 'keyword'],
                            'swatch_image' => ['type' => 'keyword'],
                            'swatch_text' => ['type' => 'keyword'],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function normalizeRangeDefinitions(array $rangeDefinitions): array
    {
        $normalized = [];

        foreach ($rangeDefinitions as $rangeDefinition) {
            if (!is_array($rangeDefinition)) {
                continue;
            }

            $item = [];
            if (array_key_exists('from', $rangeDefinition) && $rangeDefinition['from'] !== null && $rangeDefinition['from'] !== '') {
                $item['from'] = (float) $rangeDefinition['from'];
            }
            if (array_key_exists('to', $rangeDefinition) && $rangeDefinition['to'] !== null && $rangeDefinition['to'] !== '') {
                $item['to'] = (float) $rangeDefinition['to'];
            }

            $item['key'] = (string) ($rangeDefinition['key'] ?? $this->buildRangeKey($item['from'] ?? null, $item['to'] ?? null));
            $normalized[] = $item;
        }

        return $normalized;
    }

    private function fallbackBrowse(array $request): array
    {
        $result = w_query('product', 'searchProducts', [
            'keyword' => (string) ($request['keyword'] ?? ''),
            'filters' => is_array($request['filters'] ?? null) ? $request['filters'] : [],
            'page' => (int) ($request['page'] ?? 1),
            'page_size' => (int) ($request['page_size'] ?? 20),
        ]);

        $result = is_array($result) ? $result : [];
        $page = (int) ($request['page'] ?? 1);
        $pageSize = (int) ($request['page_size'] ?? 20);
        $total = (int) ($result['total'] ?? 0);

        return [
            'items' => is_array($result['items'] ?? null) ? $result['items'] : [],
            'total' => $total,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'pages' => $pageSize > 0 ? (int) ceil($total / $pageSize) : 0,
                'total' => $total,
                'from' => $total > 0 ? (($page - 1) * $pageSize) + 1 : 0,
                'to' => $total > 0 ? min($page * $pageSize, $total) : 0,
                'html' => (string) ($result['pagination'] ?? ''),
            ],
            'facets' => [],
            'engine' => 'mysql',
            'fallback' => true,
        ];
    }

    private function fallbackSuggestions(string $keyword, int $limit): array
    {
        $suggestions = [];

        foreach ([
            w_query('product', 'getProductSuggestions', ['keyword' => $keyword, 'limit' => min(5, $limit)]),
            w_query('catalog', 'getCategorySuggestions', ['keyword' => $keyword, 'limit' => min(3, $limit)]),
        ] as $source) {
            if (!is_array($source)) {
                continue;
            }

            foreach ($source as $item) {
                $text = '';
                if (is_string($item)) {
                    $text = trim($item);
                } elseif (is_array($item)) {
                    $text = trim((string) ($item['text'] ?? $item['name'] ?? $item['sku'] ?? ''));
                }

                if ($text === '' || in_array($text, $suggestions, true)) {
                    continue;
                }

                $suggestions[] = $text;
                if (count($suggestions) >= $limit) {
                    return $suggestions;
                }
            }
        }

        return $suggestions;
    }

    private function buildBaseUrl(): string
    {
        $host = (string) ($this->config['host'] ?? 'http://127.0.0.1');
        $port = (int) ($this->config['port'] ?? 9200);

        if (!preg_match('#^https?://#i', $host)) {
            $host = 'http://' . $host;
        }

        $parts = parse_url($host);
        $scheme = $parts['scheme'] ?? 'http';
        $resolvedHost = $parts['host'] ?? trim(str_replace(['http://', 'https://'], '', $host), '/');
        $resolvedPort = isset($parts['port']) ? (int) $parts['port'] : $port;

        return sprintf('%s://%s:%d', $scheme, $resolvedHost, $resolvedPort);
    }

    private function buildIndexUrl(string $suffix = ''): string
    {
        $index = trim((string) ($this->config['index'] ?? 'products'), '/');

        return $this->buildBaseUrl() . '/' . $index . $suffix;
    }

    private function buildHeaders(string $contentType = 'application/json'): array
    {
        $headers = [
            'Accept: application/json',
            'Content-Type: ' . $contentType,
        ];

        $username = (string) ($this->config['username'] ?? '');
        $password = (string) ($this->config['password'] ?? '');

        if ($username !== '' || $password !== '') {
            $headers[] = 'Authorization: Basic ' . base64_encode($username . ':' . $password);
        }

        return $headers;
    }

    private function normalizeSelectedValues(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (!is_array($value)) {
            $value = str_contains((string) $value, ',') ? explode(',', (string) $value) : [(string) $value];
        }

        return array_values(array_filter(array_map(static fn ($item): string => trim((string) $item), $value)));
    }

    private function parseRangeValue(string $value): ?array
    {
        if (!preg_match('/^([0-9]+(?:\.[0-9]+)?)?-([0-9]+(?:\.[0-9]+)?)?$/', trim($value), $matches)) {
            return null;
        }

        return [
            'from' => $matches[1] !== '' ? (float) $matches[1] : null,
            'to' => $matches[2] !== '' ? (float) $matches[2] : null,
        ];
    }

    private function buildStockBucketQuery(string $value, int $lowStockThreshold): array
    {
        return match ($value) {
            'in_stock' => ['range' => ['stock' => ['gt' => 0]]],
            'out_of_stock' => ['range' => ['stock' => ['lte' => 0]]],
            'low_stock' => [
                'bool' => [
                    'filter' => [
                        ['range' => ['stock' => ['gt' => 0]]],
                        ['range' => ['stock' => ['lte' => $lowStockThreshold]]],
                    ],
                ],
            ],
            default => ['match_none' => (object) []],
        };
    }

    private function extractNestedAggValue(array $bucket, string $aggName, string $default = ''): string
    {
        $items = $bucket[$aggName]['buckets'] ?? [];
        if (!is_array($items) || $items === []) {
            return $default;
        }

        $first = reset($items);
        if (!is_array($first)) {
            return $default;
        }

        $value = (string) ($first['key'] ?? '');

        return $value !== '' ? $value : $default;
    }

    private function buildFacetAggName(string $code): string
    {
        return 'facet__' . preg_replace('/[^a-z0-9_]+/i', '_', strtolower($code));
    }

    private function buildRangeKey(mixed $from, mixed $to): string
    {
        $fromValue = $from === null || $from === '' ? '' : (string) $from;
        $toValue = $to === null || $to === '' ? '' : (string) $to;

        return $fromValue . '-' . $toValue;
    }
}
