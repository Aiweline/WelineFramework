<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
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

final class EntityAttributeScopeColumnCacheTest extends TestCase
{
    private mixed $originalContext;
    private array $originalInstances;
    private mixed $originalManager;
    private EntityAttributeStore $store;

    protected function setUp(): void
    {
        $this->originalContext = Context::getCurrent();
        $this->originalInstances = ObjectManager::getInstances();
        $manager = new \ReflectionProperty(ObjectManager::class, 'instance');
        $this->originalManager = $manager->getValue();
        $manager->setValue(null, (new \ReflectionClass(ObjectManager::class))->newInstanceWithoutConstructor());
        $this->store = (new \ReflectionClass(EntityAttributeStore::class))->newInstanceWithoutConstructor();
        $this->newRequest();
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

    public function testRepeatedTrueAndFalseFactsShareAcrossModelsButNotTables(): void
    {
        $db = $this->database(['known']);
        self::assertTrue($this->probe($this->model($db, 'known')));
        self::assertTrue($this->probe($this->model($db, 'known', clone $db->config)));
        self::assertFalse($this->probe($this->model($db, 'legacy')));
        self::assertFalse($this->probe($this->model($db, 'legacy')));
        self::assertCount(2, $db->reads, 'One SQL probe per table fact, including false, across separate model/connector wrappers.');
        self::assertSame([
            "SELECT 1 FROM information_schema.columns WHERE table_name = 'known' AND column_name = 'scope_kind' LIMIT 1",
            "SELECT 1 FROM information_schema.columns WHERE table_name = 'legacy' AND column_name = 'scope_kind' LIMIT 1",
        ], $db->reads);
    }

    public function testConnectionIdentityChangesDoNotReuseAnotherDatabaseFact(): void
    {
        $db = $this->database(['known']);
        self::assertTrue($this->probe($this->model($db, 'known')));
        foreach ([
            ['type' => 'other-driver'],
            ['hostname' => 'other-host'],
            ['hostport' => 5433],
            ['database' => 'other-database'],
            ['path' => 'other-path'],
            ['username' => 'other-user'],
        ] as $configuration) {
            $other = $this->database([], $configuration);
            self::assertFalse($this->probe($this->model($other, 'known')), 'Each connection identity dimension must isolate metadata.');
            self::assertCount(1, $other->reads);
        }

        $model = $this->model($db, 'known');
        $db->pdo->exec('DELETE FROM information_schema.columns');
        $db->config->setDatabase('changed-database');
        self::assertFalse($this->probe($model), 'Read current connector configuration on each call.');
        self::assertCount(2, $db->reads);
    }

    public function testFailuresRetryAndRequestResetRefreshesMetadata(): void
    {
        $db = $this->database(['known']);
        $model = $this->model($db, 'known');
        $db->failures = 1;
        self::assertFalse($this->probe($model), 'Preserve the existing exception-to-false fallback.');
        self::assertTrue($this->probe($model), 'The failed query must not memoize a false result.');
        self::assertTrue($this->probe($model));
        self::assertCount(2, $db->reads);

        $db->pdo->exec('DELETE FROM information_schema.columns');
        $this->newRequest();
        self::assertFalse($this->probe($model));
        self::assertFalse($this->probe($model));
        self::assertCount(3, $db->reads, 'The next request refreshes the schema fact.');

        Context::leave();
        $cache = new StorefrontScopeHotCache();
        ObjectManager::setInstance(StorefrontScopeHotCache::class, $cache);
        self::assertFalse($this->probe($model));
        self::assertFalse($this->probe($model));
        self::assertCount(5, $db->reads, 'CLI without a request context must keep querying.');
    }

    private function newRequest(): void
    {
        if (Context::hasCurrent()) {
            Context::leave();
        }
        Context::enter(new Context());
        RequestContext::setId(uniqid('eav-scope-column-', true));
        $cache = new StorefrontScopeHotCache();
        ObjectManager::setInstance(StorefrontScopeHotCache::class, $cache);
    }

    private function database(array $tables, array $configuration = []): object
    {
        $db = (object)[
            'pdo' => new \PDO('sqlite::memory:'),
            'config' => new ConfigProvider(array_replace([
                'type' => 'pgsql', 'hostname' => 'fixture-host', 'hostport' => 5432,
                'database' => 'fixture-database', 'path' => '', 'username' => 'fixture-user',
            ], $configuration)),
            'reads' => [],
            'failures' => 0,
        ];
        $db->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
        $db->pdo->exec('CREATE TABLE information_schema.columns (table_name TEXT, column_name TEXT)');
        $insert = $db->pdo->prepare('INSERT INTO information_schema.columns VALUES (?, ?)');
        foreach ($tables as $table) {
            $insert->execute([$table, 'scope_kind']);
        }
        return $db;
    }

    private function model(object $db, string $table, ?ConfigProvider $config = null): EntityAttributeValueTable
    {
        $connector = $this->createMock(ScopeColumnConnectorFixtureInterface::class);
        $connector->method('quote')->willReturnCallback(static fn(string $value): string => $db->pdo->quote($value));
        $connector->method('formatTableName')->willReturnCallback(static fn(string $table): string => '"public"."' . $table . '"');
        $connector->method('getConfigProvider')->willReturn($config ?? $db->config);
        $connector->method('query')->willReturnCallback(function (string $sql) use ($db): QueryInterface {
            $db->reads[] = $sql;
            if ($db->failures > 0) {
                $db->failures--;
                throw new \RuntimeException('temporary fixture query failure');
            }
            $statement = $db->pdo->query($sql);
            $result = $this->createMock(QueryInterface::class);
            $result->method('fetch')->willReturnCallback(static fn(): mixed => $statement->fetch(\PDO::FETCH_ASSOC));
            return $result;
        });
        $connection = $this->createMock(ConnectionFactory::class);
        $connection->method('getConnector')->willReturn($connector);
        $model = (new \ReflectionClass(EntityAttributeValueTable::class))->newInstanceWithoutConstructor();
        return $model->setConnection($connection)->useLogicalTable($table);
    }

    private function probe(EntityAttributeValueTable $model): bool
    {
        return (new \ReflectionMethod(EntityAttributeStore::class, 'valueTableHasScopeColumns'))->invoke($this->store, $model);
    }
}

interface ScopeColumnConnectorFixtureInterface extends ConnectorInterface
{
    public function quote(string $value): string;

    public function formatTableName(string $table): string;
}
