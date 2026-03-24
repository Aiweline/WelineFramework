<?php

declare(strict_types=1);

namespace WeShop\Search\Engine;

use WeShop\Search\Api\SearchEngineInterface;

/**
 * Algolia search engine adapter.
 */
class AlgoliaEngine implements SearchEngineInterface
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

        if ($keyword === '' || !$this->hasRequiredCredentials()) {
            return [
                'items' => [],
                'total' => 0,
            ];
        }

        $payload = [
            'query' => $keyword,
            'page' => $page - 1,
            'hitsPerPage' => $pageSize,
        ];

        $filtersString = $this->buildFilters($filters);
        if ($filtersString !== '') {
            $payload['filters'] = $filtersString;
        }

        if (!empty($filters['order_by'])) {
            $payload['sortFacetValuesBy'] = strtolower((string) ($filters['order_dir'] ?? 'desc')) === 'asc' ? 'alpha' : 'count';
        }

        try {
            $response = $this->requestJson(
                'POST',
                $this->buildQueryUrl(),
                $payload,
                $this->buildHeaders()
            );

            $body = $response['body'] ?? [];

            return [
                'items' => is_array($body['hits'] ?? null) ? $body['hits'] : [],
                'total' => (int) ($body['nbHits'] ?? 0),
            ];
        } catch (\Throwable $throwable) {
            w_log_error('Algolia search failed: ' . $throwable->getMessage());

            return $this->fallbackSearch($keyword, $filters, $page, $pageSize);
        }
    }

    public function getSuggestions(string $keyword, int $limit = 10): array
    {
        $keyword = trim($keyword);
        $limit = max(1, $limit);

        if ($keyword === '' || !$this->hasRequiredCredentials()) {
            return [];
        }

        try {
            $response = $this->requestJson(
                'POST',
                $this->buildQueryUrl(),
                [
                    'query' => $keyword,
                    'page' => 0,
                    'hitsPerPage' => $limit,
                    'attributesToRetrieve' => ['name', 'sku'],
                ],
                $this->buildHeaders()
            );

            $hits = $response['body']['hits'] ?? [];

            return $this->extractUniqueSuggestionStrings($hits, $limit);
        } catch (\Throwable $throwable) {
            w_log_error('Algolia suggestions failed: ' . $throwable->getMessage());

            return $this->fallbackSuggestions($keyword, $limit);
        }
    }

    public function initConfig(array $config): bool
    {
        $this->config = [
            'application_id' => trim((string) ($config['application_id'] ?? '')),
            'api_key' => trim((string) ($config['api_key'] ?? '')),
            'index_name' => trim((string) ($config['index_name'] ?? 'products')) ?: 'products',
            'timeout' => max(1, (int) ($config['timeout'] ?? 5)),
        ];

        return true;
    }

    public function testConnection(): bool
    {
        if (!$this->hasRequiredCredentials()) {
            return false;
        }

        try {
            $response = $this->requestJson('GET', $this->buildBaseUrl(), [], $this->buildHeaders());

            return ($response['status'] ?? 0) >= 200 && ($response['status'] ?? 0) < 300;
        } catch (\Throwable $throwable) {
            w_log_error('Algolia connection test failed: ' . $throwable->getMessage());

            return false;
        }
    }

    public function getEngineType(): string
    {
        return 'algolia';
    }

    public function getEngineName(): string
    {
        return 'Algolia';
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

    private function hasRequiredCredentials(): bool
    {
        return (string) ($this->config['application_id'] ?? '') !== ''
            && (string) ($this->config['api_key'] ?? '') !== '';
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function buildFilters(array $filters): string
    {
        $compiled = ['status:1'];

        if (!empty($filters['category_id'])) {
            $compiled[] = 'category_ids:' . (int) $filters['category_id'];
        }

        if (($filters['price_min'] ?? '') !== '') {
            $compiled[] = 'price >= ' . (float) $filters['price_min'];
        }

        if (($filters['price_max'] ?? '') !== '') {
            $compiled[] = 'price <= ' . (float) $filters['price_max'];
        }

        return implode(' AND ', $compiled);
    }

    private function buildBaseUrl(): string
    {
        return sprintf(
            'https://%s-dsn.algolia.net',
            rawurlencode((string) ($this->config['application_id'] ?? ''))
        );
    }

    private function buildQueryUrl(): string
    {
        return $this->buildBaseUrl() . '/1/indexes/' . rawurlencode((string) ($this->config['index_name'] ?? 'products')) . '/query';
    }

    /**
     * @return array<int, string>
     */
    private function buildHeaders(): array
    {
        return [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Algolia-API-Key: ' . (string) ($this->config['api_key'] ?? ''),
            'X-Algolia-Application-Id: ' . (string) ($this->config['application_id'] ?? ''),
        ];
    }

    /**
     * @param array<int, mixed> $hits
     * @return array<int, string>
     */
    private function extractUniqueSuggestionStrings(array $hits, int $limit): array
    {
        $suggestions = [];

        foreach ($hits as $hit) {
            if (!is_array($hit)) {
                continue;
            }

            foreach (['name', 'sku'] as $field) {
                $value = trim((string) ($hit[$field] ?? ''));
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
