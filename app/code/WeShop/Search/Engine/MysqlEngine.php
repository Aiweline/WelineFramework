<?php

declare(strict_types=1);

namespace WeShop\Search\Engine;

use WeShop\Search\Api\SearchEngineInterface;

/**
 * MySQL 搜索引擎适配器
 */
class MysqlEngine implements SearchEngineInterface
{
    private array $config = [];

    /**
     * @inheritDoc
     */
    public function search(string $keyword, array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        $result = w_query('product', 'searchProducts', [
            'keyword' => $keyword,
            'filters' => $filters,
            'page' => $page,
            'page_size' => $pageSize,
        ]);

        return \is_array($result) ? $result : ['items' => [], 'total' => 0, 'pagination' => ''];
    }

    /**
     * @inheritDoc
     */
    public function getSuggestions(string $keyword, int $limit = 10): array
    {
        $suggestions = [];

        $productSuggestions = w_query('product', 'getProductSuggestions', [
            'keyword' => $keyword,
            'limit' => \min(5, $limit),
        ]);
        if (\is_array($productSuggestions)) {
            $suggestions = \array_merge($suggestions, $productSuggestions);
        }

        if (\count($suggestions) < $limit) {
            $categorySuggestions = w_query('catalog', 'getCategorySuggestions', [
                'keyword' => $keyword,
                'limit' => \min(3, $limit - \count($suggestions)),
            ]);
            if (\is_array($categorySuggestions)) {
                $suggestions = \array_merge($suggestions, $categorySuggestions);
            }
        }

        return \array_slice($suggestions, 0, $limit);
    }

    /**
     * @inheritDoc
     */
    public function initConfig(array $config): bool
    {
        $this->config = $config;
        return true;
    }

    /**
     * @inheritDoc
     */
    public function testConnection(): bool
    {
        try {
            $result = w_query('product', 'searchProducts', [
                'keyword' => '',
                'filters' => [],
                'page' => 1,
                'page_size' => 1,
            ]);

            return \is_array($result);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function getEngineType(): string
    {
        return 'mysql';
    }

    /**
     * @inheritDoc
     */
    public function getEngineName(): string
    {
        return 'MySQL全文搜索';
    }
}
