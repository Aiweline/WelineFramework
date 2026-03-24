<?php

declare(strict_types=1);

namespace WeShop\Search\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use WeShop\Search\Model\SearchHistory;

class SearchHistoryTest extends TestCase
{
    public function testGetPopularKeywordsUsesExplicitDatetimeComparisonSignature(): void
    {
        $history = new class() extends SearchHistory {
            public array $capturedWhere = [];

            public function clear(bool $with_query = true): static
            {
                return $this;
            }

            public function where(
                array|string $field,
                mixed $value = null,
                string $condition = '=',
                string $where_logic = 'AND',
                string $array_where_logic_type = 'AND'
            ): static {
                $this->capturedWhere = [$field, $value, $condition, $where_logic, $array_where_logic_type];

                return $this;
            }

            public function order(string $field = '', string $sort = 'DESC'): static
            {
                return $this;
            }

            public function limit(int $size, int $offset = 0): static
            {
                return $this;
            }

            public function select(string $fields = ''): static
            {
                return $this;
            }

            public function fetchArray(): array
            {
                return [
                    [
                        SearchHistory::schema_fields_KEYWORD => 'bag',
                        SearchHistory::schema_fields_SEARCH_COUNT => 8,
                    ],
                ];
            }
        };

        $result = $history->getPopularKeywords(5, 30);

        $this->assertSame(SearchHistory::schema_fields_CREATED_AT, $history->capturedWhere[0]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $history->capturedWhere[1]);
        $this->assertSame('>=', $history->capturedWhere[2]);
        $this->assertSame([
            ['keyword' => 'bag', 'count' => 8],
        ], $result);
    }

    public function testGetPopularKeywordsCollapsesDuplicateKeywords(): void
    {
        $history = new class() extends SearchHistory {
            public function clear(bool $with_query = true): static
            {
                return $this;
            }

            public function where(
                array|string $field,
                mixed $value = null,
                string $condition = '=',
                string $where_logic = 'AND',
                string $array_where_logic_type = 'AND'
            ): static {
                return $this;
            }

            public function order(string $field = '', string $sort = 'DESC'): static
            {
                return $this;
            }

            public function limit(int $size, int $offset = 0): static
            {
                return $this;
            }

            public function select(string $fields = ''): static
            {
                return $this;
            }

            public function fetchArray(): array
            {
                return [
                    [
                        SearchHistory::schema_fields_KEYWORD => 'bag',
                        SearchHistory::schema_fields_SEARCH_COUNT => 3,
                    ],
                    [
                        SearchHistory::schema_fields_KEYWORD => 'lamp',
                        SearchHistory::schema_fields_SEARCH_COUNT => 2,
                    ],
                    [
                        SearchHistory::schema_fields_KEYWORD => 'bag',
                        SearchHistory::schema_fields_SEARCH_COUNT => 5,
                    ],
                ];
            }
        };

        $this->assertSame([
            ['keyword' => 'bag', 'count' => 8],
            ['keyword' => 'lamp', 'count' => 2],
        ], $history->getPopularKeywords(10, 30));
    }
}
