<?php

declare(strict_types=1);

namespace WeShop\Search\Test\Unit\Engine;

use PHPUnit\Framework\TestCase;
use WeShop\Search\Engine\ElasticsearchEngine;

class ElasticsearchEngineTest extends TestCase
{
    public function testSearchBuildsExpectedQueryAndNormalizesHits(): void
    {
        $engine = new class() extends ElasticsearchEngine {
            public array $captured = [];

            protected function requestJson(string $method, string $url, array $payload = [], array $headers = []): array
            {
                $this->captured = compact('method', 'url', 'payload', 'headers');

                return [
                    'status' => 200,
                    'body' => [
                        'hits' => [
                            'total' => ['value' => 42],
                            'hits' => [
                                [
                                    '_id' => 'p-101',
                                    '_score' => 5.6,
                                    '_source' => [
                                        'product_id' => 101,
                                        'name' => 'Travel Bag',
                                        'sku' => 'TB-001',
                                        'price' => 49.9,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ];
            }
        };
        $engine->initConfig([
            'host' => 'http://127.0.0.1',
            'port' => 9200,
            'index' => 'products',
        ]);

        $result = $engine->search('travel bag', [
            'category_id' => 5,
            'price_min' => 10,
            'price_max' => 99,
        ], 2, 15);

        $this->assertSame('POST', $engine->captured['method']);
        $this->assertStringContainsString('/products/_search', $engine->captured['url']);
        $this->assertSame(15, $engine->captured['payload']['size']);
        $this->assertSame(15, $engine->captured['payload']['from']);
        $this->assertSame('travel bag', $engine->captured['payload']['query']['bool']['must'][0]['multi_match']['query']);

        $filters = $engine->captured['payload']['query']['bool']['filter'];
        $this->assertContains(['term' => ['document_type' => 'product']], $filters);
        $this->assertContains(['term' => ['status' => 1]], $filters);
        $this->assertContains(['terms' => ['category_ids' => [5]]], $filters);
        $this->assertContains(['range' => ['price' => ['gte' => 10.0, 'lte' => 99.0]]], $filters);

        $this->assertSame(42, $result['total']);
        $this->assertSame('Travel Bag', $result['items'][0]['name']);
        $this->assertSame('p-101', $result['items'][0]['_id']);
    }

    public function testBrowseProductsBuildsNestedEavFiltersAndFacetAggregations(): void
    {
        $engine = new class() extends ElasticsearchEngine {
            public array $captured = [];

            protected function requestJson(string $method, string $url, array $payload = [], array $headers = []): array
            {
                $this->captured = compact('method', 'url', 'payload', 'headers');

                return [
                    'status' => 200,
                    'body' => [
                        'hits' => [
                            'total' => ['value' => 0],
                            'hits' => [],
                        ],
                        'aggregations' => [],
                    ],
                ];
            }
        };
        $engine->initConfig([
            'host' => 'http://127.0.0.1',
            'port' => 9200,
            'index' => 'products',
        ]);

        $engine->browseProducts([
            'keyword' => 'bag',
            'filters' => [
                'attr_color' => ['red'],
                'price' => ['0-100'],
            ],
            'page' => 1,
            'page_size' => 24,
            'category_ids' => [8],
            'include_facets' => true,
            'facet_definitions' => [
                'attr_color' => [
                    'code' => 'attr_color',
                    'type' => 'eav',
                    'attribute_code' => 'color',
                    'name' => 'Color',
                    'display_type' => 'swatch',
                    'bucket_size' => 10,
                ],
                'price' => [
                    'code' => 'price',
                    'type' => 'price',
                    'field' => 'price',
                    'range_buckets' => [
                        ['key' => '0-100', 'from' => 0, 'to' => 100],
                    ],
                ],
            ],
        ]);

        $rootFilters = $engine->captured['payload']['query']['bool']['filter'];
        $this->assertContains(['terms' => ['category_ids' => [8]]], $rootFilters);
        $this->assertContains(
            [
                'nested' => [
                    'path' => 'eav_facets',
                    'query' => [
                        'bool' => [
                            'filter' => [
                                ['term' => ['eav_facets.attribute_code' => 'color']],
                                ['terms' => ['eav_facets.value_keyword' => ['red']]],
                            ],
                        ],
                    ],
                ],
            ],
            $rootFilters
        );

        $aggs = $engine->captured['payload']['aggs'];
        $colorFacet = $aggs['facet__attr_color'];
        $priceFacet = $aggs['facet__price'];

        $this->assertFalse(
            $this->hasFacetAttributeFilter($colorFacet['filter']['bool']['filter'], 'color'),
            'Facet self-filter should be excluded from its own aggregation context.'
        );
        $this->assertContains(
            [
                'bool' => [
                    'should' => [
                        ['range' => ['price' => ['gte' => 0.0, 'lte' => 100.0]]],
                    ],
                    'minimum_should_match' => 1,
                ],
            ],
            $colorFacet['filter']['bool']['filter']
        );
        $this->assertSame('color', $colorFacet['aggs']['facet_nested']['aggs']['facet_attribute']['filter']['term']['eav_facets.attribute_code']);
        $this->assertEquals(
            [['key' => '0-100', 'from' => 0.0, 'to' => 100.0]],
            $priceFacet['aggs']['facet_buckets']['range']['ranges']
        );
    }

    public function testGetSuggestionsReturnsStructuredUniqueProductNamesAndSkus(): void
    {
        $engine = new class() extends ElasticsearchEngine {
            protected function requestJson(string $method, string $url, array $payload = [], array $headers = []): array
            {
                return [
                    'status' => 200,
                    'body' => [
                        'hits' => [
                            'hits' => [
                                ['_source' => ['name' => 'Travel Bag', 'sku' => 'TB-001']],
                                ['_source' => ['name' => 'Travel Bag', 'sku' => 'TB-002']],
                            ],
                        ],
                    ],
                ];
            }
        };
        $engine->initConfig([
            'host' => 'http://127.0.0.1',
            'port' => 9200,
            'index' => 'products',
        ]);

        $suggestions = $engine->getSuggestions('travel', 3);

        $this->assertSame('Travel Bag', $suggestions[0]['text']);
        $this->assertSame('/search?q=Travel+Bag', $suggestions[0]['url']);
        $this->assertSame('TB-001', $suggestions[1]['text']);
        $this->assertSame('TB-002', $suggestions[2]['text']);
    }

    public function testTestConnectionUsesConfiguredEndpoint(): void
    {
        $engine = new class() extends ElasticsearchEngine {
            public array $captured = [];

            protected function requestJson(string $method, string $url, array $payload = [], array $headers = []): array
            {
                $this->captured = compact('method', 'url');

                return [
                    'status' => 200,
                    'body' => ['name' => 'local-es'],
                ];
            }
        };
        $engine->initConfig([
            'host' => 'http://search.internal',
            'port' => 9201,
            'index' => 'products',
        ]);

        $this->assertTrue($engine->testConnection());
        $this->assertSame('GET', $engine->captured['method']);
        $this->assertSame('http://search.internal:9201', $engine->captured['url']);
    }

    /**
     * @param array<int, array<string, mixed>> $filters
     */
    private function hasFacetAttributeFilter(array $filters, string $attributeCode): bool
    {
        foreach ($filters as $filter) {
            $nestedCode = $filter['nested']['query']['bool']['filter'][0]['term']['eav_facets.attribute_code'] ?? null;
            if ($nestedCode === $attributeCode) {
                return true;
            }
        }

        return false;
    }
}
