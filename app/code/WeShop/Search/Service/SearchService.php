<?php

declare(strict_types=1);

namespace WeShop\Search\Service;

use WeShop\Search\Api\SearchEngineInterface;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Search\Model\SearchHistory;

/**
 * 搜索服务
 */
class SearchService
{
    private const SEARCH_ROUTE = '/search';

    /**
     * 搜索商品
     *
     * @param string $keyword 关键词
     * @param array $filters 过滤条件
     * @param int $page 页码
     * @param int $pageSize 每页数量
     * @param string $scope 作用域
     * @return array
     */
    public function searchProducts(string $keyword, array $filters = [], int $page = 1, int $pageSize = 20, string $scope = 'default'): array
    {
        $engine = $this->createEngine($scope);

        if (!$engine) {
            return $this->fallbackSearch($keyword, $filters, $page, $pageSize);
        }

        $result = $engine->search($keyword, $filters, $page, $pageSize);
        $total = (int) ($result['total'] ?? 0);

        if (!empty($keyword)) {
            /** @var SearchHistory $searchHistory */
            $searchHistory = $this->getSearchHistoryModel();
            $userId = $this->getCurrentUserId();
            $searchHistory->recordSearch($keyword, $total, $userId);
        }

        return [
            'items' => \is_array($result['items'] ?? null) ? $result['items'] : [],
            'total' => $total,
            'pagination' => (string) ($result['pagination'] ?? ''),
            'keyword' => $keyword,
            'engine' => $engine->getEngineType(),
        ];
    }

    /**
     * 回退搜索（默认 MySQL 搜索）
     */
    private function fallbackSearch(string $keyword, array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        $result = w_query('product', 'searchProducts', [
            'keyword' => $keyword,
            'filters' => $filters,
            'page' => $page,
            'page_size' => $pageSize,
        ]);
        $result = \is_array($result) ? $result : [];

        return [
            'items' => \is_array($result['items'] ?? null) ? $result['items'] : [],
            'total' => (int) ($result['total'] ?? 0),
            'pagination' => (string) ($result['pagination'] ?? ''),
            'keyword' => $keyword,
            'engine' => 'mysql',
        ];
    }

    /**
     * 获取搜索建议
     *
     * @param string $keyword 关键词
     * @param int $limit 返回数量
     * @param string $scope 作用域
     * @return array
     */
    public function getSearchSuggestions(string $keyword, int $limit = 10, string $scope = 'default'): array
    {
        if (empty(\trim($keyword))) {
            return [];
        }

        $keyword = \trim($keyword);
        $engine = $this->createEngine($scope);

        if ($engine) {
            $engineSuggestions = $engine->getSuggestions($keyword, $limit);
            if (!empty($engineSuggestions)) {
                return \array_slice($this->normalizeSuggestions($engineSuggestions), 0, $limit);
            }
        }

        $suggestions = [];
        $productSuggestions = w_query('product', 'getProductSuggestions', [
            'keyword' => $keyword,
            'limit' => \min(5, $limit),
        ]);
        if (\is_array($productSuggestions)) {
            $suggestions = $this->appendUniqueSuggestions($suggestions, $productSuggestions, $limit);
        }

        if (\count($suggestions) < $limit) {
            $categorySuggestions = w_query('catalog', 'getCategorySuggestions', [
                'keyword' => $keyword,
                'limit' => \min(3, $limit - \count($suggestions)),
            ]);
            if (\is_array($categorySuggestions)) {
                $suggestions = $this->appendUniqueSuggestions($suggestions, $categorySuggestions, $limit);
            }
        }

        if (\count($suggestions) < $limit) {
            /** @var SearchHistory $searchHistory */
            $searchHistory = $this->getSearchHistoryModel();
            $searchHistory->clear();
            $searchHistory->where(SearchHistory::schema_fields_KEYWORD, '%' . $keyword . '%', 'like')
                ->order(SearchHistory::schema_fields_SEARCH_COUNT, 'DESC')
                ->limit(\min(3, $limit - \count($suggestions)));

            $histories = $searchHistory->select()->fetchArray();
            foreach ($histories as $item) {
                $text = (string) ($item[SearchHistory::schema_fields_KEYWORD] ?? '');
                if ($text === '' || $this->hasSuggestionText($suggestions, $text)) {
                    continue;
                }
                $suggestions[] = [
                    'text' => $text,
                    'type' => 'history',
                    'icon' => 'fa-history',
                    'url' => $this->buildSearchUrl($text),
                ];
                if (\count($suggestions) >= $limit) {
                    break;
                }
            }
        }

        return \array_slice($suggestions, 0, $limit);
    }

    /**
     * 获取热门搜索词
     */
    public function getPopularKeywords(int $limit = 10): array
    {
        /** @var SearchHistory $searchHistory */
        $searchHistory = ObjectManager::getInstance(SearchHistory::class);
        return $searchHistory->getPopularKeywords($limit);
    }

    /**
     * 获取当前用户 ID
     */
    private function getCurrentUserId(): ?int
    {
        return null;
    }

    protected function createEngine(string $scope): ?SearchEngineInterface
    {
        return SearchEngineFactory::create($scope);
    }

    protected function getSearchHistoryModel(): SearchHistory
    {
        /** @var SearchHistory $searchHistory */
        $searchHistory = ObjectManager::getInstance(SearchHistory::class);

        return $searchHistory;
    }

    private function appendUniqueSuggestions(array $target, array $candidates, int $limit): array
    {
        foreach ($candidates as $candidate) {
            $candidate = $this->normalizeSuggestion($candidate);
            $text = (string) ($candidate['text'] ?? '');
            if ($text === '' || $this->hasSuggestionText($target, $text)) {
                continue;
            }
            $target[] = $candidate;
            if (\count($target) >= $limit) {
                break;
            }
        }

        return $target;
    }

    private function hasSuggestionText(array $suggestions, string $text): bool
    {
        foreach ($suggestions as $suggestion) {
            if ((string) ($suggestion['text'] ?? '') === $text) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, mixed> $suggestions
     * @return array<int, array<string, string>>
     */
    private function normalizeSuggestions(array $suggestions): array
    {
        $normalized = [];

        foreach ($suggestions as $suggestion) {
            $item = $this->normalizeSuggestion($suggestion);
            if (($item['text'] ?? '') === '') {
                continue;
            }

            $normalized[] = $item;
        }

        return $normalized;
    }

    /**
     * @return array<string, string>
     */
    private function normalizeSuggestion(mixed $suggestion): array
    {
        if (is_string($suggestion)) {
            return [
                'text' => $suggestion,
                'type' => 'search',
                'icon' => 'fa-search',
                'url' => $this->buildSearchUrl($suggestion),
            ];
        }

        if (!is_array($suggestion)) {
            return [];
        }

        $text = trim((string) ($suggestion['text'] ?? $suggestion['query'] ?? $suggestion['name'] ?? $suggestion['sku'] ?? ''));
        if ($text === '') {
            return [];
        }

        return [
            'text' => $text,
            'type' => (string) ($suggestion['type'] ?? 'search'),
            'icon' => (string) ($suggestion['icon'] ?? 'fa-search'),
            'url' => (string) ($suggestion['url'] ?? $this->buildSearchUrl($text)),
        ];
    }

    private function buildSearchUrl(string $keyword): string
    {
        return self::SEARCH_ROUTE . '?q=' . \urlencode($keyword);
    }
}
