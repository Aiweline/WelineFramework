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
        $this->assertSame(5, $engine->captured['payload']['query']['bool']['filter'][1]['term']['category_ids']);
        $this->assertSame(10.0, $engine->captured['payload']['query']['bool']['filter'][2]['range']['price']['gte']);
        $this->assertSame(99.0, $engine->captured['payload']['query']['bool']['filter'][2]['range']['price']['lte']);
        $this->assertSame(42, $result['total']);
        $this->assertSame('Travel Bag', $result['items'][0]['name']);
        $this->assertSame('p-101', $result['items'][0]['_id']);
    }

    public function testGetSuggestionsReturnsUniqueProductNamesAndSkus(): void
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

        $this->assertSame(['Travel Bag', 'TB-001', 'TB-002'], $suggestions);
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
}
