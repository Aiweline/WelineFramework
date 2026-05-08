<?php

declare(strict_types=1);

namespace WeShop\Search\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use WeShop\Search\Model\SearchEngineConfig;

class SearchEngineConfigTest extends TestCase
{
    public function testSaveConfigSetsRequiredTimestampsOnInsert(): void
    {
        $config = new class() extends SearchEngineConfig {
            public array $savedData = [];

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

            public function find(string $find_fields = ''): static
            {
                return $this;
            }

            public function fetch(string $model_class = ''): static
            {
                return $this;
            }

            public function getId(mixed $default = 0)
            {
                return 0;
            }

            public function save(string|array|bool|\Weline\Framework\Database\AbstractModel $data = [], string|array $sequence = ''): bool|int
            {
                $this->savedData = $this->getData();

                return true;
            }
        };

        $config->saveConfig(SearchEngineConfig::ENGINE_MYSQL, 'default', ['host' => 'localhost'], true, 100);

        $this->assertSame(SearchEngineConfig::ENGINE_MYSQL, $config->savedData[SearchEngineConfig::schema_fields_ENGINE_TYPE]);
        $this->assertSame('default', $config->savedData[SearchEngineConfig::schema_fields_SCOPE]);
        $this->assertArrayHasKey(SearchEngineConfig::schema_fields_CREATED_AT, $config->savedData);
        $this->assertArrayHasKey(SearchEngineConfig::schema_fields_UPDATED_AT, $config->savedData);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $config->savedData[SearchEngineConfig::schema_fields_CREATED_AT]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $config->savedData[SearchEngineConfig::schema_fields_UPDATED_AT]);
    }

    public function testSaveConfigSetsUpdatedAtOnExistingConfig(): void
    {
        $existing = new class() extends SearchEngineConfig {
            public array $savedData = [];

            public function getId(mixed $default = 0)
            {
                return 12;
            }

            public function save(string|array|bool|\Weline\Framework\Database\AbstractModel $data = [], string|array $sequence = ''): bool|int
            {
                $this->savedData = $this->getData();

                return true;
            }
        };

        $config = new class($existing) extends SearchEngineConfig {
            public function __construct(private readonly SearchEngineConfig $existing)
            {
            }

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

            public function find(string $find_fields = ''): static
            {
                return $this;
            }

            public function fetch(string $model_class = ''): SearchEngineConfig
            {
                return $this->existing;
            }
        };

        $config->saveConfig(SearchEngineConfig::ENGINE_OPENSEARCH, 'default', ['hosts' => ['127.0.0.1']], false, 50);

        $this->assertSame(0, $existing->savedData[SearchEngineConfig::schema_fields_IS_ACTIVE]);
        $this->assertSame(50, $existing->savedData[SearchEngineConfig::schema_fields_PRIORITY]);
        $this->assertArrayHasKey(SearchEngineConfig::schema_fields_UPDATED_AT, $existing->savedData);
        $this->assertArrayNotHasKey(SearchEngineConfig::schema_fields_CREATED_AT, $existing->savedData);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $existing->savedData[SearchEngineConfig::schema_fields_UPDATED_AT]);
    }
}
