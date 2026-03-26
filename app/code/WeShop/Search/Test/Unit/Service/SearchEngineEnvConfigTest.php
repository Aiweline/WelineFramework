<?php

declare(strict_types=1);

namespace WeShop\Search\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Search\Service\SearchEngineEnvConfig;

class SearchEngineEnvConfigTest extends TestCase
{
    public function testFallsBackToOpenSearchWhenConfiguredDefaultEngineIsInvalid(): void
    {
        $config = new class([
            'default_scope' => 'default',
            'default_engine' => 'invalid-engine',
            'engines' => [
                'opensearch' => [
                    'host' => 'http://127.0.0.1',
                    'port' => 9200,
                    'index' => 'products',
                ],
            ],
        ], []) extends SearchEngineEnvConfig {
            public function __construct(
                private readonly array $moduleDefaults,
                private readonly array $runtimeOverrides
            ) {
            }

            protected function loadModuleDefaults(): array
            {
                return $this->moduleDefaults;
            }

            protected function loadRuntimeOverrides(): array
            {
                return $this->runtimeOverrides;
            }
        };

        $this->assertSame('opensearch', $config->getDefaultEngineType());
    }

    public function testRuntimeOverridesMergeIntoModuleDefaults(): void
    {
        $config = new class([
            'default_scope' => 'default',
            'default_engine' => 'opensearch',
            'engines' => [
                'opensearch' => [
                    'host' => 'http://127.0.0.1',
                    'port' => 9200,
                    'index' => 'products',
                    'timeout' => 5,
                    'version' => '3.5.0',
                    'install_dir' => 'extend/server/opensearch',
                    'config_file' => 'extend/server/opensearch/config/opensearch.yml',
                    'data_dir' => 'extend/server/opensearch/data',
                    'log_dir' => 'extend/server/opensearch/logs',
                ],
            ],
        ], [
            'default_scope' => 'production',
            'engines' => [
                'opensearch' => [
                    'port' => 9201,
                    'index' => 'products_live',
                    'data_dir' => 'D:/WelineRuntime/opensearch-data',
                    'log_dir' => 'D:/WelineRuntime/opensearch-logs',
                ],
            ],
        ]) extends SearchEngineEnvConfig {
            public function __construct(
                private readonly array $moduleDefaults,
                private readonly array $runtimeOverrides
            ) {
            }

            protected function loadModuleDefaults(): array
            {
                return $this->moduleDefaults;
            }

            protected function loadRuntimeOverrides(): array
            {
                return $this->runtimeOverrides;
            }
        };

        $engineConfig = $config->getEngineConfig('opensearch');

        $this->assertSame('production', $config->getDefaultScope());
        $this->assertSame('opensearch', $config->getDefaultEngineType());
        $this->assertSame('http://127.0.0.1', $engineConfig['host']);
        $this->assertSame(9201, $engineConfig['port']);
        $this->assertSame('products_live', $engineConfig['index']);
        $this->assertSame(5, $engineConfig['timeout']);
        $this->assertSame('3.5.0', $engineConfig['version']);
        $this->assertSame('extend/server/opensearch', $engineConfig['install_dir']);
        $this->assertSame('extend/server/opensearch/config/opensearch.yml', $engineConfig['config_file']);
        $this->assertSame('D:/WelineRuntime/opensearch-data', $engineConfig['data_dir']);
        $this->assertSame('D:/WelineRuntime/opensearch-logs', $engineConfig['log_dir']);
    }

    public function testNormalizeEngineTypeSupportsOpenSearchCaseInsensitive(): void
    {
        $config = new class([], []) extends SearchEngineEnvConfig {
            public function __construct(
                private readonly array $moduleDefaults,
                private readonly array $runtimeOverrides
            ) {
            }

            protected function loadModuleDefaults(): array
            {
                return $this->moduleDefaults;
            }

            protected function loadRuntimeOverrides(): array
            {
                return $this->runtimeOverrides;
            }
        };

        $this->assertSame('opensearch', $config->normalizeEngineType('OpenSearch'));
        $this->assertSame('', $config->normalizeEngineType('unsupported'));
    }
}
