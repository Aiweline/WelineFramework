<?php

declare(strict_types=1);

namespace WeShop\Search\Test\Unit\Engine;

use PHPUnit\Framework\TestCase;
use WeShop\Search\Engine\AlgoliaEngine;

class AlgoliaEngineTest extends TestCase
{
    public function testSearchBuildsAlgoliaRequestAndNormalizesHits(): void
    {
        $engine = new class() extends AlgoliaEngine {
            public array $captured = [];

            protected function requestJson(string $method, string $url, array $payload = [], array $headers = []): array
            {
                $this->captured = compact('method', 'url', 'payload', 'headers');

                return [
                    'status' => 200,
                    'body' => [
                        'hits' => [
                            [
                                'product_id' => 55,
                                'name' => 'Desk Lamp',
                                'sku' => 'DL-055',
                                'price' => 35.5,
                            ],
                        ],
                        'nbHits' => 11,
                    ],
                ];
            }
        };
        $engine->initConfig([
            'application_id' => 'APP123',
            'api_key' => 'key-123',
            'index_name' => 'products',
        ]);

        $result = $engine->search('lamp', [
            'category_id' => 3,
            'price_min' => 20,
            'price_max' => 50,
        ], 1, 12);

        $this->assertSame('POST', $engine->captured['method']);
        $this->assertStringContainsString('APP123-dsn.algolia.net/1/indexes/products/query', $engine->captured['url']);
        $this->assertSame('lamp', $engine->captured['payload']['query']);
        $this->assertSame(0, $engine->captured['payload']['page']);
        $this->assertSame(12, $engine->captured['payload']['hitsPerPage']);
        $this->assertStringContainsString('category_ids:3', (string) $engine->captured['payload']['filters']);
        $this->assertStringContainsString('price >= 20', (string) $engine->captured['payload']['filters']);
        $this->assertStringContainsString('price <= 50', (string) $engine->captured['payload']['filters']);
        $this->assertSame(11, $result['total']);
        $this->assertSame('Desk Lamp', $result['items'][0]['name']);
    }

    public function testGetSuggestionsReturnsUniqueNamesAndSkus(): void
    {
        $engine = new class() extends AlgoliaEngine {
            protected function requestJson(string $method, string $url, array $payload = [], array $headers = []): array
            {
                return [
                    'status' => 200,
                    'body' => [
                        'hits' => [
                            ['name' => 'Desk Lamp', 'sku' => 'DL-055'],
                            ['name' => 'Desk Lamp', 'sku' => 'DL-056'],
                        ],
                    ],
                ];
            }
        };
        $engine->initConfig([
            'application_id' => 'APP123',
            'api_key' => 'key-123',
            'index_name' => 'products',
        ]);

        $suggestions = $engine->getSuggestions('lamp', 3);

        $this->assertSame(['Desk Lamp', 'DL-055', 'DL-056'], $suggestions);
    }

    public function testTestConnectionReturnsFalseWhenCredentialsAreMissing(): void
    {
        $engine = new AlgoliaEngine();
        $engine->initConfig([
            'application_id' => '',
            'api_key' => '',
            'index_name' => 'products',
        ]);

        $this->assertFalse($engine->testConnection());
    }
}
