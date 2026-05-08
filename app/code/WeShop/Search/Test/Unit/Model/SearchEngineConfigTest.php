<?php

declare(strict_types=1);

namespace WeShop\Search\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\AbstractModel;
use WeShop\Search\Model\SearchEngineConfig;

class SearchEngineConfigTest extends TestCase
{
    public function testSaveConfigSetsTimestampsForNewConfig(): void
    {
        $config = new class() extends SearchEngineConfig {
            public array $savedRows = [];

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

            public function fetch(string $model_class = ''): mixed
            {
                return new SearchEngineConfig();
            }

            public function save(string|array|bool|AbstractModel $data = [], string|array $sequence = ''): bool|int
            {
                $this->savedRows[] = $this->getData();

                return true;
            }
        };

        $this->assertTrue($config->saveConfig(
            SearchEngineConfig::ENGINE_MYSQL,
            'default',
            [],
            true,
            100
        ));

        $row = $config->savedRows[0] ?? [];
        $this->assertSame(SearchEngineConfig::ENGINE_MYSQL, $row[SearchEngineConfig::schema_fields_ENGINE_TYPE] ?? null);
        $this->assertSame('default', $row[SearchEngineConfig::schema_fields_SCOPE] ?? null);
        $this->assertSame('[]', $row[SearchEngineConfig::schema_fields_CONFIG_DATA] ?? null);
        $this->assertSame(1, $row[SearchEngineConfig::schema_fields_IS_ACTIVE] ?? null);
        $this->assertSame(100, $row[SearchEngineConfig::schema_fields_PRIORITY] ?? null);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ($row[SearchEngineConfig::schema_fields_CREATED_AT] ?? ''));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ($row[SearchEngineConfig::schema_fields_UPDATED_AT] ?? ''));
    }

    public function testSaveConfigUpdatesExistingConfigTimestampWithoutNewInsert(): void
    {
        $existing = new class() extends SearchEngineConfig {
            public array $savedRows = [];

            public function save(string|array|bool|AbstractModel $data = [], string|array $sequence = ''): bool|int
            {
                $this->savedRows[] = $this->getData();

                return true;
            }
        };
        $existing
            ->setData(SearchEngineConfig::schema_fields_ID, 7)
            ->setData(SearchEngineConfig::schema_fields_CREATED_AT, '2026-01-01 00:00:00');

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

            public function fetch(string $model_class = ''): mixed
            {
                return $this->existing;
            }

            public function save(string|array|bool|AbstractModel $data = [], string|array $sequence = ''): bool|int
            {
                self::fail('Existing config path should save the fetched record, not insert a new row.');
            }
        };

        $this->assertTrue($config->saveConfig(
            SearchEngineConfig::ENGINE_OPENSEARCH,
            'default',
            ['host' => 'http://127.0.0.1'],
            false,
            50
        ));

        $row = $existing->savedRows[0] ?? [];
        $this->assertSame(7, $row[SearchEngineConfig::schema_fields_ID] ?? null);
        $this->assertSame('2026-01-01 00:00:00', $row[SearchEngineConfig::schema_fields_CREATED_AT] ?? null);
        $this->assertSame('{"host":"http:\/\/127.0.0.1"}', $row[SearchEngineConfig::schema_fields_CONFIG_DATA] ?? null);
        $this->assertSame(0, $row[SearchEngineConfig::schema_fields_IS_ACTIVE] ?? null);
        $this->assertSame(50, $row[SearchEngineConfig::schema_fields_PRIORITY] ?? null);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ($row[SearchEngineConfig::schema_fields_UPDATED_AT] ?? ''));
    }
}
