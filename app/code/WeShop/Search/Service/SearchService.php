<?php

declare(strict_types=1);

namespace WeShop\Search\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\Search\Model\SearchHistory;

/**
 * 搜索服务
 */
class SearchService
{
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
        $engine = SearchEngineFactory::create($scope);

        if (!$engine) {
            return $this->fallbackSearch($keyword, $filters, $page, $pageSize);
        }

        $result = $engine->search($keyword, $filters, $page, $pageSize);
        $total = (int) ($result['total'] ?? 0);

        if (!empty($keyword)) {
            /** @var SearchHistory $searchHistory */
            $searchHistory = ObjectManager::getInstance(SearchHistory::class);
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
        $engine = SearchEngineFactory::create($scope);

        if ($engine) {
            $engineSuggestions = $engine->getSuggestions($keyword, $limit);
            if (!empty($engineSuggestions)) {
                return \array_slice($engineSuggestions, 0, $limit);
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
            $searchHistory = ObjectManager::getInstance(SearchHistory::class);
            $searchHistory->clear();
            $searchHistory->where(SearchHistory::schema_fields_KEYWORD, ['like', '%' . $keyword . '%'])
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
                    'url' => '/search/index?q=' . \urlencode($text),
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

    private function appendUniqueSuggestions(array $target, array $candidates, int $limit): array
    {
        foreach ($candidates as $candidate) {
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
}
