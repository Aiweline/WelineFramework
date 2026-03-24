<?php

declare(strict_types=1);

namespace WeShop\Search\Test\Unit\Engine;

use PHPUnit\Framework\TestCase;
use WeShop\Search\Engine\OpenSearchEngine;

class OpenSearchEngineTest extends TestCase
{
    public function testReportsOpenSearchIdentity(): void
    {
        $engine = new OpenSearchEngine();

        $this->assertSame('opensearch', $engine->getEngineType());
        $this->assertSame('OpenSearch', $engine->getEngineName());
    }

    public function testConnectionUsesElasticsearchCompatibleEndpoint(): void
    {
        $engine = new class() extends OpenSearchEngine {
            public array $captured = [];

            protected function requestJson(string $method, string $url, array $payload = [], array $headers = []): array
            {
                $this->captured = compact('method', 'url', 'payload', 'headers');

                return [
                    'status' => 200,
                    'body' => ['name' => 'local-opensearch'],
                ];
            }
        };

        $engine->initConfig([
            'host' => 'http://127.0.0.1',
            'port' => 9200,
            'index' => 'products',
        ]);

        $this->assertTrue($engine->testConnection());
        $this->assertSame('GET', $engine->captured['method']);
        $this->assertSame('http://127.0.0.1:9200', $engine->captured['url']);
    }
}
