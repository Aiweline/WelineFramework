<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Type\Value as AttributeValueModel;
use Weline\Eav\Service\AttributeFilterService;
use Weline\Framework\Event\EventsManager;

class AttributeFilterServiceTest extends TestCase
{
    public function testBuildAttributeDataWithValuesSkipsInvalidAttributesWithoutIds(): void
    {
        $service = new AttributeFilterService($this->createMock(EventsManager::class));

        $attribute = $this->createMock(EavAttribute::class);
        $attribute->expects(self::atLeastOnce())->method('getAttributeId')->willReturn(0);
        $attribute->expects(self::never())->method('getId');
        $attribute->expects(self::never())->method('w_getValueModel');

        $result = $this->invokePrivate($service, 'buildAttributeDataWithValues', [[$attribute], [1, 2, 3]]);

        self::assertSame([], $result);
    }

    public function testBuildAttributeMetadataResultSkipsInvalidAttributesWithoutIds(): void
    {
        $service = new AttributeFilterService($this->createMock(EventsManager::class));

        $attribute = $this->createMock(EavAttribute::class);
        $attribute->expects(self::atLeastOnce())->method('getAttributeId')->willReturn(0);
        $attribute->expects(self::never())->method('getId');
        $attribute->expects(self::never())->method('getCode');

        $result = $this->invokePrivate($service, 'buildAttributeMetadataResult', [[$attribute]]);

        self::assertSame([], $result);
    }

    public function testMapAttributePrefersRealAttributeIdFieldOverCompositePrimaryId(): void
    {
        $service = new AttributeFilterService($this->createMock(EventsManager::class));

        $attribute = $this->createMock(EavAttribute::class);
        $attribute->expects(self::once())
            ->method('getAttributeId')
            ->willReturn(8);
        $attribute->expects(self::never())->method('getId');
        $attribute->method('getTypeModel')->willThrowException(new \RuntimeException('type not needed'));
        $attribute->method('getCode')->willReturn('brand');
        $attribute->method('getName')->willReturn('Brand');
        $attribute->method('getTypeId')->willReturn(5);
        $attribute->method('getSetId')->willReturn(1);
        $attribute->method('getGroupId')->willReturn(2);
        $attribute->method('isVisibleOnFront')->willReturn(true);
        $attribute->method('isFilterable')->willReturn(true);
        $attribute->method('isSearchable')->willReturn(true);
        $attribute->method('hasOption')->willReturn(true);
        $attribute->method('getMultipleValued')->willReturn(false);

        $result = $this->invokePrivate($service, 'mapAttribute', [$attribute]);

        self::assertSame(8, $result['attribute_id']);
        self::assertSame('brand', $result['code']);
    }

    public function testGetAttributeValuesWithCountsUsesAttributeIdFieldBeforeCompositePrimaryId(): void
    {
        $service = new AttributeFilterService($this->createMock(EventsManager::class));

        $valueModel = new class extends AttributeValueModel {
            public array $whereCalls = [];

            public function reset(): static
            {
                return $this;
            }

            public function fields(string|array $fields): static
            {
                return $this;
            }

            public function where(array|string $field, mixed $value = null, string $condition = '=', string $where_logic = 'AND', string $array_where_logic_type = 'AND'): static
            {
                $this->whereCalls[] = [$field, $value, $condition, $where_logic, $array_where_logic_type];

                return $this;
            }

            public function group(string $fields): static
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
                    ['value' => '42', 'count' => 2],
                ];
            }
        };

        $attribute = $this->createMock(EavAttribute::class);
        $attribute->expects(self::once())
            ->method('getAttributeId')
            ->willReturn(8);
        $attribute->expects(self::never())->method('getId');
        $attribute->expects(self::once())->method('w_getValueModel')->willReturn($valueModel);

        $result = $this->invokePrivate($service, 'getAttributeValuesWithCounts', [$attribute, [2, 3]]);

        self::assertSame(['attribute_id', 8, '=', 'AND', 'AND'], $valueModel->whereCalls[0]);
        self::assertSame(['42'], $result['values']);
        self::assertSame(['42' => 2], $result['counts']);
    }

    public function testValueGetTableFallsBackToEavEntityCodeWithoutCurrentEntity(): void
    {
        $path = dirname(__DIR__, 3) . '/Model/EavAttribute/Type/Value.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('function getTable(string $table = \'\'): string', $source);
        self::assertStringContainsString('getEavEntity()->getCode()', $source);
        self::assertStringContainsString('current_getEntity()->getEntityCode()', $source);
        self::assertLessThan(
            strpos($source, 'getEavEntity()->getCode()') ?: PHP_INT_MAX,
            strpos($source, 'function getTable(string $table = \'\'): string') ?: PHP_INT_MAX,
        );
    }

    public function testBuildAttributeDataWithValuesBatchesByPhysicalTableAndPreservesCounts(): void
    {
        $service = new AttributeFilterService($this->createMock(EventsManager::class));
        $queries = (object)['sql' => []];
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE typed_values (attribute_id INTEGER, entity_id INTEGER, value TEXT)');
        $pdo->exec('CREATE TABLE other_values (attribute_id INTEGER, entity_id INTEGER, value TEXT)');
        $pdo->exec("INSERT INTO typed_values VALUES (8,1,'0'),(8,1,'0'),(8,2,'red'),(8,3,'red'),(8,1,''),(8,2,NULL),(8,99,'outside'),(9,1,'M'),(9,1,'M'),(9,2,'M'),(10,1,''),(10,2,NULL)");
        $pdo->exec("INSERT INTO other_values VALUES (8,1,'wool')");
        $attributes = [
            $this->batchAttribute(8, 'color', $this->batchValueModel($pdo, $queries, 'typed_values')),
            $this->batchAttribute(9, 'size', $this->batchValueModel($pdo, $queries, 'typed_values')),
            $this->batchAttribute(10, 'empty', $this->batchValueModel($pdo, $queries, 'typed_values')),
            $this->batchAttribute(8, 'material', $this->batchValueModel($pdo, $queries, 'other_values')),
        ];

        $result = $this->invokePrivate($service, 'buildAttributeDataWithValues', [$attributes, [1, '2', 3, 1]]);

        self::assertSame([
            'color' => $this->expectedBatchAttribute(8, 'color', ['0', 'red'], ['0' => 1, 'red' => 2]),
            'size' => $this->expectedBatchAttribute(9, 'size', ['M'], ['M' => 2]),
            'material' => $this->expectedBatchAttribute(8, 'material', ['wool'], ['wool' => 1]),
        ], $result);
        self::assertCount(2, $queries->sql, 'Count all attributes in each physical table with one query, including attributes with no values.');
    }

    public function testBuildAttributeDataWithValuesKeepsIdenticalTablesOnDifferentConnectionsSeparate(): void
    {
        $service = new AttributeFilterService($this->createMock(EventsManager::class));
        $queries = (object)['sql' => []];
        $attributes = [];
        foreach (['first' => 'red', 'second' => 'blue'] as $database => $value) {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE typed_values (attribute_id INTEGER, entity_id INTEGER, value TEXT)');
            $pdo->exec("INSERT INTO typed_values VALUES (8,1,'" . $value . "')");
            $attributes[] = $this->batchAttribute(8, $database, $this->batchValueModel($pdo, $queries, 'typed_values', $database));
        }

        $result = $this->invokePrivate($service, 'buildAttributeDataWithValues', [$attributes, [1]]);

        self::assertSame([
            'first' => $this->expectedBatchAttribute(8, 'first', ['red'], ['red' => 1]),
            'second' => $this->expectedBatchAttribute(8, 'second', ['blue'], ['blue' => 1]),
        ], $result);
        self::assertCount(2, $queries->sql);
    }

    private function batchAttribute(int $id, string $code, AttributeValueModel $valueModel): EavAttribute
    {
        $attribute = $this->createMock(EavAttribute::class);
        $attribute->method('getAttributeId')->willReturn($id);
        $attribute->expects(self::never())->method('getId');
        $attribute->method('getCode')->willReturn($code);
        $attribute->method('getName')->willReturn(ucfirst($code));
        $attribute->method('getTypeId')->willReturn(5);
        $attribute->method('getSetId')->willReturn(1);
        $attribute->method('getGroupId')->willReturn(2);
        $attribute->method('isVisibleOnFront')->willReturn(true);
        $attribute->method('isFilterable')->willReturn(true);
        $attribute->method('isSearchable')->willReturn(false);
        $attribute->method('hasOption')->willReturn(false);
        $attribute->method('getMultipleValued')->willReturn(false);
        $attribute->method('getTypeModel')->willThrowException(new \RuntimeException('No type decoration in this count fixture'));
        $attribute->method('w_getValueModel')->willReturn($valueModel);
        return $attribute;
    }

    private function expectedBatchAttribute(int $id, string $code, array $values, array $counts): array
    {
        return [
            'attribute' => [
                'attribute_id' => $id, 'code' => $code, 'name' => ucfirst($code),
                'type_id' => 5, 'type_code' => '', 'type_element' => '', 'set_id' => 1, 'group_id' => 2,
                'frontend_is_visible' => true, 'frontend_is_filterable' => true, 'frontend_is_searchable' => false,
                'data_has_option' => false, 'data_is_multiple' => false, 'has_option' => false, 'is_multiple' => false,
                'is_swatch' => false, 'swatch_color' => false, 'swatch_image' => false, 'swatch_text' => false,
            ],
            'options' => [], 'values' => $values, 'counts' => $counts,
        ];
    }

    private function batchValueModel(\PDO $pdo, object $queries, string $table, string $database = 'first'): AttributeValueModel
    {
        $config = new \Weline\Framework\Database\DbManager\ConfigProvider([
            'type' => 'sqlite', 'hostname' => 'fixture-host', 'hostport' => 0,
            'database' => $database, 'path' => ':memory:', 'username' => 'fixture-user',
        ]);
        $connector = $this->createMock(\Weline\Framework\Database\Connection\Api\ConnectorInterface::class);
        $connector->method('getConfigProvider')->willReturn($config);
        $connection = $this->createMock(\Weline\Framework\Database\ConnectionFactory::class);
        $connection->method('getConnector')->willReturn($connector);

        return new class($pdo, $queries, $table, $connection) extends AttributeValueModel {
            private array $selectedFields = [];
            private array $conditions = [];
            private array $groups = [];
            private ?\PDOStatement $statement = null;

            public function __construct(private \PDO $pdo, private object $queries, private string $physicalTable, private object $fixtureConnection) {}
            public function getTable(string $table = ''): string { return $this->physicalTable; }
            public function getConnection() { return $this->fixtureConnection; }
            public function reset(): static
            {
                $this->selectedFields = $this->conditions = $this->groups = [];
                $this->statement = null;
                return $this;
            }
            public function fields(string|array $fields): static
            {
                $this->selectedFields = is_array($fields) ? $fields : [$fields];
                return $this;
            }
            public function where(array|string $field, mixed $value = null, string $condition = '=', string $where_logic = 'AND', string $array_where_logic_type = 'AND'): static
            {
                if (strtoupper($condition) === 'IS NOT NULL') {
                    $this->conditions[] = $field . ' IS NOT NULL';
                } elseif (strtolower($condition) === 'in') {
                    $this->conditions[] = $field . ' IN (' . implode(',', array_map(fn($item): string => $this->pdo->quote((string)$item), $value)) . ')';
                } else {
                    $this->conditions[] = $field . ' ' . $condition . ' ' . $this->pdo->quote((string)$value);
                }
                return $this;
            }
            public function group(string $fields): static { $this->groups[] = $fields; return $this; }
            public function select(string $fields = ''): static
            {
                $sql = 'SELECT ' . ($fields !== '' ? $fields : implode(',', $this->selectedFields))
                    . ' FROM ' . $this->physicalTable . ' WHERE ' . implode(' AND ', $this->conditions)
                    . ' GROUP BY ' . implode(',', $this->groups);
                $this->queries->sql[] = $sql;
                $this->statement = $this->pdo->query($sql);
                return $this;
            }
            public function fetchArray(): array { return $this->statement->fetchAll(\PDO::FETCH_ASSOC); }
        };
    }

    public function testBuildAttributeMetadataResultBatchesSharedOptionsAndPreservesCacheReset(): void
    {
        $service = new AttributeFilterService($this->createMock(EventsManager::class));
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE options (attribute_id INTEGER, scope_instance_id INTEGER, option_id INTEGER, code TEXT, value TEXT, swatch_image TEXT, swatch_color TEXT, swatch_text TEXT)');
        $pdo->exec("INSERT INTO options VALUES (8,0,10,'zero','0','zero.png','#000',NULL),(8,0,30,'blue','Blue',NULL,'#00f','blue'),(9,0,20,'medium','M',NULL,NULL,NULL),(8,7,90,'private','Private',NULL,NULL,NULL),(11,0,50,'unrequested','Other',NULL,NULL,NULL)");
        $queries = (object)['sql' => []];
        $optionModel = new class($pdo, $queries) extends \Weline\Eav\Model\EavAttribute\Option {
            private string $selectedFields = '*';
            private array $conditions = [];
            private array $ordering = [];
            private ?\PDOStatement $statement = null;

            public function __construct(private \PDO $pdo, private object $queries) {}
            public function clearData(bool $with_query = false): static { return $this; }
            public function reset(): static
            {
                $this->selectedFields = '*';
                $this->conditions = $this->ordering = [];
                $this->statement = null;
                return $this;
            }
            public function fields(string|array $fields): static
            {
                $this->selectedFields = is_array($fields) ? implode(',', $fields) : $fields;
                return $this;
            }
            public function where(array|string $field, mixed $value = null, string $condition = '=', string $where_logic = 'AND', string $array_where_logic_type = 'AND'): static
            {
                if (strtolower($condition) === 'in') {
                    $this->conditions[] = $field . ' IN (' . implode(',', array_map(fn($item): string => $this->pdo->quote((string)$item), $value)) . ')';
                } else {
                    $this->conditions[] = $field . ' ' . $condition . ' ' . $this->pdo->quote((string)$value);
                }
                return $this;
            }
            public function order(string $field, string $direction = 'DESC'): static
            {
                $this->ordering[] = $field . ' ' . $direction;
                return $this;
            }
            public function select(string $fields = ''): static
            {
                $sql = 'SELECT ' . ($fields !== '' ? $fields : $this->selectedFields)
                    . ' FROM options AS main_table WHERE ' . implode(' AND ', $this->conditions)
                    . ($this->ordering !== [] ? ' ORDER BY ' . implode(',', $this->ordering) : '');
                $this->queries->sql[] = $sql;
                $this->statement = $this->pdo->query($sql);
                return $this;
            }
            public function fetchArray(): array { return $this->statement->fetchAll(\PDO::FETCH_ASSOC); }
        };
        $attributes = [];
        foreach ([8 => 'color', 9 => 'size', 10 => 'empty'] as $id => $code) {
            $attribute = $this->createMock(EavAttribute::class);
            $attribute->method('getAttributeId')->willReturn($id);
            $attribute->expects(self::never())->method('getId');
            $attribute->method('getCode')->willReturn($code);
            $attribute->method('hasOption')->willReturn(true);
            $attribute->method('getTypeModel')->willThrowException(new \RuntimeException('No type decoration in this option fixture'));
            $attributes[] = $attribute;
        }
        $expectedOptions = [
            'color' => [
                30 => ['option_id' => 30, 'code' => 'blue', 'value' => 'Blue', 'swatch_image' => null, 'swatch_color' => '#00f', 'swatch_text' => 'blue'],
                10 => ['option_id' => 10, 'code' => 'zero', 'value' => '0', 'swatch_image' => 'zero.png', 'swatch_color' => '#000', 'swatch_text' => null],
            ],
            'size' => [20 => ['option_id' => 20, 'code' => 'medium', 'value' => 'M', 'swatch_image' => null, 'swatch_color' => null, 'swatch_text' => null]],
            'empty' => [],
        ];
        $optionClass = \Weline\Eav\Model\EavAttribute\Option::class;
        $instances = \Weline\Framework\Manager\ObjectManager::getInstances();
        $originalOption = $instances[$optionClass] ?? null;
        \Weline\Framework\Manager\ObjectManager::setInstance($optionClass, $optionModel);
        try {
            $result = $this->invokePrivate($service, 'buildAttributeMetadataResult', [$attributes]);
            self::assertSame($expectedOptions, array_map(static fn(array $row): array => $row['options'], $result));
            self::assertSame(['color', 'size', 'empty'], array_keys($result));
            self::assertCount(1, $queries->sql, 'Fetch all missing shared attribute option buckets in one query.');

            $pdo->exec("UPDATE options SET value = 'Changed' WHERE option_id = 10");
            self::assertSame($result, $this->invokePrivate($service, 'buildAttributeMetadataResult', [$attributes]));
            self::assertCount(1, $queries->sql, 'Reuse populated and empty buckets on the same service instance.');

            $service->clearCache();
            $expectedOptions['color'][10]['value'] = 'Changed';
            $refreshed = $this->invokePrivate($service, 'buildAttributeMetadataResult', [$attributes]);
            self::assertSame($expectedOptions, array_map(static fn(array $row): array => $row['options'], $refreshed));
            self::assertCount(2, $queries->sql, 'The existing clearCache API must refresh all buckets on the next call.');
        } finally {
            if ($originalOption !== null) {
                \Weline\Framework\Manager\ObjectManager::setInstance($optionClass, $originalOption);
            } else {
                \Weline\Framework\Manager\ObjectManager::removeInstance($optionClass);
            }
        }
    }

    public function testBuildAttributeMetadataResultCanSkipUnusedOptions(): void
    {
        $service = new AttributeFilterService($this->createMock(EventsManager::class));
        $attribute = $this->createMock(EavAttribute::class);
        $attribute->method('getAttributeId')->willReturn(8);
        $attribute->method('getCode')->willReturn('color');
        $attribute->method('getName')->willReturn('Color');
        $attribute->method('hasOption')->willReturn(true);
        $attribute->method('getTypeModel')->willThrowException(new \RuntimeException('No type decoration in this fixture'));

        $result = $this->invokePrivate($service, 'buildAttributeMetadataResult', [[$attribute], false]);

        self::assertSame([], $result['color']['options']);
        self::assertSame(8, $result['color']['attribute']['attribute_id']);
    }

    public function testEmptyEntityFilterForwardsIncludeOptionsFlagToMetadataPath(): void
    {
        $events = $this->createMock(EventsManager::class);
        $service = new class($events) extends AttributeFilterService {
            public ?bool $includeOptions = null;

            public function getFilterableAttributeMetadata(
                string $entityCode,
                array $attributeCodes = [],
                ?int $setId = null,
                bool $includeOptions = true,
            ): array {
                $this->includeOptions = $includeOptions;

                return [];
            }
        };

        self::assertSame([], $service->getFilterableAttributes('product', [], [], false));
        self::assertFalse($service->includeOptions);
    }

    private function invokePrivate(object $instance, string $method, array $args): mixed
    {
        $reflection = new \ReflectionMethod($instance, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($instance, $args);
    }
}
