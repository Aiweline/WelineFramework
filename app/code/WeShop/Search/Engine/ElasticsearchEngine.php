<?php

declare(strict_types=1);

namespace WeShop\Search\Engine;

use WeShop\Search\Api\SearchEngineInterface;

/**
 * Elasticsearch search engine adapter.
 */
class ElasticsearchEngine implements SearchEngineInterface
{
    /**
     * @var array<string, mixed>
     */
    private array $config = [];

    public function search(string $keyword, array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        $keyword = trim($keyword);
        $page = max(1, $page);
        $pageSize = max(1, $pageSize);

        if ($keyword === '') {
            return [
                'items' => [],
                'total' => 0,
            ];
        }

        $payload = [
            'from' => ($page - 1) * $pageSize,
            'size' => $pageSize,
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'multi_match' => [
                                'query' => $keyword,
                                'fields' => ['name^4', 'sku^3', 'short_description^2', 'description'],
                                'type' => 'best_fields',
                            ],
                        ],
                    ],
                    'filter' => $this->buildSearchFilters($filters),
                ],
            ],
        ];

        if (!empty($filters['order_by'])) {
            $payload['sort'] = [[
                (string) $filters['order_by'] => [
                    'order' => strtolower((string) ($filters['order_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc',
                ],
            ]];
        }

        try {
            $response = $this->requestJson(
                'POST',
                $this->buildIndexUrl('/_search'),
                $payload,
                $this->buildHeaders()
            );

            return $this->normalizeSearchResult($response['body'] ?? []);
        } catch (\Throwable $throwable) {
            w_log_error('Elasticsearch search failed: ' . $throwable->getMessage());

            return $this->fallbackSearch($keyword, $filters, $page, $pageSize);
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
            '_source' => ['name', 'sku'],
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'multi_match' => [
                                'query' => $keyword,
                                'fields' => ['name^4', 'sku^3'],
                                'type' => 'best_fields',
                            ],
                        ],
                    ],
                    'filter' => [
                        ['term' => ['status' => 1]],
                    ],
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

            $hits = $response['body']['hits']['hits'] ?? [];

            return $this->extractUniqueSuggestionStrings($hits, $limit, '_source');
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

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $headers
     * @return array{status:int, body:array<string, mixed>}
     */
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

        if (!empty($payload) && strtoupper($method) !== 'GET') {
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
            return [
                'status' => $status,
                'body' => [],
            ];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON response.');
        }

        return [
            'status' => $status,
            'body' => $decoded,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function buildSearchFilters(array $filters): array
    {
        $compiledFilters = [
            ['term' => ['status' => 1]],
        ];

        if (!empty($filters['category_id'])) {
            $compiledFilters[] = [
                'term' => [
                    'category_ids' => (int) $filters['category_id'],
                ],
            ];
        }

        if (($filters['price_min'] ?? null) !== null || ($filters['price_max'] ?? null) !== null) {
            $range = [];
            if (($filters['price_min'] ?? '') !== '') {
                $range['gte'] = (float) $filters['price_min'];
            }
            if (($filters['price_max'] ?? '') !== '') {
                $range['lte'] = (float) $filters['price_max'];
            }
            if ($range !== []) {
                $compiledFilters[] = [
                    'range' => [
                        'price' => $range,
                    ],
                ];
            }
        }

        return $compiledFilters;
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

    /**
     * @return array<int, string>
     */
    private function buildHeaders(): array
    {
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        $username = (string) ($this->config['username'] ?? '');
        $password = (string) ($this->config['password'] ?? '');

        if ($username !== '' || $password !== '') {
            $headers[] = 'Authorization: Basic ' . base64_encode($username . ':' . $password);
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $body
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    private function normalizeSearchResult(array $body): array
    {
        $items = [];
        $hits = $body['hits']['hits'] ?? [];

        foreach ($hits as $hit) {
            if (!is_array($hit)) {
                continue;
            }

            $source = is_array($hit['_source'] ?? null) ? $hit['_source'] : [];
            $items[] = array_merge($source, [
                '_id' => $hit['_id'] ?? null,
                '_score' => $hit['_score'] ?? null,
            ]);
        }

        $total = $body['hits']['total']['value'] ?? $body['hits']['total'] ?? 0;

        return [
            'items' => $items,
            'total' => (int) $total,
        ];
    }

    /**
     * @param array<int, mixed> $hits
     * @return array<int, string>
     */
    private function extractUniqueSuggestionStrings(array $hits, int $limit, string $sourceKey = ''): array
    {
        $suggestions = [];

        foreach ($hits as $hit) {
            $row = $hit;
            if ($sourceKey !== '' && is_array($hit) && is_array($hit[$sourceKey] ?? null)) {
                $row = $hit[$sourceKey];
            }

            if (!is_array($row)) {
                continue;
            }

            foreach (['name', 'sku'] as $field) {
                $value = trim((string) ($row[$field] ?? ''));
                if ($value === '' || in_array($value, $suggestions, true)) {
                    continue;
                }
                $suggestions[] = $value;
                if (count($suggestions) >= $limit) {
                    return $suggestions;
                }
            }
        }

        return $suggestions;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items:array<int, mixed>, total:int, pagination:string}
     */
    private function fallbackSearch(string $keyword, array $filters, int $page, int $pageSize): array
    {
        $result = w_query('product', 'searchProducts', [
            'keyword' => $keyword,
            'filters' => $filters,
            'page' => $page,
            'page_size' => $pageSize,
        ]);

        if (!is_array($result)) {
            return [
                'items' => [],
                'total' => 0,
                'pagination' => '',
            ];
        }

        return [
            'items' => is_array($result['items'] ?? null) ? $result['items'] : [],
            'total' => (int) ($result['total'] ?? 0),
            'pagination' => (string) ($result['pagination'] ?? ''),
        ];
    }

    /**
     * @return array<int, string>
     */
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
}
