<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Eav\Api\Attribute\AttributeRecord;
use Weline\Eav\Api\Attribute\ScopedAttributeBatchReaderInterface;
use Weline\Eav\Api\Entity\EntityDefinitionInterface;
use Weline\Eav\Model\EavEntity;
use Weline\Eav\Service\EavScopeResolver;
use Weline\Eav\Service\EntityAttributeStore;
use Weline\Eav\Service\EntityAttributeValueTable;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Context;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;

final class EntityAttributeBatchReadTest extends TestCase
{
    private mixed $originalContext;
    private array $originalInstances;
    private mixed $originalManager;

    protected function setUp(): void
    {
        $this->originalContext = Context::getCurrent();
        $this->originalInstances = ObjectManager::getInstances();
        $manager = new \ReflectionProperty(ObjectManager::class, 'instance');
        $this->originalManager = $manager->getValue();
        $manager->setValue(null, (new \ReflectionClass(ObjectManager::class))->newInstanceWithoutConstructor());
        Context::enter(new Context());
        RequestContext::setId('eav-batch-fixture');
        ObjectManager::setInstance(StorefrontScopeHotCache::class, new StorefrontScopeHotCache());
    }

    protected function tearDown(): void
    {
        Context::leave();
        (new \ReflectionProperty(ObjectManager::class, 'instances'))->setValue(null, $this->originalInstances);
        (new \ReflectionProperty(ObjectManager::class, 'instance'))->setValue(null, $this->originalManager);
        if ($this->originalContext !== null) {
            Context::enter($this->originalContext);
        }
    }

    public function testBatchMatchesSingleReadsForAllScopeAndLocaleFallbacks(): void
    {
        [$store, $entity, $attributes, $db] = $this->fixture();
        $scopes = [ScopeIdentity::global(), ScopeIdentity::website(7, 'site'),
            ScopeIdentity::store(7, 'site', 'shop', ScopeIdentity::MODE_NORMAL),
            ScopeIdentity::channel(7, 'site', 'shop', 'web', ScopeIdentity::MODE_NORMAL)];
        foreach ($scopes as $scope) {
            foreach (['en_US', 'zh_Hans_CN', ''] as $locale) {
                $expected = [];
                foreach ([1, 2, 3] as $id) {
                    foreach ($attributes as $attribute) {
                        $expected[$id][$attribute->code] = $store->readScopedValue($entity, $id, $attribute, $scope, $locale);
                    }
                }
                $before = $db->valueReads;
                $actual = $this->readMany($store, $entity, [1, 2, 3, 1], $attributes, $scope, $locale);
                self::assertEquals($expected, $actual, 'Bulk results must preserve explicit, cleared, empty, multi-value and missing cells.');
                self::assertSame(2, $db->valueReads - $before, 'Two value types require two SELECTs, independent of owner count.');
                if ($scope->scopeKind === ScopeIdentity::KIND_GLOBAL) {
                    self::assertSame(['one', 'two'], $actual[1]['tags']->value);
                    self::assertTrue($actual[3]['name']->isExplicit());
                    self::assertSame('', $actual[3]['name']->value);
                    self::assertSame('inherit', $actual[3]['summary']->source);
                }
                if ($scope->scopeKind === ScopeIdentity::KIND_WEBSITE && $locale === 'en_US') {
                    self::assertTrue($actual[2]['name']->isCleared());
                    self::assertNull($actual[2]['name']->value);
                }
                if ($scope->scopeKind === ScopeIdentity::KIND_CHANNEL && $locale === 'en_US') {
                    self::assertSame('Channel English', $actual[1]['name']->value);
                    self::assertTrue($actual[1]['tags']->isCleared());
                }
            }
        }
    }

    private function readMany($store, $entity, array $ids, array $attributes, $scope, string $locale): array
    {
        if ($store instanceof ScopedAttributeBatchReaderInterface) {
            return $store->readScopedValues($entity, $ids, $attributes, $scope, $locale);
        }
        $out = [];
        foreach (array_unique($ids) as $id) {
            foreach ($attributes as $attribute) {
                $out[$id][$attribute->code] = $store->readScopedValue($entity, $id, $attribute, $scope, $locale);
            }
        }
        return $out;
    }

    private function fixture(): array
    {
        $db = (object)['pdo' => new \PDO('sqlite::memory:'), 'valueReads' => 0, 'table' => '', 'conditions' => [], 'parameters' => []];
        $db->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
        $db->pdo->exec('CREATE TABLE information_schema.columns (table_name TEXT, column_name TEXT)');
        foreach (['string', 'text'] as $type) {
            $table = 'eav_fixture_' . $type;
            $db->pdo->exec('CREATE TABLE ' . $table . ' (entity_id INTEGER, attribute_id INTEGER, value TEXT, scope_kind TEXT, website_code TEXT, store_code TEXT, channel_code TEXT, locale TEXT, is_cleared INTEGER)');
            $db->pdo->prepare('INSERT INTO information_schema.columns VALUES (?, ?)')->execute([$table, 'scope_kind']);
        }
        $insert = $db->pdo->prepare('INSERT INTO eav_fixture_string VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ([
            [1, 11, 'Global', 'global', null, null, null, '', 0],
            [1, 11, 'Website English', 'website', 'site', null, null, 'en_US', 0],
            [1, 11, '店铺默认', 'store', 'site', 'shop', null, '', 0],
            [1, 11, 'Channel English', 'channel', 'site', 'shop', 'web', 'en_US', 0],
            [2, 11, 'Global second', 'global', null, null, null, '', 0],
            [2, 11, '', 'website', 'site', null, null, 'en_US', 1],
            [2, 11, 'Other website', 'website', 'other', null, null, '', 0],
            [3, 11, '', 'global', null, null, null, '', 0],
            [1, 12, 'one', 'global', null, null, null, '', 0],
            [1, 12, 'two', 'global', null, null, null, '', 0],
            [1, 12, '', 'store', 'site', 'shop', null, 'en_US', 1],
            [2, 11, 'legacy must be ignored', null, null, null, null, '', 0],
        ] as $row) {
            $insert->execute($row);
        }
        $db->pdo->exec("INSERT INTO eav_fixture_text VALUES (1, 13, 'Summary', 'global', NULL, NULL, NULL, '', 0)");
        $query = $this->createMock(QueryInterface::class);
        $query->_index_sort_keys = [];
        $query->method('identity')->willReturnSelf();
        $query->method('table')->willReturnCallback(static function (string $table) use ($db, $query) { $db->table = $table; return $query; });
        $query->method('reset')->willReturnCallback(static function () use ($db, $query) { $db->conditions = []; $db->parameters = []; return $query; });
        $query->method('where')->willReturnCallback(static function ($field, $value = null, $condition = '=') use ($db, $query) {
            if ($value === null) {
                $db->conditions[] = $field . ($condition === '!=' ? ' IS NOT NULL' : ' IS NULL');
            } elseif ($condition === 'IN') {
                $db->conditions[] = $field . ' IN (' . implode(',', array_fill(0, count($value), '?')) . ')';
                array_push($db->parameters, ...$value);
            } else {
                $db->conditions[] = $field . ' ' . $condition . ' ?';
                $db->parameters[] = $value;
            }
            return $query;
        });
        $query->method('select')->willReturnSelf();
        $query->method('fetchArray')->willReturnCallback(static function () use ($db): array {
            $db->valueReads++;
            $statement = $db->pdo->prepare('SELECT * FROM ' . $db->table . ' WHERE ' . implode(' AND ', $db->conditions));
            $statement->execute($db->parameters);
            return $statement->fetchAll(\PDO::FETCH_ASSOC);
        });
        $connector = $this->createMock(BatchReadConnectorFixtureInterface::class);
        $connector->method('quote')->willReturnCallback(static fn(string $v) => $db->pdo->quote($v));
        $connector->method('formatTableName')->willReturnArgument(0);
        $connector->method('getConfigProvider')->willReturn(new ConfigProvider(['type' => 'sqlite', 'database' => 'batch-fixture']));
        $connector->method('query')->willReturnCallback(function (string $sql) use ($db) {
            $statement = $db->pdo->query($sql);
            $result = $this->createMock(QueryInterface::class);
            $result->method('fetch')->willReturnCallback(static fn() => $statement->fetch(\PDO::FETCH_ASSOC));
            return $result;
        });
        $connection = $this->createMock(ConnectionFactory::class);
        $connection->method('getConnector')->willReturn($connector);
        $table = (new \ReflectionClass(EntityAttributeValueTable::class))->newInstanceWithoutConstructor();
        $table->setConnection($connection)->bindQuery($query);
        $entityModel = $this->getMockBuilder(EavEntity::class)->disableOriginalConstructor()->disableOriginalClone()
            ->onlyMethods(['__clone', 'clearData', 'load', 'getId'])->getMock();
        $entityModel->method('clearData')->willReturnSelf();
        $entityModel->method('load')->willReturnSelf();
        $entityModel->method('getId')->willReturn(6);
        $store = (new \ReflectionClass(EntityAttributeStore::class))->newInstanceWithoutConstructor();
        foreach (['entityModel' => $entityModel, 'valueTableModel' => $table, 'scopeResolver' => new EavScopeResolver()] as $property => $value) {
            (new \ReflectionProperty($store, $property))->setValue($store, $value);
        }
        $entity = $this->createStub(EntityDefinitionInterface::class);
        $entity->method('getEntityCode')->willReturn('fixture');
        return [$store, $entity, [
            new AttributeRecord(11, 6, 'name', 'Name', 1, 'string', 1, 1, false, false),
            new AttributeRecord(12, 6, 'tags', 'Tags', 1, 'string', 1, 1, true, false),
            new AttributeRecord(13, 6, 'summary', 'Summary', 2, 'text', 1, 1, false, false),
        ], $db];
    }
}

interface BatchReadConnectorFixtureInterface extends ConnectorInterface
{
    public function quote(string $value): string;
    public function formatTableName(string $table): string;
}
